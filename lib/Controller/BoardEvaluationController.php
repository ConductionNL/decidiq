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
	 */
	public function __construct(
		IRequest $request,
		private readonly BoardEvaluationResponseService $responseService,
		private readonly BoardEvaluationScoreService $scoreService,
		private readonly BoardEvaluationReportService $reportService,
		private readonly ParticipationPublicationService $publicationService,
		private readonly IUserSession $userSession,
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
	 * summary and advances the lifecycle to `closed`. OpenRegister's
	 * property-RBAC rule on `lifecycle` already enforces that only the
	 * body's chair/secretary can reach this state (the client-side write
	 * that got the object to lifecycle=open/closed happens via the normal
	 * object API); this endpoint runs the scoring side-effect.
	 *
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function close(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
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
	 * @param string $id UUID of the BoardEvaluation
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
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

		try {
			$result = $this->reportService->generate(evaluationId: $id);
			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

	}//end report()
}//end class
