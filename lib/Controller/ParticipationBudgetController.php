<?php
/**
 * Decidesk Participation Budget Controller
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
 * @package  OCA\Decidesk\Controller
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

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\ParticipationResponder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for participatory-budget action endpoints.
 *
 * Staff actions are guarded by the ParticipationResponder (governance-body
 * authority via the decidesk chair group, falling back to NC admin); citizen
 * actions require an authenticated session. Fail closed.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationBudgetController extends Controller
{
    /**
     * Constructor for ParticipationBudgetController.
     *
     * @param IRequest                        $request            The request object
     * @param ParticipationLifecycleService   $lifecycleService   Lifecycle transitions
     * @param BudgetVotingService             $budgetService      Proposals + advisory voting
     * @param ParticipationPublicationService $publicationService Result publication
     * @param ParticipationResponder          $responder          Guard + response mapping
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
     * @param string $status   The target status.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function transitionBudgetRound(string $budgetId, string $status): JSONResponse
    {
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
     * The round must be in its submission phase (enforced server-side by the
     * service), so this action is scoped to the open-round state rather than an
     * arbitrary object id (no IDOR).
     *
     * @param string $budgetId    The budget round UUID.
     * @param string $title       The proposal title.
     * @param string $description The proposal description.
     * @param float  $amount      The requested amount.
     * @param string $category    Optional category.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function submitProposal(string $budgetId, string $title='', string $description='', float $amount=0, string $category=''): JSONResponse
    {
        return $this->responder->citizenAction(
            operation: fn (string $uid): array => $this->budgetService->submitProposal(
                budgetId: $budgetId,
                title: $title,
                description: $description,
                requested: $amount,
                submitterId: $uid,
                category: $category
            ),
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
     * @param string    $proposalId The proposal UUID.
     * @param bool|null $approve    False to reject; true or absent to approve.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function validateProposal(string $proposalId, ?bool $approve=null): JSONResponse
    {
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
    private function approveProposal(string $proposalId): JSONResponse
    {
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
    private function rejectProposal(string $proposalId): JSONResponse
    {
        return $this->applyProposalDecision(proposalId: $proposalId, approve: false);

    }//end rejectProposal()

    /**
     * Shared implementation behind approveProposal() and rejectProposal().
     *
     * @param string $proposalId The proposal UUID.
     * @param bool   $approve    True to validate, false to reject.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function applyProposalDecision(string $proposalId, bool $approve): JSONResponse
    {
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
     * One CitizenVote per citizen per proposal; the service rejects duplicates
     * (HTTP 409) and votes outside the voting window. The action is scoped to
     * the proposal's validated/voting state, not an arbitrary owned object.
     *
     * @param string $proposalId The proposal UUID.
     * @param string $value      'voor' | 'tegen'.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function castAdvisoryVote(string $proposalId, string $value=''): JSONResponse
    {
        return $this->responder->citizenAction(
            operation: fn (string $uid): array => $this->budgetService->castAdvisoryVote(
                proposalId: $proposalId,
                voterId: $uid,
                value: $value
            ),
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
    public function publishBudgetResults(string $budgetId): JSONResponse
    {
        return $this->responder->staffAction(
            operation: fn (): array => $this->publicationService->publishBudgetResults(budgetId: $budgetId)
        );

    }//end publishBudgetResults()
}//end class
