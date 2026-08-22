<?php

/**
 * Decidiq Participation Budget Controller
 *
 * Thin REST controller for the participatory-BUDGET half of citizen
 * participation: round lifecycle, proposal submission + staff validation,
 * advisory voting, and allocation-result publication. Split out of
 * ParticipationController, which had grown to cover three separate concerns
 * (consultations, reactions, participatory budgeting) in one class.
 *
 * The URLs and verbs are unchanged — only the route target moved. Plain object
 * CRUD stays on the OpenRegister object API (ADR-022) — no pass-through
 * endpoints.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\ParticipationResponder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for participatory-budget action endpoints.
 *
 * Staff actions are guarded by the ParticipationResponder (governance-body
 * authority via the Decidiq chair group, falling back to NC admin); citizen
 * actions require an authenticated session. Fail closed.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationBudgetController extends Controller {
	/**
	 * Constructor for ParticipationBudgetController.
	 *
	 * @param IRequest $request The request object
	 * @param ParticipationLifecycleService $lifecycleService Lifecycle transitions
	 * @param BudgetVotingService $budgetService Proposals + advisory voting
	 * @param ParticipationPublicationService $publicationService Result publication
	 * @param ParticipationResponder $responder Guard + response mapping
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ParticipationLifecycleService $lifecycleService,
		private readonly BudgetVotingService $budgetService,
		private readonly ParticipationPublicationService $publicationService,
		private readonly ParticipationResponder $responder,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Transition a participatory-budget round status (staff only).
	 *
	 * @param string $budgetId The budget round UUID.
	 * @param string $status The target status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function transitionBudgetRound(string $budgetId, string $status): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->lifecycleService->transitionBudgetRound(
				budgetId: $budgetId,
				newStatus: $status
			),
			key: 'budgetRound'
		);

	}//end transitionBudgetRound()

	/**
	 * Submit a budget proposal as an authenticated citizen.
	 *
	 * Open to EVERY authenticated account by design — "Authenticated citizens
	 * SHALL submit `BudgetProposal` objects to a round during its `submission`
	 * phase" (citizen-participation spec), and the register's authorization
	 * baseline says the same (`create: ["authenticated"]`). Narrowing the
	 * audience would be inventing a restriction, not closing a hole.
	 *
	 * What IS enforced, and is the whole authorization surface here:
	 *   - the recorded `submitter` is the SESSION's UID (`$uid` below), never a
	 *     value the request supplied — the caller cannot file a proposal under
	 *     someone else's name;
	 *   - the round must be in its submission phase, checked server-side by
	 *     `ParticipationLifecycleService::budgetAcceptsProposals()` against the
	 *     stored status AND the `submissionDeadline`, so a caller-supplied
	 *     `budgetId` naming a draft or closed round is refused.
	 *
	 * @param string $budgetId The budget round UUID.
	 * @param string $title The proposal title.
	 * @param string $description The proposal description.
	 * @param float $amount The requested amount.
	 * @param string $category Optional category.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	#[NoAdminRequired]
	public function submitProposal(
		string $budgetId,
		string $title = '',
		string $description = '',
		float $amount = 0,
		string $category = '',
	): JSONResponse {
		$uid = $this->responder->currentUid();

		return $this->responder->citizenAction(
			operation: fn (): array => $this->budgetService->submitProposal(
				budgetId: $budgetId,
				title: $title,
				description: $description,
				requested: $amount,
				submitterId: (string)$uid,
				category: $category
			),
			uid: $uid,
			key: 'proposal',
			status: Http::STATUS_CREATED
		);

	}//end submitProposal()

	/**
	 * Validate or reject a submitted proposal (staff only).
	 *
	 * Dispatches to the intention-revealing approve/reject helpers. An absent
	 * `approve` value keeps the historical "approve by default" contract.
	 *
	 * @param string $proposalId The proposal UUID.
	 * @param bool|null $approve False to reject; true or absent to approve.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function validateProposal(string $proposalId, ?bool $approve = null): JSONResponse {
		if ($approve === false) {
			return $this->rejectProposal(proposalId: $proposalId);
		}

		return $this->approveProposal(proposalId: $proposalId);
	}//end validateProposal()

	/**
	 * Approve a submitted proposal (staff only).
	 *
	 * @param string $proposalId The proposal UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function approveProposal(string $proposalId): JSONResponse {
		return $this->applyProposalDecision(proposalId: $proposalId, approve: true);
	}//end approveProposal()

	/**
	 * Reject a submitted proposal (staff only).
	 *
	 * @param string $proposalId The proposal UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function rejectProposal(string $proposalId): JSONResponse {
		return $this->applyProposalDecision(proposalId: $proposalId, approve: false);
	}//end rejectProposal()

	/**
	 * Shared implementation behind approveProposal() and rejectProposal().
	 *
	 * @param string $proposalId The proposal UUID.
	 * @param bool $approve True to validate, false to reject.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function applyProposalDecision(string $proposalId, bool $approve): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->budgetService->validateProposal(
				proposalId: $proposalId,
				approve: $approve
			),
			key: 'proposal'
		);

	}//end applyProposalDecision()

	/**
	 * Cast one advisory vote on a validated proposal (authenticated citizen).
	 *
	 * Open to EVERY authenticated account by design — "Authenticated citizens
	 * SHALL cast one advisory vote (voor/tegen) per `validated` proposal during
	 * the round's `voting` phase" (citizen-participation spec).
	 *
	 * What IS enforced:
	 *   - the recorded `voterId` is the SESSION's UID (`$uid` below), never a
	 *     request value — so one caller cannot vote as another citizen, and the
	 *     one-vote-per-citizen rule below cannot be sidestepped by renaming
	 *     oneself;
	 *   - one CitizenVote per citizen per proposal —
	 *     `AdvisoryVoteService::applyAdvisoryTally()` refuses a duplicate (409);
	 *   - the proposal must be `validated` AND its round must accept votes
	 *     (status + `votingDeadline`), both server-side, so a caller-supplied
	 *     `proposalId` naming a closed round is refused.
	 *
	 * @param string $proposalId The proposal UUID.
	 * @param string $value 'voor' | 'tegen'.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	#[NoAdminRequired]
	public function castAdvisoryVote(string $proposalId, string $value = ''): JSONResponse {
		$uid = $this->responder->currentUid();

		return $this->responder->citizenAction(
			operation: fn (): array => $this->budgetService->castAdvisoryVote(
				proposalId: $proposalId,
				voterId: (string)$uid,
				value: $value
			),
			uid: $uid,
			status: Http::STATUS_CREATED
		);

	}//end castAdvisoryVote()

	/**
	 * Publish a budget round's PII-free allocation results (staff only).
	 *
	 * @param string $budgetId The budget round UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function publishBudgetResults(string $budgetId): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->publicationService->publishBudgetResults(budgetId: $budgetId)
		);

	}//end publishBudgetResults()
}//end class
