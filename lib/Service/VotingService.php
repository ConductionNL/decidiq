<?php
/**
 * Decidesk Voting Service
 *
 * Business logic for voting round management, quorum enforcement, vote casting,
 * proxy delegation, result tallying, and ORI publication triggering.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidesk\Service\ParticipantResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service implementing voting round governance rules.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

    /**
     * Configurable majority thresholds (mirrors Resolution.voteThreshold).
     *
     * @var string[]
     */
    public const VOTE_THRESHOLDS = [
        'simple-majority',
        'qualified-majority-two-thirds',
        'qualified-majority-three-quarters',
        'unanimous',
    ];

    /**
     * Abstention-handling modes: 'exclude' keeps abstentions out of the
     * calculation base (default); 'count' adds them to the base, making
     * every threshold harder to reach.
     *
     * @var string[]
     */
    public const ABSTENTION_MODES = ['exclude', 'count'];

    /**
     * Tie-break rules for a simple-majority tie: 'rejected' (default — the
     * motion fails, preserving the status quo), 'chair-decides' (result stays
     * 'tied' until the chair re-runs close with an explicit casting vote),
     * 'revote' (result stays 'tied'; the round may be reopened exactly once).
     *
     * @var string[]
     */
    public const TIE_BREAK_RULES = ['rejected', 'chair-decides', 'revote'];

    /**
     * Fail-closed preflight for opening a round (rules, revote guard, presets).
     *
     * @var VotingRoundPreflight
     */
    private readonly VotingRoundPreflight $preflight;

    /**
     * Parliamentary amendment ordering + subject/meeting resolution.
     *
     * @var AmendmentOrderService
     */
    private readonly AmendmentOrderService $amendmentOrder;

    /**
     * Fail-soft announcements for a freshly opened round.
     *
     * @var VotingOpenedNotifier
     */
    private readonly VotingOpenedNotifier $notifier;

    /**
     * Pure, rule-aware result computation.
     *
     * @var VotingResultCalculator
     */
    private readonly VotingResultCalculator $calculator;

    /**
     * Exact-id scoping for OpenRegister relation-filtered result sets.
     *
     * @var ObjectRelationFilter
     */
    private readonly ObjectRelationFilter $relationFilter;

    /**
     * The cast-a-vote path (round guard, proxy rules, dedup, ballot write).
     *
     * @var VoteCastingService
     */
    private readonly VoteCastingService $caster;

    /**
     * The close-a-round path (closedAt, subject lifecycle, ORI, anonymisation).
     *
     * @var VotingRoundCloser
     */
    private readonly VotingRoundCloser $closer;

    /**
     * The unauthenticated public projection view of a round.
     *
     * @var VotingRoundProjection
     */
    private readonly VotingRoundProjection $projection;

    /**
     * Constructor for VotingService.
     *
     * @param ContainerInterface     $container           The DI container
     * @param LoggerInterface        $logger              The logger
     * @param OriPublicationService  $oriService          The ORI publication service
     * @param MotionService          $motionService       The motion service for lifecycle transitions
     * @param ParticipantResolver    $participantResolver Participant resolver for meeting-based membership checks
     * @param ProcessTemplateService $templateService     Resolves a body's template voting-rule defaults (process-configuration)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        LoggerInterface $logger,
        OriPublicationService $oriService,
        MotionService $motionService,
        private readonly ParticipantResolver $participantResolver,
        ProcessTemplateService $templateService,
    ) {
        $this->preflight = new VotingRoundPreflight(
            container: $container,
            logger: $logger,
            motionService: $motionService,
            participantResolver: $participantResolver,
            templateService: $templateService
        );

        $this->amendmentOrder = new AmendmentOrderService(
            container: $container,
            motionService: $motionService
        );

        $this->notifier = new VotingOpenedNotifier(
            container: $container,
            logger: $logger,
            participantResolver: $participantResolver
        );

        $this->calculator     = new VotingResultCalculator();
        $this->relationFilter = new ObjectRelationFilter();

        $this->caster = new VoteCastingService(
            container: $container,
            logger: $logger,
            participantResolver: $participantResolver,
            amendmentOrder: $this->amendmentOrder,
            relationFilter: $this->relationFilter
        );

        $this->closer = new VotingRoundCloser(
            container: $container,
            logger: $logger,
            oriService: $oriService,
            motionService: $motionService,
            amendmentOrder: $this->amendmentOrder,
            relationFilter: $this->relationFilter
        );

        $this->projection = new VotingRoundProjection(container: $container);

    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Normalise the result of ObjectService::saveObject() to an array.
     *
     * The saveObject() call returns an OpenRegister ObjectEntity; methods declaring a
     * `: array` return type must serialise it (otherwise PHP raises a TypeError).
     * Falls back to the original payload when the saved value is neither an
     * ObjectEntity nor an array.
     *
     * @param mixed                $saved    The value returned by saveObject().
     * @param array<string, mixed> $fallback The original object payload.
     *
     * @return array<string, mixed> The persisted object as an array.
     */
    private function normaliseSaved(mixed $saved, array $fallback): array
    {
        if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
            return $saved->jsonSerialize();
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        return $fallback;

    }//end normaliseSaved()

    /**
     * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
     *
     * Queries the participant register by nextcloudUserId field. Returns null
     * when no matching participant object is found.
     *
     * @param string $nextcloudUid The Nextcloud user login name (UID)
     *
     * @return string|null The participant object UUID, or null if not found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function resolveParticipantUuid(string $nextcloudUid): ?string
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $entities = $objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

        foreach ($entities as $participantEntity) {
            $participant = $participantEntity->jsonSerialize();
            return ($participant['uuid'] ?? $participant['id'] ?? null);
        }

        return null;

    }//end resolveParticipantUuid()

    /**
     * Check whether quorum is met for a given meeting.
     *
     * Counts Participants whose leftAt is null (active) in the GovernanceBody,
     * and compares against Meeting.quorumRequired.
     *
     * @param string $meetingId The meeting UUID
     *
     * @return bool True if quorum is met or quorumRequired is null/0
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->objectService();
        $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        $meeting       = null;
        if ($meetingEntity !== null) {
            $meeting = $meetingEntity->jsonSerialize();
        }

        if ($meeting === null) {
            return false;
        }

        $quorumRequired = (int) ($meeting['quorumRequired'] ?? 0);
        if ($quorumRequired === 0) {
            return true;
        }

        // Count active participants (leftAt is null) via the shared
        // ParticipantResolver, which resolves the meeting → governance-body link
        // and the participant memberships from BOTH the structured relation list
        // and the flat field-keyed relation map ('@self.relations.governanceBody')
        // produced by the standard OpenRegister object API. The previous inline
        // logic read '$meeting["relations"]' as a structured list and filtered on
        // '_relations.governance-body', neither of which matches OR-object-API
        // data, so it always counted 0 active participants and failed closed.
        $participants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);

        $activeCount = 0;
        foreach ($participants as $participant) {
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a VotingRound, optionally with preset participant UUIDs.
     *
     * Parliamentary ordering (motion-amendment spec, fail closed):
     * - subjectType 'motion': rejected while any amendment of the motion is
     *   still in lifecycle submitted/debating/voting — amendments are voted
     *   before the main motion.
     * - subjectType 'amendment': $motionId is the AMENDMENT UUID; rejected
     *   when a sibling amendment earlier in the configured order (votingOrder
     *   ascending, unordered last by submittedAt) is still undecided.
     *
     * @param string                $motionId             The motion UUID (the amendment UUID when subjectType is 'amendment')
     * @param string                $meetingId            The meeting UUID
     * @param string                $votingMethod         The voting method (for-against-abstain, show-of-hands, etc.)
     * @param bool                  $isSecret             Whether the ballot is secret
     * @param string|null           $closedAt             Optional pre-defined close time
     * @param array<string>         $presetParticipantIds Optional array of participant UUIDs for a voting group preset
     * @param string|null           $revoteOfRoundId      UUID of a tied round this round is the single permitted revote of
     * @param VotingRoundRules|null $roundRules           The configurable decision rules (threshold / abstention /
     *                                                    tie-break / subject type / opening body); null = all defaults
     *
     * @return array<string,mixed> The created voting round object with excludedPresetUuids key if any UUIDs were excluded
     *
     * @throws RuntimeException         When quorum is not met, the revote guard fails, the amendment ordering rule is violated,
     *                                   or lifecycle transition fails
     * @throws InvalidArgumentException When a rule or subjectType value is not in its enum (fail closed)
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     */
    public function openVotingRound(
        string $motionId,
        string $meetingId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        array $presetParticipantIds=[],
        ?string $revoteOfRoundId=null,
        ?VotingRoundRules $roundRules=null,
    ): array {
        $roundRules  = ($roundRules ?? new VotingRoundRules());
        $subjectType = $roundRules->subjectType;

        // Process-configuration: resolution order per rule is caller value (non-null) ->
        // body template default -> built-in default. The caller (controller) always passes
        // explicit values, so it always wins; the template only fills nulls. Unknown rule
        // values are rejected, never silently defaulted.
        $rules = $this->preflight->resolveRules(
            governanceBodyId: $roundRules->governanceBodyId,
            voteThreshold: $roundRules->voteThreshold,
            abstentionHandling: $roundRules->abstentionHandling,
            tieBreakRule: $roundRules->tieBreakRule,
            subjectType: $subjectType
        );

        $quorumMet = $this->checkQuorum(meetingId: $meetingId);
        if ($quorumMet === false) {
            throw new RuntimeException('Quorum niet bereikt');
        }

        // Revote-once guard: the referenced round must be a tied revote-rule round
        // that has not been revoted before (fail closed on every mismatch).
        if ($revoteOfRoundId !== null) {
            $this->preflight->assertRevoteAllowed(revoteOfRoundId: $revoteOfRoundId);
        }

        // Parliamentary ordering (motion-amendment spec) and the lifecycle
        // transition below apply to fresh rounds only: a revote re-opens a
        // question that was already in order and never left 'voting'.
        $isFreshRound = ($revoteOfRoundId === null);
        if ($isFreshRound === true) {
            $this->amendmentOrder->assertOrdering(subjectId: $motionId, subjectType: $subjectType);
        }

        // Preset UUIDs are validated against active memberships; the eligible
        // ones become participant relations on the round.
        $presets     = $this->preflight->splitPresetParticipants(meetingId: $meetingId, presetIds: $presetParticipantIds);
        $votingRound = $this->preflight->buildRoundPayload(
            motionId: $motionId,
            subjectType: $subjectType,
            votingMethod: $votingMethod,
            isSecret: $isSecret,
            closedAt: $closedAt,
            quorumMet: $quorumMet,
            rules: $rules,
            revoteOfRoundId: $revoteOfRoundId,
            participantIds: $presets['eligible']
        );

        $created = $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $votingRound);

        if ($isFreshRound === true) {
            $this->preflight->transitionSubjectToVoting(subjectId: $motionId, subjectType: $subjectType);
        }

        // ObjectService::saveObject() returns an ObjectEntity; normalise to an array so the
        // declared `: array` return type holds and callers can subscript the result.
        $result = $this->normaliseSaved(saved: $created, fallback: $votingRound);

        if (count($presets['excluded']) > 0) {
            $result['excludedPresetUuids'] = $presets['excluded'];
        }

        // Fail-soft announcements: the activity-feed entry and the
        // preference-aware "pending vote" notifications (user-settings spec),
        // fanned out to each participant's active absence delegate.
        $this->notifier->announce(round: $result, motionId: $motionId, meetingId: $meetingId, closedAt: $closedAt, subjectType: $subjectType);

        return $result;

    }//end openVotingRound()

    /**
     * Cast a vote in a VotingRound.
     *
     * Delegates to VoteCastingService, which owns the round-state guard, the
     * meeting-membership check (#300), the proxy rules and the idempotent
     * ballot write.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The participant UUID
     * @param string      $value         for | against | abstain
     * @param bool        $isProxy       True when the participant is voting as proxy for another
     * @param string|null $delegatorId   The participant UUID being delegated (required when isProxy=true)
     * @param string|null $callerUid     The authenticated Nextcloud UID of the casting user (used only
     *                                   to detect an absence delegation when no formal proxy exists —
     *                                   delegations are configured by NC UID in the user settings)
     *
     * @return array<string,mixed> The created/updated Vote object
     *
     * @throws RuntimeException When the round is not open, the caller is not a meeting member,
     *                           or proxy rules are violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     * @spec openspec/specs/user-settings/spec.md
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
        ?string $callerUid=null,
    ): array {
        return $this->caster->castVote(
            votingRoundId: $votingRoundId,
            participantId: $participantId,
            value: $value,
            isProxy: $isProxy,
            delegatorId: $delegatorId,
            callerUid: $callerUid
        );

    }//end castVote()

    /**
     * Close a VotingRound, optionally anonymising vote values.
     *
     * When $chairCasting is provided, it is the chair's explicit casting vote
     * resolving a tie under tieBreakRule 'chair-decides': the value is persisted
     * as chairCastingVote on the round BEFORE the tally so computeResult() can
     * resolve the tie, and the audit trail records how it was broken. Fail
     * closed: a casting vote on a round whose tie-break rule is not
     * 'chair-decides', or with a value other than for/against, is refused.
     * Caller-side chair authorization is enforced by VotingController::close().
     *
     * The ordering below is load-bearing and is the reason this method still
     * exists rather than living entirely in VotingRoundCloser: the casting vote
     * must be persisted BEFORE the tally reads it, and the tally must run
     * BEFORE the round is stamped closed.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param bool        $anonymise     Whether to nullify individual vote values (GDPR anonymisation)
     * @param string|null $chairCasting  Optional chair casting vote ('for'|'against') resolving a tie
     *
     * @return array<string,mixed> The closed voting round object
     *
     * @throws RuntimeException When the casting vote is not permitted (fail closed)
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function closeVotingRound(string $votingRoundId, bool $anonymise=false, ?string $chairCasting=null): array
    {
        if ($chairCasting !== null) {
            $this->closer->applyChairCastingVote(votingRoundId: $votingRoundId, chairCasting: $chairCasting);
        }

        return $this->closer->close(
            votingRoundId: $votingRoundId,
            anonymise: $anonymise,
            tally: $this->tallyResults(votingRoundId: $votingRoundId)
        );

    }//end closeVotingRound()

    /**
     * Compute the rule-aware voting result for a set of counts.
     *
     * Formula (F = for, A = against, B = abstain, all weighted; see the
     * voting-system spec delta for the legal worked examples):
     *
     * - base = F + A ('exclude', default) or F + A + B ('count' — abstentions
     *   make every threshold harder).
     * - T == 0 -> 'invalid'.
     * - Tie (simple-majority only, F == A and F > 0) -> tieBreakRule applies:
     *   'rejected' -> 'rejected' (motion fails, status quo); 'chair-decides' ->
     *   'tied' until the round carries a chairCastingVote; 'revote' -> 'tied'.
     * - base == 0 -> 'rejected' (nothing can carry; guards unanimous vacuity).
     * - simple-majority: adopted iff 2F > base (strict "50%+1").
     * - qualified-majority-two-thirds: adopted iff 3F >= 2*base.
     * - qualified-majority-three-quarters: adopted iff 4F >= 3*base.
     * - unanimous: adopted iff F == base.
     *
     * Integer math throughout — no float threshold comparisons.
     *
     * @param int                 $for     Weighted for-votes
     * @param int                 $against Weighted against-votes
     * @param int                 $abstain Weighted abstentions
     * @param array<string,mixed> $round   The voting round (rules + chairCastingVote are read from it)
     *
     * @return array{result: string, base: int, voteThreshold: string, abstentionHandling: string, tieBreakRule: string}
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function computeResult(int $for, int $against, int $abstain, array $round): array
    {
        return $this->calculator->compute(for: $for, against: $against, abstain: $abstain, round: $round);

    }//end computeResult()

    /**
     * Tally all votes in a VotingRound and update the round with counts and result.
     *
     * The result honours the round's configured voteThreshold, abstentionHandling
     * and tieBreakRule (see computeResult()). The applied rules and the computed
     * base are persisted on the round alongside the counts for auditability.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed> Tally with votesFor, votesAgainst, votesAbstain, total, base, result and applied rules
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     * @spec openspec/specs/voting-system/spec.md
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->objectService();

        // Load the round first — the configured rules drive the result computation.
        $roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round       = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');
        $voteEntities = $this->relationFilter->matching(
            entities: $objectService->findAll(['filters' => ['_relations.voting-round' => $votingRoundId]]),
            schema: 'voting-round',
            targetId: $votingRoundId
        );

        $for     = 0;
        $against = 0;
        $abstain = 0;

        foreach ($voteEntities as $voteEntity) {
            $vote   = $voteEntity->jsonSerialize();
            $val    = ($vote['value'] ?? '');
            $weight = (int) ($vote['weight'] ?? 1);
            if ($val === 'for') {
                $for += $weight;
            } else if ($val === 'against') {
                $against += $weight;
            } else if ($val === 'abstain') {
                $abstain += $weight;
            }
        }

        $total    = ($for + $against + $abstain);
        $computed = $this->computeResult(for: $for, against: $against, abstain: $abstain, round: ($round ?? []));
        $result   = $computed['result'];

        // Update VotingRound with tally + the applied rules and base (audit trail).
        if ($round !== null) {
            $round['votesFor']      = $for;
            $round['votesAgainst']  = $against;
            $round['votesAbstain']  = $abstain;
            $round['result']        = $result;
            $round['voteBase']      = $computed['base'];
            $round['voteThreshold'] = $computed['voteThreshold'];
            $round['abstentionHandling'] = $computed['abstentionHandling'];
            $round['tieBreakRule']       = $computed['tieBreakRule'];
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        return [
            'votesFor'           => $for,
            'votesAgainst'       => $against,
            'votesAbstain'       => $abstain,
            'total'              => $total,
            'base'               => $computed['base'],
            'voteThreshold'      => $computed['voteThreshold'],
            'abstentionHandling' => $computed['abstentionHandling'],
            'tieBreakRule'       => $computed['tieBreakRule'],
            'result'             => $result,
        ];

    }//end tallyResults()

    /**
     * Record a show-of-hands tally for an open VotingRound.
     *
     * Only valid for rounds with votingMethod == 'show-of-hands'.
     * Saves the chair-entered counts directly as aggregate totals and computes result.
     *
     * @param string $votingRoundId The voting round UUID
     * @param int    $votesFor      Count of raised hands for
     * @param int    $votesAgainst  Count of raised hands against
     * @param int    $votesAbstain  Count of abstentions
     *
     * @return array<string,mixed> Updated VotingRound data
     *
     * @throws RuntimeException When the round is not found or is not a show-of-hands round
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function saveShowOfHandsTally(string $votingRoundId, int $votesFor, int $votesAgainst, int $votesAbstain): array
    {
        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            throw new RuntimeException("VotingRound $votingRoundId not found");
        }

        if (($round['votingMethod'] ?? '') !== 'show-of-hands') {
            throw new RuntimeException('saveShowOfHandsTally is only valid for show-of-hands rounds');
        }

        // #302: Validate submitted counts against the actual participant count for the meeting.
        // The round relates to a motion (or an amendment, resolved through its parent
        // motion), which relates to a meeting; count active participants.
        $meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);

        if ($meetingId !== null) {
            // Count only active participants (leftAt is null) via canonical resolver.
            $meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
            $activeCount         = 0;
            foreach ($meetingParticipants as $pd) {
                if (($pd['leftAt'] ?? null) === null) {
                    $activeCount++;
                }
            }

            $submittedTotal = ($votesFor + $votesAgainst + $votesAbstain);
            if ($activeCount > 0 && $submittedTotal > $activeCount) {
                throw new RuntimeException(
                    "Ingevoerde tellingen ({$submittedTotal}) overschrijden het aantal actieve deelnemers ({$activeCount})"
                );
            }
        }//end if

        // Rule-aware result: show-of-hands rounds honour the same configured
        // threshold / abstention / tie-break rules as ballot rounds.
        $computed = $this->computeResult(
            for: $votesFor,
            against: $votesAgainst,
            abstain: $votesAbstain,
            round: $round
        );

        $round['votesFor']      = $votesFor;
        $round['votesAgainst']  = $votesAgainst;
        $round['votesAbstain']  = $votesAbstain;
        $round['result']        = $computed['result'];
        $round['voteBase']      = $computed['base'];
        $round['voteThreshold'] = $computed['voteThreshold'];
        $round['abstentionHandling'] = $computed['abstentionHandling'];
        $round['tieBreakRule']       = $computed['tieBreakRule'];

        $saved = $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        // The saveObject() call returns an ObjectEntity; normalise to satisfy the `: array` return type.
        return $this->normaliseSaved(saved: $saved, fallback: $round);

    }//end saveShowOfHandsTally()

    /**
     * Get public-state for a VotingRound for projection display.
     *
     * Delegates to VotingRoundProjection, which owns the #303 visibility rules:
     * a secret ballot and an unpublished round both read as not-found to an
     * anonymous caller, so neither leaks even aggregate counts.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed>|null The public-state array or null if not found / not accessible
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
     */
    public function getPublicState(string $votingRoundId): ?array
    {
        return $this->projection->publicState(votingRoundId: $votingRoundId);

    }//end getPublicState()
}//end class
