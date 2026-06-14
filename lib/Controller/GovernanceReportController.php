<?php
/**
 * Decidesk Governance Report Controller
 *
 * Admin/secretary-gated REST surface for the Phase 5 governance reporting
 * service: generate, list, show, export annual reports.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\GovernanceReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for governance reports.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 */
class GovernanceReportController extends Controller
{
    use GovernanceControllerTrait;

    /**
     * Constructor.
     *
     * @param IRequest                   $request          HTTP request
     * @param GovernanceReportingService $reportingService Reporting service
     * @param IUserSession               $userSession      User session
     * @param IGroupManager              $groupManager     Group manager
     */
    public function __construct(
        IRequest $request,
        private readonly GovernanceReportingService $reportingService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate an annual report.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     *
     * @return JSONResponse
     */
    public function generate(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $boardId = (string) $this->request->getParam('boardId', '');
        $year    = (int) $this->request->getParam('year', 0);
        if ($boardId === '' || $year === 0) {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardId' or 'year'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return $this->respondFromResult(
            result: $this->reportingService->generateAnnualReport($boardId, $year),
            payloadKey: 'report',
            successCode: Http::STATUS_CREATED
        );

    }//end generate()

    /**
     * List reports for a board.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $boardId = (string) $this->request->getParam('boardId', '');
        if ($boardId === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardId'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->reportingService->listReports($boardId);
        return new JSONResponse(
            [
                'results' => $result['reports'],
                'total'   => $result['count'],
            ]
        );

    }//end index()

    /**
     * Show a single report (JSON).
     *
     * @param string $id UUID of the report
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     *
     * @return JSONResponse
     */
    public function show(string $id): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $result = $this->reportingService->exportReport($id, 'json');
        if (($result['success'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'Report not found.');
            $status  = Http::STATUS_NOT_FOUND;
            if (stripos($message, 'not found') === false) {
                $status = Http::STATUS_UNPROCESSABLE_ENTITY;
            }

            return new JSONResponse(['message' => $message], $status);
        }

        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) === false) {
            $decoded = ['body' => $result['body']];
        }

        return new JSONResponse($decoded);

    }//end show()

    /**
     * Export a report in the chosen format (json or csv).
     *
     * @param string $id     UUID of the report
     * @param string $format One of json|csv
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     *
     * @return Response
     */
    public function export(string $id, string $format): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $result = $this->reportingService->exportReport($id, $format);
        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to export report.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $extension = 'json';
        if ($format === 'csv') {
            $extension = 'csv';
        }

        $filename = 'governance-report-'.$id.'.'.$extension;
        $response = new DataDisplayResponse(
            $result['body'],
            Http::STATUS_OK,
            ['Content-Type' => $result['contentType']]
        );
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
