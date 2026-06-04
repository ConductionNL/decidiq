<?php
/**
 * Decidesk Board Minutes Signing Controller
 *
 * Endpoints to initiate, verify and finalize eIDAS QES signing of board minutes.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\EidasSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for minutes signing endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class BoardMinutesSigningController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request      The request.
     * @param EidasSignatureService $eidas        The eIDAS signature service.
     * @param IUserSession          $userSession  The user session.
     * @param IGroupManager         $groupManager The group manager.
     * @param IAppConfig            $appConfig    The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     */
    public function __construct(
        IRequest $request,
        private readonly EidasSignatureService $eidas,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary/chair (configured group) or admin.
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
     * Initiate a signing request for a minutes record.
     *
     * POST /api/board/minutes/{id}/initiate-signing
     *
     * @param string $id The minutes UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function initiateSigning(string $id): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $signatories = (array) ($this->request->getParam('signatories', []));

        try {
            $result = $this->eidas->initializeSigningRequest($id, $signatories);
            return new JSONResponse($result, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end initiateSigning()

    /**
     * Finalize signing: store signatures, hash, and transition to signed.
     *
     * POST /api/board/minutes/{id}/finalize
     *
     * @param string $id The minutes UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function finalize(string $id): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $signatures = (array) ($this->request->getParam('signatures', []));
        $uid        = (string) ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $minutes = $this->eidas->finalizeMinutes($id, $signatures, $uid);
            return new JSONResponse($minutes, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end finalize()
}//end class
