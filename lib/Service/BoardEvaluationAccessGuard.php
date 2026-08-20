<?php

/**
 * Decidesk Board Evaluation Access Guard
 *
 * The two per-object authorisation questions the board self-evaluation ACTION
 * endpoints ask: "may this caller act as the evaluating body's chair or
 * secretary?" (close / publish) and "is this caller on that body at all?"
 * (report).
 *
 * The chair/secretary rule is NOT invented here. It is the rule the
 * `BoardEvaluation` schema already declares on its `lifecycle` property
 * (`lib/Settings/decidesk_register.json` — `authorization.update` matching
 * `chairUserId: $userId` or `secretaryUserId: $userId`), which OpenRegister
 * enforces on the object write, and which REQ-EVAL-006 fixes as the source of
 * truth ("Lifecycle gating is OR RBAC, not app-local"). This guard reads the
 * SAME two denormalised fields so no second, drifting rule exists — it adds no
 * role model and no app-local authorization service. What it adds is a
 * refusal at the app boundary: without it, `close()` ran the whole scoring
 * pass before OR refused the write and the caller got a generic 422, and
 * `publish()` answered HTTP 200 with `publishedPredicateSet: false` because
 * `ParticipationPublicationService::publishSummary()` swallows the refusal in
 * a `catch` — an unauthorised caller could not tell a denial from a success.
 * `report()` writes no `lifecycle` at all, so OR gated it nowhere.
 *
 * Fails CLOSED: an unresolvable governance body is 403 for every non-admin. A
 * missing BoardEvaluation is deliberately NOT this guard's 403 — it returns
 * null so the action produces its own 404/422 rather than turning the guard
 * into an existence oracle.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Per-object authorisation for the board self-evaluation action endpoints.
 * Fail-closed.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
 */
class BoardEvaluationAccessGuard {

	/**
	 * Constructor for BoardEvaluationAccessGuard.
	 *
	 * @param ObjectServiceInterface $objectService OR object service
	 * @param ParticipantUuidLookup $participants Nextcloud UID -> Participant resolution, scoped to a governance body
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager for the admin bypass
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantUuidLookup $participants,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Require the evaluating body's chair or secretary (or an NC admin).
	 *
	 * Used by `close()` and `publish()`: both write the `lifecycle` property,
	 * whose OpenRegister `authorization.update` rule names exactly
	 * `chairUserId` / `secretaryUserId`. The rule is therefore identical to
	 * the one OR enforces on the write — this only moves the refusal forward
	 * to the HTTP boundary, where it can be a 403 instead of a 422 (close) or
	 * a silently-unpublished 200 (publish).
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return JSONResponse|null Null when authorised, a 401/403 JSONResponse otherwise
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function requireChairOrSecretary(string $evaluationId): ?JSONResponse {
		$userId = $this->currentUid();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		$evaluation = $this->findEvaluation(evaluationId: $evaluationId);
		if ($evaluation === null) {
			// Not this guard's 404 to raise — let the action answer.
			return null;
		}

		if ($this->isChairOrSecretary(evaluation: $evaluation, userId: $userId) === true) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Forbidden: chair or secretary of the evaluating body required.'],
			Http::STATUS_FORBIDDEN
		);
	}//end requireChairOrSecretary()

	/**
	 * Require membership of the evaluating body (any role), its chair or
	 * secretary, or an NC admin.
	 *
	 * Used by `report()`, which generates the cycle's report document into the
	 * body's Files folder. Deliberately WIDER than
	 * `requireChairOrSecretary()`: REQ-EVAL-005 names no actor for report
	 * generation, the document carries only the aggregate `scoreSummary` that
	 * every member already reads on the results tab, and the "Generate report"
	 * button sits on the same tab for every member — narrowing it to the
	 * presiding officers would take a capability away from board members who
	 * have it today. It is still not instance-wide: a board self-evaluation
	 * report is that board's internal document, and the caller-supplied
	 * evaluation id must not let a user off the body materialise it.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return JSONResponse|null Null when authorised, a 401/403 JSONResponse otherwise
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function requireBodyMember(string $evaluationId): ?JSONResponse {
		$userId = $this->currentUid();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		$evaluation = $this->findEvaluation(evaluationId: $evaluationId);
		if ($evaluation === null) {
			return null;
		}

		if ($this->isChairOrSecretary(evaluation: $evaluation, userId: $userId) === true) {
			return null;
		}

		$governanceBodyId = $this->governanceBodyIdOf(evaluation: $evaluation);
		if ($governanceBodyId !== ''
			&& $this->participants->forNextcloudUserInBody(
				nextcloudUid: $userId,
				governanceBodyId: $governanceBodyId
			) !== null
		) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Forbidden: membership of the evaluating body required.'],
			Http::STATUS_FORBIDDEN
		);
	}//end requireBodyMember()

	/**
	 * Whether the caller is the evaluation's denormalised chair or secretary.
	 *
	 * Compares against `chairUserId` / `secretaryUserId` — the exact two
	 * fields the schema's `lifecycle.update` rule matches on `$userId`. An
	 * empty field never matches, so a cycle created without a chair or
	 * secretary is closed to every non-admin, which is the same answer
	 * OpenRegister gives.
	 *
	 * @param array<string, mixed> $evaluation Serialised BoardEvaluation
	 * @param string $userId Nextcloud UID of the caller
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	private function isChairOrSecretary(array $evaluation, string $userId): bool {
		$chairUserId = (string)($evaluation['chairUserId'] ?? '');
		$secretaryUserId = (string)($evaluation['secretaryUserId'] ?? '');

		return ($chairUserId !== '' && $chairUserId === $userId)
			|| ($secretaryUserId !== '' && $secretaryUserId === $userId);
	}//end isChairOrSecretary()

	/**
	 * Read the governance body the evaluation belongs to.
	 *
	 * OpenRegister hands the link back either as a plain property or under
	 * `@self.relations`, so both shapes are honoured — the same read
	 * `BoardEvaluationResponseService::resolveResponder()` performs.
	 *
	 * @param array<string, mixed> $evaluation Serialised BoardEvaluation
	 *
	 * @return string The governance-body UUID, or '' when not linked
	 */
	private function governanceBodyIdOf(array $evaluation): string {
		$relations = (array)($evaluation['@self']['relations'] ?? []);

		return (string)($evaluation['governanceBody'] ?? ($relations['governanceBody'] ?? ''));
	}//end governanceBodyIdOf()

	/**
	 * Load the serialised BoardEvaluation, or null when it cannot be read.
	 *
	 * `find()` THROWS for an unknown id rather than returning null (see
	 * `ParticipantResolver::resolveGovernanceBodyId()`), so both answers are
	 * translated to null here and the action gets to raise its own 404.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return array<string, mixed>|null
	 */
	private function findEvaluation(string $evaluationId): ?array {
		if ($evaluationId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $evaluationId,
				register: 'decidesk',
				schema: 'board-evaluation'
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findEvaluation()

	/**
	 * Resolve the acting user's UID.
	 *
	 * @return string|null The UID, or null when no user is signed in
	 */
	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end currentUid()
}//end class
