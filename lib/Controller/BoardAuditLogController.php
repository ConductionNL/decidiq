<?php
/**
 * Decidesk Board Audit Log Controller
 *
 * Secretary/admin-only endpoints for querying, verifying and exporting the
 * immutable board audit trail.
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
use OCA\Decidesk\Service\BoardAuditLogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for board audit log endpoints (secretary/admin only).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 */
class BoardAuditLogController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request      The request.
     * @param BoardAuditLogService $auditLog     The audit log service.
     * @param IUserSession         $userSession  The user session.
     * @param IGroupManager        $groupManager The group manager.
     * @param IAppConfig           $appConfig    The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    public function __construct(
        IRequest $request,
        private readonly BoardAuditLogService $auditLog,
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
     * Query the audit log with optional filters.
     *
     * GET /api/board/audit-log
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    #[NoAdminRequired]
    public function index(): DataDownloadResponse|JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params  = $this->request->getParams();
        $filters = [];
        foreach (['actor', 'action', 'from', 'to', 'objectUuid'] as $key) {
            if (isset($params[$key]) === true && $params[$key] !== '') {
                $filters[$key] = $params[$key];
            }
        }

        $format = (string) ($params['format'] ?? 'json');
        if ($format === 'csv') {
            $csv = $this->auditLog->export(
                startDate: ($filters['from'] ?? null),
                endDate: ($filters['to'] ?? null),
                format: 'csv'
            );
            return new DataDownloadResponse($csv, 'board-audit-log.csv', 'text/csv');
        }

        return new JSONResponse(['results' => $this->auditLog->query(filters: $filters)], Http::STATUS_OK);

    }//end index()

    /**
     * Verify the integrity of the audit chain.
     *
     * GET /api/board/audit-log/verify
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     */
    #[NoAdminRequired]
    public function verify(): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        return new JSONResponse($this->auditLog->verify(), Http::STATUS_OK);

    }//end verify()
}//end class
