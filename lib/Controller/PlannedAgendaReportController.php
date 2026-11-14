<?php

/**
 * Decidiq PlannedAgendaReportController
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\PlannedAgendaReportException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\PlannedAgendaReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Download the long-term agenda, as its filters select it, as a report.
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request The request.
	 * @param PlannedAgendaReportService $reports The report.
	 * @param LoggerInterface            $logger  Diagnostics.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 */
	public function __construct(
		IRequest $request,
		private readonly PlannedAgendaReportService $reports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the report and save it in the caller's Files.
	 *
	 * POST /api/planned-agenda/report with `filter` (the list's active
	 * filters) and `search` (its search text).
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @no-admin-idor-exempt every item and every name is read through OpenRegister as the caller, so its read
	 *                       rules decide what the report holds; no object is addressed by id.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$filter = $this->request->getParam('filter', []);
		if (is_array($filter) === false) {
			$filter = [];
		}

		try {
			$result = $this->reports->render(filter: $filter, search: (string)$this->request->getParam('search', ''));
		} catch (PlannedAgendaReportException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('Decidiq long-term agenda report: the report failed', ['exception' => $e]);
			return new JSONResponse(
				['message' => 'The report failed. Try again, or read the Nextcloud log for the cause.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end create()
}//end class
