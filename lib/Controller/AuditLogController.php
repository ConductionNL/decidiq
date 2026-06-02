<?php
/**
 * Decidesk Audit Log Controller
 *
 * Thin REST controller for querying, exporting and verifying the governance audit log.
 * Scoped to the board secretary/admin operator.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\AuditLogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Governance audit-log REST endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 */
class AuditLogController extends Controller
{
    /**
     * Constructor for AuditLogController.
     *
     * @param IRequest        $request         The request object.
     * @param AuditLogService $auditLogService The audit log service.
     * @param IUserSession    $userSession     The user session.
     * @param IGroupManager   $groupManager    The group manager.
     * @param IAppConfig      $appConfig       The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    public function __construct(
        IRequest $request,
        private readonly AuditLogService $auditLogService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary or system administrator.
     *
     * @return JSONResponse|null A 401/403 response on failure, null on success.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    private function requireBoardSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid            = $user->getUID();
        $secretaryGroup = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $inGroup        = ($secretaryGroup !== '' && $this->groupManager->isInGroup($uid, $secretaryGroup) === true);

        if ($inGroup === false && $this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(['message' => 'Board secretary or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireBoardSecretary()

    /**
     * Query the audit log with optional filters.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $filters = [];
        foreach (['actor', 'action', 'start', 'end', 'object'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return new JSONResponse(['results' => $this->auditLogService->query($filters)]);

    }//end index()

    /**
     * Verify the integrity of the audit-log hash chain up to an entry.
     *
     * @param string $id The audit log entry UUID to verify up to.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    #[NoAdminRequired]
    public function verify(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        return new JSONResponse($this->auditLogService->verify($id));

    }//end verify()
}//end class
