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
use OCA\Decidesk\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
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
class GovernanceReportController extends Controller {
	use RequiresOrAdmin;
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param GovernanceReportingService $reportingService Reporting service
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager
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
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function generate(): JSONResponse {
		$deny = $this->requireAdmin();
		if ($deny !== null) {
			return $deny;
		}

		$boardId = (string)$this->request->getParam('boardId', '');
		$year = (int)$this->request->getParam('year', 0);
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
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		$deny = $this->requireAdmin();
		if ($deny !== null) {
			return $deny;
		}

		$boardId = (string)$this->request->getParam('boardId', '');
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
				'total' => $result['count'],
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
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function show(string $id): JSONResponse {
		$deny = $this->requireAdmin();
		if ($deny !== null) {
			return $deny;
		}

		$result = $this->reportingService->exportReport($id, 'json');
		if ($result['success'] === false) {
			$message = $result['message'];
			$status = Http::STATUS_NOT_FOUND;
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
	 * @param string $id UUID of the report
	 * @param string $format One of json|csv
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
	 *
	 * @return Response
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function export(string $id, string $format): Response {
		$deny = $this->requireAdmin();
		if ($deny !== null) {
			return $deny;
		}

		$result = $this->reportingService->exportReport($id, $format);
		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message']],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$extension = 'json';
		if ($format === 'csv') {
			$extension = 'csv';
		}

		$filename = 'governance-report-' . $id . '.' . $extension;
		$response = new DataDisplayResponse(
			$result['body'],
			Http::STATUS_OK,
			['Content-Type' => $result['contentType']]
		);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
		return $response;
	}//end export()

	// Admin guard requireAdmin() comes from the shared RequiresOrAdmin trait
	// (consume-or-rbac-authorization, REQ-RBAC-004).
}//end class
