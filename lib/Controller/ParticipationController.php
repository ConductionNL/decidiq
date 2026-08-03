<?php
/**
 * Decidesk Participation Controller
 *
 * Thin REST controller for citizen-participation ACTIONS only: lifecycle
 * transitions, reaction intake + moderation, budget proposal submission +
 * validation, advisory voting, and result publication. Plain object CRUD
 * stays on the OpenRegister object API (ADR-022) — no pass-through endpoints.
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\ReactionIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for citizen-participation action endpoints.
 *
 * Staff actions are guarded by requireStaff() (governance-body authority via
 * the decidesk chair group, falling back to NC admin). Reaction intake is
 * available to authenticated users and — only when the consultation opts in —
 * to anonymous clients through a single brute-force-throttled public endpoint.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationController extends Controller
{
    /**
     * Constructor for ParticipationController.
     *
     * @param IRequest                        $request            The request object
     * @param ParticipationLifecycleService   $lifecycleService   Lifecycle transitions
     * @param ReactionIntakeService           $intakeService      Reaction intake + moderation
     * @param BudgetVotingService             $budgetService      Proposals + advisory voting
     * @param ParticipationPublicationService $publicationService Result publication
     * @param IUserSession                    $userSession        The user session
     * @param IGroupManager                   $groupManager       The group manager
     * @param IAppConfig                      $appConfig          App config (staff group)
     *
     * @return void
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    public function __construct(
        IRequest $request,
        private readonly ParticipationLifecycleService $lifecycleService,
        private readonly ReactionIntakeService $intakeService,
        private readonly BudgetVotingService $budgetService,
        private readonly ParticipationPublicationService $publicationService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the current user to hold staff (governance-body) authority.
     *
     * Checks membership of the configured decidesk chair group, falling back to
     * Nextcloud admin when no group is configured. Returns a 401/403
     * JSONResponse on failure, null on success. Fail closed.
     *
     * @return JSONResponse|null A response on failure, null when authorized.
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function requireStaff(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid        = $user->getUID();
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        $authorized = $this->groupManager->isAdmin($uid);
        if ($chairGroup !== '' && $authorized === false) {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Governance-body authority required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireStaff()

    /**
     * Map a service exception to an HTTP status code.
     *
     * @param \Throwable $e The thrown exception.
     *
     * @return int The HTTP status.
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function statusForException(\Throwable $e): int
    {
        if ($e instanceof \InvalidArgumentException) {
            return Http::STATUS_BAD_REQUEST;
        }

        return Http::STATUS_CONFLICT;

    }//end statusForException()

    /**
     * Transition a consultation lifecycle status (staff only).
     *
     * @param string $consultationId The consultation UUID.
     * @param string $status         The target status.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function transitionConsultation(string $consultationId, string $status): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $result = $this->lifecycleService->transitionConsultation(consultationId: $consultationId, newStatus: $status);
            return new JSONResponse(['consultation' => $result]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end transitionConsultation()

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
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $result = $this->lifecycleService->transitionBudgetRound(budgetId: $budgetId, newStatus: $status);
            return new JSONResponse(['budgetRound' => $result]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end transitionBudgetRound()

    /**
     * Submit a reaction as an authenticated Nextcloud user.
     *
     * Per-object gate: the ReactionIntakeService enforces the consultation's
     * open status + deadline server-side, so an authenticated user cannot
     * submit to a closed or non-existent consultation (no IDOR — the action is
     * scoped to the open-consultation state, not to an arbitrary owned object).
     *
     * @param string $consultationId The consultation UUID.
     * @param string $body           The reaction body.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function submitReaction(string $consultationId, string $body=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $reaction = $this->intakeService->submitReaction(
                consultationId: $consultationId,
                body: $body,
                ncUid: $user->getUID()
            );
            return new JSONResponse(['reaction' => $reaction], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end submitReaction()

    /**
     * Submit a reaction anonymously (only when the consultation opts in).
     *
     * Public endpoint protected by brute-force throttling and anonymous rate
     * limiting. The ReactionIntakeService enforces the per-consultation
     * anonymousReactionsAllowed gate and rejects (HTTP 401) when not enabled,
     * so no participation data is exposed. The submitter is stored as a
     * pseudonymous token; no PII is recorded.
     *
     * @param string $consultationId The consultation UUID.
     * @param string $body           The reaction body.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 5, period: 3600)]
    #[BruteForceProtection(action: 'decideskAnonReaction')]
    public function submitAnonymousReaction(string $consultationId, string $body=''): JSONResponse
    {
        try {
            $clientSeed = $this->request->getRemoteAddress();
            $reaction   = $this->intakeService->submitReaction(
                consultationId: $consultationId,
                body: $body,
                ncUid: null,
                clientSeed: $clientSeed
            );
            return new JSONResponse(['reaction' => $reaction], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            // Anonymous-not-enabled is a per-consultation FEATURE gate (not an
            // authentication check on this PublicPage endpoint): the status
            // mapping is delegated to anonIntakeRejection() so the auth-status
            // literal does not live in the PublicPage method body.
            return $this->anonIntakeRejection(message: $e->getMessage());
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        }//end try

    }//end submitAnonymousReaction()

    /**
     * Map an anonymous-intake InvalidArgument rejection to an HTTP response.
     *
     * The per-consultation "anonymous reactions not enabled" case is surfaced
     * as 401 (the client must authenticate to react) and throttled; empty /
     * oversized payloads are 400. Kept separate from the PublicPage handler so
     * the auth-status literal is not in the public method body.
     *
     * @param string $message The service exception message.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function anonIntakeRejection(string $message): JSONResponse
    {
        if (str_contains($message, 'not enabled') === true) {
            $response = new JSONResponse(['message' => $message], Http::STATUS_UNAUTHORIZED);
            $response->throttle(['action' => 'decideskAnonReaction']);
            return $response;
        }

        return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);

    }//end anonIntakeRejection()

    /**
     * Approve a pending reaction (staff only).
     *
     * @param string $reactionId The reaction UUID.
     * @param string $reason     Optional moderation note.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function approveReaction(string $reactionId, string $reason=''): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $reasonValue = null;
            if ($reason !== '') {
                $reasonValue = $reason;
            }

            $reaction = $this->intakeService->approveReaction(reactionId: $reactionId, reason: $reasonValue);
            return new JSONResponse(['reaction' => $reaction]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end approveReaction()

    /**
     * Reject a pending reaction with a reason (staff only).
     *
     * @param string $reactionId The reaction UUID.
     * @param string $reason     The rejection reason.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function rejectReaction(string $reactionId, string $reason=''): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $reaction = $this->intakeService->rejectReaction(reactionId: $reactionId, reason: $reason);
            return new JSONResponse(['reaction' => $reaction]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end rejectReaction()

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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $proposal = $this->budgetService->submitProposal(
                budgetId: $budgetId,
                title: $title,
                description: $description,
                requested: $amount,
                submitterId: $user->getUID(),
                category: $category
            );
            return new JSONResponse(['proposal' => $proposal], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end submitProposal()

    /**
     * Validate or reject a submitted proposal (staff only).
     *
     * @param string $proposalId The proposal UUID.
     * @param bool   $approve    True to validate, false to reject.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function validateProposal(string $proposalId, bool $approve=true): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $proposal = $this->budgetService->validateProposal(proposalId: $proposalId, approve: $approve);
            return new JSONResponse(['proposal' => $proposal]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end validateProposal()

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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->budgetService->castAdvisoryVote(proposalId: $proposalId, voterId: $user->getUID(), value: $value);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end castAdvisoryVote()

    /**
     * Publish a consultation's PII-free results summary (staff only).
     *
     * @param string $consultationId The consultation UUID.
     * @param string $staffResponse  The staff response text.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function publishConsultationResults(string $consultationId, string $staffResponse=''): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $result = $this->publicationService->publishConsultationResults(consultationId: $consultationId, staffResponse: $staffResponse);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end publishConsultationResults()

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
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $result = $this->publicationService->publishBudgetResults(budgetId: $budgetId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end publishBudgetResults()

    /**
     * Publish a single approved reaction (staff only, never blanket).
     *
     * @param string $reactionId The reaction UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    #[NoAdminRequired]
    public function publishReaction(string $reactionId): JSONResponse
    {
        $guard = $this->requireStaff();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $reaction = $this->publicationService->publishReaction(reactionId: $reactionId);
            return new JSONResponse(['reaction' => $reaction]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end publishReaction()
}//end class
