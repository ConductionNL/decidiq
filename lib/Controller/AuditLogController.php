<?php
/**
 * Decidesk Audit Log Controller
 *
 * REST endpoints for the board portal hash-chained audit log.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the board audit log.
 *
 * Access is restricted to NC administrators (the secretary group). Per
 * ADR-005 / OWASP A01, the controller verifies `IGroupManager::isAdmin()` on
 * every request rather than delegating to the framework's
 *
 * @NoAdminRequired absence (which is silently bypassed in some test setups).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
 */
class AuditLogController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor for AuditLogController.
     *
     * @param IRequest        $request         The HTTP request
     * @param AuditLogService $auditLogService The audit log service
     * @param IUserSession    $userSession     The user session
     * @param IGroupManager   $groupManager    NC group manager (admin check)
     */
    public function __construct(
        IRequest $request,
        private readonly AuditLogService $auditLogService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Query the audit log.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $filters = [
            'actor'      => (string) $this->request->getParam('actor', ''),
            'action'     => (string) $this->request->getParam('action', ''),
            'startDate'  => (string) $this->request->getParam('startDate', ''),
            'endDate'    => (string) $this->request->getParam('endDate', ''),
            'objectUuid' => (string) $this->request->getParam('objectUuid', ''),
            'limit'      => (int) $this->request->getParam('limit', 100),
            'offset'     => (int) $this->request->getParam('offset', 0),
        ];

        foreach (['actor', 'action', 'startDate', 'endDate', 'objectUuid'] as $key) {
            if ($filters[$key] === '') {
                unset($filters[$key]);
            }
        }

        $result = $this->auditLogService->query($filters);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => 'Failed to query audit log.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(
            [
                'results' => $result['entries'],
                'total'   => $result['count'],
            ]
        );

    }//end index()

    /**
     * Verify the hash chain up to (and including) the given entry.
     *
     * @param string $id UUID of the entry to stop at
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function verify(string $id): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return new JSONResponse($this->auditLogService->verify($id));

    }//end verify()

    /**
     * Export a date-range slice of the audit log as JSON or CSV.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.4
     *
     * @return Response
     */
    #[NoAdminRequired]
    public function export(): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $startDate = (string) $this->request->getParam('startDate', '1970-01-01T00:00:00Z');
        $endDate   = (string) $this->request->getParam('endDate', '9999-12-31T23:59:59Z');
        $format    = strtolower((string) $this->request->getParam('format', 'json'));

        $result = $this->auditLogService->export($startDate, $endDate, $format);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => 'Failed to export audit log.'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $contentType = 'application/json';
        $extension   = 'json';
        if ($format === 'csv') {
            $contentType = 'text/csv';
            $extension   = 'csv';
        }

        $filename = 'board-audit-log-'.gmdate('Ymd-His').'.'.$extension;

        $response = new DataDisplayResponse($result['body'], Http::STATUS_OK, ['Content-Type' => $contentType]);
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;

    }//end export()

    /**
     * Return 401 / 403 when the caller is not an admin; null otherwise.
     *
     * @return JSONResponse|null
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Administrator role required.'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireAdmin()
}//end class
