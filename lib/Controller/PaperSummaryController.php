<?php

/**
 * Decidiq Paper Summary Controller
 *
 * A clerk asks for an AI summary or comparison of a paper attached to an
 * agenda item, and the agenda item page asks whether an AI provider is
 * installed. Reviewing a summary (edit, show, hide) is an ordinary
 * OpenRegister update guarded by the schema's lifecycle and update rule, so it
 * has no endpoint here.
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
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Service\PaperSummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Paper summary requests and provider availability.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
class PaperSummaryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request   The request
	 * @param PaperSummaryService $summaries Checks, schedules and saves
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PaperSummaryService $summaries,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask for a summary of a paper, or a comparison with a second paper.
	 *
	 * POST /api/agenda-items/{id}/paper-summaries
	 * Body: { fileId, kind: summary|comparison, comparedFileId? }
	 *
	 * Access control: per-object guard in PaperSummaryService through
	 * PaperSummaryAccess::assertMayRequest(): 401 when anonymous, 403 outside
	 * the secretariat or outside an active confidentiality restriction's
	 * circle on the item or the paper.
	 *
	 * @param string $id The agenda item
	 *
	 * @return JSONResponse 201 the requested summary; 401, 403, 422 or 503 with a message
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		$compared = $this->request->getParam('comparedFileId');
		$comparedFileId = null;
		if ($compared !== null && $compared !== '') {
			$comparedFileId = (int)$compared;
		}

		try {
			$summary = $this->summaries->request(
				agendaItemId: $id,
				fileId: (int)$this->request->getParam('fileId', 0),
				kind: (string)$this->request->getParam('kind', 'summary'),
				comparedFileId: $comparedFileId
			);
		} catch (PaperSummaryRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($summary, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Whether an installed TaskProcessing provider can summarise and compare.
	 *
	 * GET /api/paper-summaries/availability
	 *
	 * Access control: any signed-in user (NC middleware); the answer is three
	 * booleans about the instance and names no object, so no per-object guard
	 * applies. Asking for a summary is guarded in create().
	 *
	 * @return JSONResponse 200 { available, summary, comparison }
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		return new JSONResponse($this->summaries->availability());
	}//end availability()
}//end class
