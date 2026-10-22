<?php

/**
 * Decidiq decision authority endpoint.
 *
 * Tells the decisions leaf whether the person who drafted a decision may take
 * it under the delegatie- en mandaatregister, so a host page can say so beside
 * the decision.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionAuthority;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads a decision's authority answer.
 *
 * AUTH POSTURE. Signed-in users only. The decision is read through
 * ObjectService with RBAC and multitenancy at their defaults inside
 * DecisionAuthority, before the register is asked: a decision the caller may
 * not read answers 404, the same as a missing one. The answer discloses only
 * whether the drafter holds the authority and, when they do, the covering
 * allocation's subject and restrictions, which the register publishes.
 *
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */
class DecisionAuthorityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     HTTP request
	 * @param IUserSession      $userSession Nextcloud user session
	 * @param DecisionAuthority $authority   Answers the authority question
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DecisionAuthority $authority,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether a decision's drafter may take it.
	 *
	 * GET /api/decisions/{decisionId}/authority
	 *
	 * Returns 200 with { authorised, reason, actor, act, subject, restrictions },
	 * 401 when signed out, 404 when the decision is missing or unreadable.
	 *
	 * @param string $decisionId UUID of the decision
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
	 */
	#[NoAdminRequired]
	public function show(string $decisionId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		$answer = $this->authority->forDecision(decisionId: $decisionId);
		if ($answer === null) {
			return new JSONResponse(['message' => 'Decision not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($answer);
	}//end show()
}//end class
