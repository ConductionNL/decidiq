<?php

/**
 * Decidesk Board Evaluation Controller
 *
 * REST surface for the board self-evaluation workflow (board-self-evaluation):
 * anonymous response submission, closing a cycle (materialises scoring),
 * publishing the aggregate summary, and generating the report document.
 * Object CRUD (create/list/read a BoardEvaluation, EvaluationTemplate) uses
 * the standard OpenRegister object API and needs no bespoke endpoint here.
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
 * @spec openspec/specs/board-self-evaluation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardEvaluationAccessGuard;
use OCA\Decidesk\Service\BoardEvaluationReportService;
use OCA\Decidesk\Service\BoardEvaluationResponseService;
use OCA\Decidesk\Service\BoardEvaluationScoreService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the board self-evaluation workflow.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md
 */
class BoardEvaluationController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request
	 * @param BoardEvaluationResponseService $responseService Anonymous response collection
	 * @param BoardEvaluationScoreService $scoreService Scoring + cycle close
	 * @param BoardEvaluationReportService $reportService Report document generation
	 * @param ParticipationPublicationService $publicationService Publication of the aggregate summary
	 * @param IUserSession $userSession User session
	 * @param BoardEvaluationAccessGuard $accessGuard Per-object authorisation for the action endpoints
	 */
	public function __construct(
		IRequest $request,
		private readonly BoardEvaluationResponseService $responseService,
		private readonly BoardEvaluationScoreService $scoreService,
		private readonly BoardEvaluationReportService $reportService,
		private readonly ParticipationPublicationService $publicationService,
		private readonly IUserSession $userSession,
		private readonly BoardEvaluationAccessGuard $accessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Submit the authenticated user's anonymous response to an evaluation
	 * cycle. Participant identity is derived server-side from the session
	 * (never trusted from client input) and is never persisted on the
	 * response content.
	 *
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function respond(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		// Scoped to THIS evaluation's governance body: one person sits on
		// several bodies and therefore has several Participant objects, so an
		// unscoped UID lookup can hand back the identity they hold on a
		// different board — which the roster check then correctly rejects,
		// leaving a legitimately invited member unable to respond.
		$nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
		$participantId = $this->responseService->resolveResponder(
			evaluationId: $id,
			nextcloudUid: $nextcloudUid
		);
		if ($participantId === null) {
			return new JSONResponse(
				['message' => 'No participant profile for the logged-in user on this evaluation\'s governance body.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$answers = $this->request->getParam('answers', []);
		if (is_array($answers) === false) {
			$answers = [];
		}

		return $this->respondFromResult(
			result: $this->responseService->submitResponse(
				evaluationId: $id,
				participantId: $participantId,
				answers: $answers
			),
			payloadKey: 'response',
			successCode: Http::STATUS_CREATED
		);

	}//end respond()

	/**
	 * Close an open evaluation cycle: computes and materialises the score
	 * summary and advances the lifecycle to `closed`.
	 *
	 * `$id` is caller-supplied, so the caller is authorised FOR THAT
	 * EVALUATION first: the body's chair or secretary, or an admin. That is
	 * the rule the schema's `lifecycle.update` block already declares
	 * (REQ-EVAL-006 — the guard reads the same `chairUserId`/`secretaryUserId`
	 * fields rather than inventing a second one), enforced here so an
	 * unauthorised caller is refused BEFORE the scoring pass runs and gets a
	 * 403 instead of the 422 OpenRegister's later refusal produced.
	 *
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function close(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$denied = $this->accessGuard->requireChairOrSecretary(evaluationId: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->respondFromResult(
			result: $this->scoreService->closeCycle(evaluationId: $id),
			payloadKey: 'evaluation'
		);

	}//end close()

	/**
	 * Publish a closed evaluation's aggregate summary through the existing
	 * publication stack. Raw responses are never published.
	 *
	 * Publishing sets `publicationDate`, which is what makes the summary
	 * anonymously readable, and advances `lifecycle` to `published` — so the
	 * same chair/secretary rule as `close()` applies, and for the same reason
	 * it is checked here: `ParticipationPublicationService::publishSummary()`
	 * catches OpenRegister's refusal and still answers 200 with
	 * `publishedPredicateSet: false`, so an unauthorised caller could not tell
	 * a denial from a success.
	 *
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$denied = $this->accessGuard->requireChairOrSecretary(evaluationId: $id);
		if ($denied !== null) {
			return $denied;
		}

		try {
			$result = $this->publicationService->publishEvaluationResults(evaluationId: $id);
			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

	}//end publish()

	/**
	 * Generate the evaluation report document (markdown canonical, Docudesk
	 * PDF where present) via the existing minutes/document generation path.
	 *
	 * Writes no `lifecycle`, so OpenRegister's property rule gates nothing
	 * here: the authorisation is this guard's alone. It is the WIDER of the
	 * two rules — any member of the evaluating body, not just the presiding
	 * officers — because REQ-EVAL-005 names no actor for report generation and
	 * the "Generate report" button is offered to every member on the results
	 * tab. What it excludes is a caller who is not on the body at all.
	 *
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function report(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$denied = $this->accessGuard->requireBodyMember(evaluationId: $id);
		if ($denied !== null) {
			return $denied;
		}

		try {
			$result = $this->reportService->generate(evaluationId: $id);
			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

	}//end report()
}//end class
