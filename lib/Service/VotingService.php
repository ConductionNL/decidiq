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
     * Constructor for VotingService.
     *
     * @param ContainerInterface     $container             The DI container
     * @param LoggerInterface        $logger                The logger
     * @param OriPublicationService  $oriPublicationService The ORI publication service
     * @param MotionService          $motionService         The motion service for lifecycle transitions
     * @param ParticipantResolver    $participantResolver   Participant resolver for meeting-based membership checks
     * @param ProcessTemplateService $templateService       Resolves a body's template voting-rule defaults (process-configuration)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly OriPublicationService $oriPublicationService,
        private readonly MotionService $motionService,
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

        $this->calculator = new VotingResultCalculator();

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
     * Scope a relation-filtered result set down to a specific related object id.
     *
     * The OpenRegister `_relations.<schema>` filter matches any object that
     * carries a relation of that schema — it does NOT scope by the related id
     * (the filter value is ignored). Tally/quorum/dedup logic needs an exact
     * match, so this helper re-checks each returned object's relations and keeps
     * only those that actually reference $targetId. Both the structured
     * (`{"relations.N.id": "...", "relations.N.schema": "..."}`) and the legacy
     * flat (`{"<field>": "<id>"}`) relation shapes are honoured.
     *
     * @param array<int, mixed> $entities The ObjectEntity result set from findAll().
     * @param string            $schema   The related schema slug (e.g. 'voting-round').
     * @param string            $targetId The related object UUID that must be referenced.
     *
     * @return array<int, mixed> Entities that genuinely reference $targetId.
     */
    private function filterByRelation(array $entities, string $schema, string $targetId): array
    {
        $matched = [];
        foreach ($entities as $entity) {
            $object    = $entity->jsonSerialize();
            $relations = ($object['@self']['relations'] ?? ($object['relations'] ?? []));
            if (is_array($relations) === false) {
                continue;
            }

            if ($this->relationsReference(relations: $relations, schema: $schema, targetId: $targetId) === true) {
                $matched[] = $entity;
            }
        }

        return $matched;

    }//end filterByRelation()

    /**
     * Determine whether a serialised relations structure references $targetId.
     *
     * @param array<mixed> $relations The object's relations structure.
     * @param string       $schema    The expected related schema slug.
     * @param string       $targetId  The related UUID to look for.
     *
     * @return bool True when $targetId is referenced by the relations.
     */
    private function relationsReference(array $relations, string $schema, string $targetId): bool
    {
        // Structured list form: [{ 'id' => ..., 'schema' => ... }, ...].
        foreach ($relations as $value) {
            if (is_array($value) === true) {
                $relSchema = ($value['schema'] ?? null);
                $relId     = ($value['id'] ?? null);
                if ($relId === $targetId && ($relSchema === null || $relSchema === $schema)) {
                    return true;
                }

                continue;
            }

            // Flat scalar form: '<field>' => '<id>' or 'relations.N.id' => '<id>'.
            if (is_string($value) === true && $value === $targetId) {
                return true;
            }
        }

        return false;

    }//end relationsReference()

    /**
     * Resolve OpenRegister FileService.
     *
     * @return object
     */
    private function fileService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');

    }//end fileService()

    /**
     * Return the per-app HMAC secret for secret-ballot voter token generation.
     *
     * The secret is generated once with random_bytes() and persisted in app config
     * so that the HMAC is stable across requests while remaining server-side only.
     * Using HMAC instead of a bare SHA-256 hash means the mapping from
     * (participantId, votingRoundId) → voterToken cannot be computed without
     * knowledge of this secret, preventing store-admin-level ballot de-anonymisation.
     *
     * @return string 64-character hex secret
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    private function voterTokenSecret(): string
    {
        $appConfig = $this->container->get(\OCP\IAppConfig::class);
        $secret    = $appConfig->getValueString('decidesk', 'voter_token_secret', '');
        if ($secret === '') {
            // The `sensitive: true` flag below is required — see InitializeSettings. This
            // lazy path exists for the first call before the repair step has run; it must
            // flag the key too, or a fresh instance writes it in cleartext.
            $secret = bin2hex(random_bytes(32));
            $appConfig->setValueString('decidesk', 'voter_token_secret', $secret, sensitive: true);
        }

        return $secret;

    }//end voterTokenSecret()

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
     * Check whether the casting participant is the configured absence delegate
     * of the claimed delegator (user-settings spec).
     *
     * Matches the stored delegate identifier against both the caster's
     * participant UUID and their Nextcloud UID (the settings UI stores NC
     * UIDs). Fail-closed for the gate's purpose: when the preference service
     * is unavailable the method returns false and the caller falls back to
     * the generic no-proxy rejection — the vote is denied either way.
     *
     * @param string      $delegatorId   The claimed delegator (participant UUID or NC UID)
     * @param string      $participantId The casting participant UUID
     * @param string|null $callerUid     The casting user's Nextcloud UID, when known
     *
     * @return bool
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function hasAbsenceDelegation(string $delegatorId, string $participantId, ?string $callerUid): bool
    {
        try {
            $prefService = $this->container->get(NotificationPreferenceService::class);
            if ($prefService instanceof NotificationPreferenceService === false) {
                return false;
            }

            if ($prefService->hasActiveDelegationTo(delegatorId: $delegatorId, delegateId: $participantId) === true) {
                return true;
            }

            if ($callerUid !== null && $callerUid !== '') {
                return $prefService->hasActiveDelegationTo(delegatorId: $delegatorId, delegateId: $callerUid);
            }
        } catch (Throwable $e) {
            // Both outcomes deny the vote; this only selects the error text.
            $this->logger->debug('Decidesk: delegation consult failed', ['error' => $e->getMessage()]);
        }

        return false;

    }//end hasAbsenceDelegation()

    /**
     * Resolve the attendance mode to stamp on a vote (remote-vote annotation).
     *
     * Honest recording only — reads the casting participant's participantType
     * ('in-person' | 'remote') and returns it; 'unknown' when the participant
     * cannot be resolved or the field is unset. No session-verification theater.
     * Carries no identity, so it is stamped on secret-ballot votes too.
     *
     * @param string $participantId The casting participant UUID
     *
     * @return string 'in-person' | 'remote' | 'unknown'
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function resolveCastAs(string $participantId): string
    {
        try {
            $participantEntity = $this->objectService()->find(id: $participantId, register: 'decidesk', schema: 'participant');
            if ($participantEntity !== null) {
                $participant = $participantEntity->jsonSerialize();
                $type        = ($participant['participantType'] ?? null);
                if (in_array($type, ['in-person', 'remote'], true) === true) {
                    return $type;
                }
            }
        } catch (Throwable $e) {
            $this->logger->debug('Decidesk: castAs participant lookup failed', ['error' => $e->getMessage()]);
        }

        return 'unknown';

    }//end resolveCastAs()

    /**
     * Cast a vote in a VotingRound.
     *
     * Checks the round is open, verifies the participant is a member of the
     * meeting that owns the round (#300), prevents duplicates (overwrites
     * existing vote), and enforces one-proxy-per-round for proxy votes.
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
        $objectService = $this->objectService();

        $roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round       = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            throw new RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['closedAt'] ?? null) !== null && strtotime($round['closedAt']) < time()) {
            throw new RuntimeException('Stemronde is gesloten');
        }

        if (($round['openedAt'] ?? null) === null) {
            throw new RuntimeException('Stemronde is nog niet geopend');
        }

        // #300: Verify the participant is actually a member of the meeting that owns this round.
        // The round is linked to a Motion (or an Amendment, which resolves through its
        // parent motion); the Motion is linked to a Meeting via its relations.
        $meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);

        if ($meetingId !== null) {
            // Verify the participant belongs to the meeting's governance body.
            $meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
            $memberIds           = array_column($meetingParticipants, 'id');
            if (in_array($participantId, $memberIds, true) === false) {
                throw new RuntimeException('Deelnemer is geen lid van de vergadering');
            }
        }

        $isSecret = (bool) ($round['isSecret'] ?? false);

        // For proxy votes: verify the casting participant holds a granted proxy from the claimed delegator.
        if ($isProxy === true && $delegatorId !== null) {
            $proxyGrantFound = false;
            foreach (($round['notes'] ?? []) as $note) {
                if (($note['title'] ?? '') !== 'Proxy') {
                    continue;
                }

                $body = json_decode($note['body'] ?? '{}', true);
                if (($body['fromParticipantId'] ?? '') === $delegatorId && ($body['toParticipantId'] ?? '') === $participantId) {
                    $proxyGrantFound = true;
                    break;
                }
            }

            if ($proxyGrantFound === false) {
                // User-settings spec — "Delegate cannot vote without explicit
                // proxy": an absence delegation (configured in personal
                // settings) covers notifications and read access only. When
                // the caster IS the configured absence delegate of the claimed
                // delegator, reject with the spec-mandated message plus a
                // pointer to the formal proxy (volmacht) granting process.
                // The existing proxy-grant check above stays authoritative;
                // this only improves the rejection for the delegation case.
                if ($this->hasAbsenceDelegation(delegatorId: $delegatorId, participantId: $participantId, callerUid: $callerUid) === true) {
                    throw new RuntimeException(
                        'Delegation does not include voting rights. A formal proxy (volmacht) is required for voting. '
                        .'Grant one via the voting round proxy process (POST /apps/decidesk/api/voting-rounds/{id}/proxy).'
                    );
                }

                throw new RuntimeException('Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever');
            }

            // Enforce one-proxy-per-round: check for existing proxy vote from this delegator.
            // For secret rounds, participant relations are suppressed for anonymity, so dedup
            // is keyed on a deterministic delegatorToken (HMAC) to avoid DNS-style rebinding.
            if ($isSecret === true) {
                $delegatorToken = hash_hmac('sha256', $delegatorId.':proxy:'.$votingRoundId, $this->voterTokenSecret());
                $objectService->setRegister('decidesk');
                $objectService->setSchema('vote');
                $existingProxyEntities = $this->filterByRelation(
                    entities: $objectService->findAll(
                        [
                            'filters' => [
                                '_relations.voting-round' => $votingRoundId,
                                'delegatorToken'          => $delegatorToken,
                            ],
                        ]
                    ),
                    schema: 'voting-round',
                    targetId: $votingRoundId
                );
                if (empty($existingProxyEntities) === false) {
                    throw new RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
                }
            } else {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('vote');
                $existingProxyEntities = $this->filterByRelation(
                    entities: $objectService->findAll(
                        [
                            'filters' => [
                                '_relations.voting-round' => $votingRoundId,
                                'isProxy'                 => true,
                            ],
                        ]
                    ),
                    schema: 'voting-round',
                    targetId: $votingRoundId
                );

                foreach ($existingProxyEntities as $proxyVoteEntity) {
                    $proxyVote = $proxyVoteEntity->jsonSerialize();
                    foreach (($proxyVote['relations'] ?? []) as $rel) {
                        if (($rel['schema'] ?? '') === 'participant' && ($rel['id'] ?? '') === $delegatorId && ($rel['type'] ?? '') === 'delegator') {
                            throw new RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
                        }
                    }
                }
            }//end if
        }//end if

        // Check for existing vote — overwrite if found.
        // For secret rounds the participant relation is suppressed for anonymity,
        // so dedup is keyed on a deterministic voterToken instead.
        if ($isSecret === true) {
            $voterToken = hash_hmac('sha256', $participantId.':'.$votingRoundId, $this->voterTokenSecret());
            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            $existingVoteEntities = $this->filterByRelation(
                entities: $objectService->findAll(
                    [
                        'filters' => [
                            '_relations.voting-round' => $votingRoundId,
                            'voterToken'              => $voterToken,
                        ],
                    ]
                ),
                schema: 'voting-round',
                targetId: $votingRoundId
            );
        } else {
            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            // Both _relations filters are schema-presence-only in OR, so scope to
            // this round first, then to this participant, to get an exact dedup match.
            $existingVoteEntities = $this->filterByRelation(
                entities: $this->filterByRelation(
                    entities: $objectService->findAll(
                        [
                            'filters' => [
                                '_relations.voting-round' => $votingRoundId,
                                '_relations.participant'  => $participantId,
                            ],
                        ]
                    ),
                    schema: 'voting-round',
                    targetId: $votingRoundId
                ),
                schema: 'participant',
                targetId: $participantId
            );
        }//end if

        $existingVote = null;
        foreach ($existingVoteEntities as $vEntity) {
            $existingVote = $vEntity->jsonSerialize();
            break;
        }

        $relations = [
            ['register' => 'decidesk', 'schema' => 'voting-round', 'id' => $votingRoundId],
        ];

        // For non-secret rounds, link the vote to the casting participant.
        // For secret rounds, omit the participant relation to preserve anonymity.
        if ($isSecret === false) {
            $relations[] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $participantId];

            if ($isProxy === true && $delegatorId !== null) {
                $relations[] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $delegatorId, 'type' => 'delegator'];
            }
        }

        // Build the vote object.
        // Idempotency: set a deterministic @self.slug so that concurrent castVote requests
        // for the same (participant, round) result in an upsert rather than two inserts.
        // - Secret rounds:     slug = voterToken (HMAC, already opaque)
        // - Non-secret rounds: slug = "vote-{votingRoundId}-{participantId}" (truncated)
        // OR's saveObject with a matching slug performs UPDATE rather than INSERT, so the
        // second concurrent request safely overwrites the first with the same value.
        if ($isSecret === true) {
            $idempotencySlug = hash_hmac('sha256', $participantId.':'.$votingRoundId, $this->voterTokenSecret());
        } else {
            // Slugs must be URL-safe and <= 255 chars.
            $idempotencySlug = 'vote-'.substr($votingRoundId, 0, 8).'-'.substr($participantId, 0, 8);
            if ($isProxy === true && $delegatorId !== null) {
                $idempotencySlug .= '-proxy-'.substr($delegatorId, 0, 8);
            }
        }

        $vote = [
            '@self'     => ['slug' => $idempotencySlug],
            'value'     => $value,
            'weight'    => 1,
            'isProxy'   => $isProxy,
            'castAt'    => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'castAs'    => $this->resolveCastAs(participantId: $participantId),
            'relations' => $relations,
        ];

        // Store opaque dedup token for secret rounds (never contains participant identity).
        if ($isSecret === true) {
            $vote['voterToken'] = $idempotencySlug;
        }

        // Store delegatorToken on secret proxy votes for one-proxy-per-round enforcement
        // without storing the delegator's participant ID (anonymity preservation).
        if ($isSecret === true && $isProxy === true && $delegatorId !== null) {
            $vote['delegatorToken'] = hash_hmac('sha256', $delegatorId.':proxy:'.$votingRoundId, $this->voterTokenSecret());
        }

        if ($existingVote !== null) {
            $vote['id']   = ($existingVote['id'] ?? null);
            $vote['uuid'] = ($existingVote['uuid'] ?? null);
        }

        $saved = $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);

        // The saveObject() call returns an ObjectEntity; normalise to satisfy the `: array` return type.
        return $this->normaliseSaved(saved: $saved, fallback: $vote);

    }//end castVote()

    /**
     * Validate and persist the chair's casting vote on a tied round (fail closed).
     *
     * Only permitted when the round exists, its tieBreakRule is 'chair-decides',
     * and the value is 'for' or 'against'. Persisted as chairCastingVote so the
     * subsequent tally resolves the tie and the audit trail shows the resolution.
     *
     * @param string $votingRoundId The voting round UUID
     * @param string $chairCasting  The casting vote value ('for'|'against')
     *
     * @return void
     *
     * @throws RuntimeException When the casting vote is not permitted
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function applyChairCastingVote(string $votingRoundId, string $chairCasting): void
    {
        if (in_array($chairCasting, ['for', 'against'], true) === false) {
            throw new RuntimeException("Casting vote refused: value must be 'for' or 'against'");
        }

        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            throw new RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['tieBreakRule'] ?? 'rejected') !== 'chair-decides') {
            throw new RuntimeException("Casting vote refused: this round's tie-break rule is not 'chair-decides'");
        }

        $round['chairCastingVote'] = $chairCasting;
        $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

    }//end applyChairCastingVote()

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
            $this->applyChairCastingVote(votingRoundId: $votingRoundId, chairCasting: $chairCasting);
        }

        $tally = $this->tallyResults(votingRoundId: $votingRoundId);

        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round !== null && ($round['closedAt'] ?? null) === null) {
            $round['closedAt'] = (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        $result = ($tally['result'] ?? 'invalid');

        // Transition subject (motion or amendment) lifecycle based on result.
        // #318: Re-throw InvalidArgumentException (bad state-machine transition) so the
        // caller learns the round was closed but the subject lifecycle could not be updated.
        // Transient/infrastructure errors are still logged-and-continued so a network hiccup
        // does not leave the round un-closed; however they are logged at ERROR level so they
        // surface in monitoring rather than silently disappearing.
        if ($round !== null) {
            $subjectType = null;
            $subjectId   = null;
            foreach (($round['relations'] ?? []) as $rel) {
                $relSchema = ($rel['schema'] ?? '');
                if ($relSchema === 'motion' || $relSchema === 'amendment') {
                    $subjectType = $relSchema;
                    $subjectId   = ($rel['id'] ?? null);
                    break;
                }
            }

            if ($subjectType !== null && $subjectId !== null) {
                // Only transition to defined terminal states via the guarded state machine.
                $subjectLifecycle = match ($result) {
                    'adopted'  => 'adopted',
                    'rejected' => 'rejected',
                    default    => null,
                };

                if ($subjectLifecycle !== null) {
                    try {
                        $this->motionService->transitionLifecycle(
                            objectId: $subjectId,
                            objectType: $subjectType,
                            newState: $subjectLifecycle,
                            actorId: 'system',
                        );

                        // Create dossier folder if an adopted MOTION.
                        if ($subjectType === 'motion' && $subjectLifecycle === 'adopted') {
                            $motionEntity = $objectService->find(id: $subjectId, register: 'decidesk', schema: 'motion');
                            $motion       = null;
                            if ($motionEntity !== null) {
                                $motion = $motionEntity->jsonSerialize();
                            }

                            $motionTitle = (string) ($motion['title'] ?? $subjectId);
                            $this->createDossierFolder(motionId: $subjectId, motionTitle: $motionTitle);
                        }

                        // Adopted AMENDMENT: incorporate it into the parent motion text
                        // (motion-amendment spec — "the final motion text MUST incorporate
                        // all adopted amendments"). Fail-soft: a text-merge failure must
                        // not undo the recorded vote result.
                        if ($subjectType === 'amendment' && $subjectLifecycle === 'adopted') {
                            $this->incorporateAdoptedAmendment(amendmentId: $subjectId);
                        }
                    } catch (InvalidArgumentException $e) {
                        // State-machine violation: re-throw so the caller can surface it.
                        // #318: Previously swallowed silently.
                        throw new RuntimeException(
                            'Stemronde gesloten maar motie kon niet worden bijgewerkt: '.$e->getMessage(),
                            0,
                            $e
                        );
                    } catch (Throwable $e) {
                        // Transient infrastructure failure: log at ERROR level and continue.
                        // #318: Previously logged at WARNING and lost in monitoring noise.
                        $this->logger->error(
                            'Decidesk: lifecycle transition after close failed — round is closed but motion state may be stale',
                            ['votingRoundId' => $votingRoundId, 'motionId' => $subjectId, 'error' => $e->getMessage()]
                        );
                    }//end try
                }//end if
            }//end if
        }//end if

        // Trigger ORI publication.
        // #318: Publication failures are now surfaced to callers when they indicate a real
        // configuration or protocol error (not "endpoint not configured" — that is still
        // swallowed, as `publish()` returns silently when no endpoint is set).
        // Infrastructure/network errors are logged at ERROR level (was INFO) so they surface
        // in monitoring.
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: ORI publication failed after round close',
                ['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
            );
            // Attach ORI error to round data so caller can reflect it in the response.
            if ($round !== null) {
                $round['oriPublicationError'] = $e->getMessage();
            }
        }

        // Anonymise vote values if requested (sequence: tally → publish → anonymise).
        if ($anonymise === true) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('vote');
                $voteEntities = $this->filterByRelation(
                    entities: $objectService->findAll(['filters' => ['_relations.voting-round' => $votingRoundId]]),
                    schema: 'voting-round',
                    targetId: $votingRoundId
                );

                foreach ($voteEntities as $voteEntity) {
                    $vote          = $voteEntity->jsonSerialize();
                    $vote['value'] = null;
                    $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);
                }

                $this->logger->info('Decidesk: votes anonymised', ['votingRoundId' => $votingRoundId]);
            } catch (Throwable $e) {
                $this->logger->warning('Decidesk: vote anonymisation failed', ['error' => $e->getMessage()]);
            }
        }//end if

        return ($round ?? []);

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
        $voteEntities = $this->filterByRelation(
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
     * Create a dossier folder for an adopted motion via FileService.
     *
     * @param string $motionId    The motion UUID
     * @param string $motionTitle The motion title (used to compose folder path)
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    private function createDossierFolder(string $motionId, string $motionTitle): void
    {
        try {
            $fileService = $this->fileService();
            $slug        = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $motionTitle) ?? $motionId);
            $folderPath  = "motions/{$slug}-{$motionId}";
            $fileService->createFolder($folderPath);
            $this->logger->info('Decidesk: dossier folder created', ['path' => $folderPath, 'motionId' => $motionId]);
        } catch (Throwable $e) {
            $this->logger->warning('Decidesk: dossier folder creation failed', ['motionId' => $motionId, 'error' => $e->getMessage()]);
        }

    }//end createDossierFolder()

    /**
     * Incorporate an adopted amendment into its parent motion text (fail-soft).
     *
     * Resolves the amendment's parent motion (flat parentMotion property or
     * structured relation) and delegates to MotionService::applyAmendment(),
     * which appends the amendment as an annotated section of the motion text.
     * Failures are logged and never undo the recorded vote result.
     *
     * @param string $amendmentId The adopted amendment UUID
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function incorporateAdoptedAmendment(string $amendmentId): void
    {
        try {
            $amendmentEntity = $this->objectService()->find(id: $amendmentId, register: 'decidesk', schema: 'amendment');
            if ($amendmentEntity === null) {
                return;
            }

            $amendment      = $amendmentEntity->jsonSerialize();
            $parentMotionId = $this->amendmentOrder->resolveParentMotionId(amendment: $amendment);
            if ($parentMotionId === null) {
                return;
            }

            $this->motionService->applyAmendment(motionId: $parentMotionId, amendmentId: $amendmentId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to incorporate adopted amendment into the parent motion text',
                ['amendmentId' => $amendmentId, 'error' => $e->getMessage()]
            );
        }

    }//end incorporateAdoptedAmendment()

    /**
     * Get public-state for a VotingRound for projection display.
     *
     * Returns aggregate vote counts, preselected option, and no individual vote values.
     * Accessible without authentication.
     *
     * #303: Returns null (treating as not-found) when:
     * - The round has isSecret==true (secret ballots must not leak even aggregate
     *   counts to unauthenticated projection displays until the chair explicitly
     *   publishes results).
     * - The round's lifecycle is not 'published' (draft/closed rounds are not visible
     *   to anonymous callers).
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed>|null The public-state array or null if not found / not accessible
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
     */
    public function getPublicState(string $votingRoundId): ?array
    {
        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            return null;
        }

        // #303: Secret voting rounds must never be surfaced to anonymous projection callers.
        if ((bool) ($round['isSecret'] ?? false) === true) {
            return null;
        }

        // #303: Only rounds that have been explicitly published (lifecycle == 'published') are
        // visible to unauthenticated callers. Draft, open, and closed-but-unpublished rounds
        // must not leak to the public projection endpoint.
        $lifecycle = $round['lifecycle'] ?? $round['status'] ?? '';
        if ($lifecycle !== 'published') {
            return null;
        }

        $motionTitle = '';
        // Find linked motion title.
        foreach (($round['relations'] ?? []) as $rel) {
            if (($rel['schema'] ?? '') === 'motion') {
                $motionId = ($rel['id'] ?? null);
                if ($motionId !== null) {
                    $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
                    $motion       = null;
                    if ($motionEntity !== null) {
                        $motion = $motionEntity->jsonSerialize();
                    }

                    $motionTitle = (string) ($motion['title'] ?? '');
                }

                break;
            }
        }

        // Compute preselected option from vote counts.
        $votesFor     = (int) ($round['votesFor'] ?? 0);
        $votesAgainst = (int) ($round['votesAgainst'] ?? 0);
        $votesAbstain = (int) ($round['votesAbstain'] ?? 0);

        $preselectedOption = null;
        if ($votesFor > $votesAgainst && $votesFor > $votesAbstain) {
            $preselectedOption = 'for';
        } else if ($votesAgainst > $votesFor && $votesAgainst > $votesAbstain) {
            $preselectedOption = 'against';
        } else if ($votesAbstain > $votesFor && $votesAbstain > $votesAgainst) {
            $preselectedOption = 'abstain';
        }

        return [
            'motionTitle'       => $motionTitle,
            'votingMethod'      => ($round['votingMethod'] ?? ''),
            'isOpen'            => ($round['closedAt'] ?? null) === null,
            'votesFor'          => $votesFor,
            'votesAgainst'      => $votesAgainst,
            'votesAbstain'      => $votesAbstain,
            'preselectedOption' => $preselectedOption,
            'openedAt'          => ($round['openedAt'] ?? null),
        ];

    }//end getPublicState()
}//end class
