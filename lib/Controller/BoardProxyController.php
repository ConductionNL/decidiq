<?php
/**
 * Decidesk Board Proxy Controller
 *
 * Endpoints to register, suspend and revoke proxy delegations for board meetings.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardProxyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for proxy delegation endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class BoardProxyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request      The request.
     * @param BoardProxyService $proxyService The proxy service.
     * @param IUserSession      $userSession  The user session.
     * @param IGroupManager     $groupManager The group manager.
     * @param IAppConfig        $appConfig    The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function __construct(
        IRequest $request,
        private readonly BoardProxyService $proxyService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary (configured group) or admin.
     *
     * @return JSONResponse|null
     */
    private function requireSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid          = $user->getUID();
        $secretaryGrp = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $authorized   = $this->groupManager->isAdmin($uid);
        if ($secretaryGrp !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $secretaryGrp);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Board secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireSecretary()

    /**
     * Register a new proxy (secretary only).
     *
     * POST /api/board/proxies
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params    = $this->request->getParams();
        $meetingId = (string) ($params['meetingId'] ?? '');
        $grantorId = (string) ($params['grantorId'] ?? '');
        $holderId  = (string) ($params['holderId'] ?? '');
        $scope     = (string) ($params['scope'] ?? 'full');
        $resUids   = (array) ($params['scopedResolutionUids'] ?? []);
        $expiresAt = (string) ($params['expiresAt'] ?? '');
        if ($meetingId === '' || $grantorId === '' || $holderId === '') {
            return new JSONResponse(['message' => 'meetingId, grantorId and holderId are required'], Http::STATUS_BAD_REQUEST);
        }

        $uid = (string) ($this->userSession->getUser()?->getUID() ?? '');
        try {
            $proxy = $this->proxyService->register($meetingId, $grantorId, $holderId, $scope, $resUids, $expiresAt, $uid);
            return new JSONResponse($proxy, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end create()

    /**
     * Suspend a proxy (secretary only).
     *
     * PUT /api/board/proxies/{id}/suspend
     *
     * @param string $id The proxy UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    #[NoAdminRequired]
    public function suspend(string $id): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $uid = (string) ($this->userSession->getUser()?->getUID() ?? '');
        try {
            $proxy = $this->proxyService->setStatus($id, 'suspended', $uid);
            return new JSONResponse($proxy, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end suspend()

    /**
     * Revoke a proxy (secretary only).
     *
     * DELETE /api/board/proxies/{id}
     *
     * @param string $id The proxy UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $uid = (string) ($this->userSession->getUser()?->getUID() ?? '');
        try {
            $proxy = $this->proxyService->setStatus($id, 'revoked', $uid);
            return new JSONResponse($proxy, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end destroy()
}//end class
