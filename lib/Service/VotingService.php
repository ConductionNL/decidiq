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

use OCA\Decidesk\Service\ParticipantResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service implementing voting round governance rules.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

    /**
     * Roles that may not be a proxy receiver (observer and guest cannot vote).
     *
     * @var string[]
     */
    private const NON_VOTING_ROLES = ['observer', 'guest'];

    /**
     * Constructor for VotingService.
     *
     * @param ContainerInterface    $container             The DI container
     * @param LoggerInterface       $logger                The logger
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param MotionService         $motionService         The motion service for lifecycle transitions
     * @param ParticipantResolver   $participantResolver   Participant resolver for meeting-based membership checks
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
    ) {
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
     * Resolve Nextcloud Notification IManager.
     *
     * @return \OCP\Notification\IManager
     */
    private function notificationManager(): \OCP\Notification\IManager
    {
        return $this->container->get(\OCP\Notification\IManager::class);

    }//end notificationManager()

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
            $secret = bin2hex(random_bytes(32));
            $appConfig->setValueString('decidesk', 'voter_token_secret', $secret);
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

        // Count active participants (leftAt is null).
        $governanceBodyId = null;
        foreach (($meeting['relations'] ?? []) as $relation) {
            if (($relation['schema'] ?? '') === 'governance-body') {
                $governanceBodyId = ($relation['id'] ?? null);
                break;
            }
        }

        if ($governanceBodyId === null) {
            // No governance body linked — cannot verify quorum; fail closed.
            return false;
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $participantEntities = $objectService->findAll(['filters' => ['relations.governance-body' => $governanceBodyId]]);

        $activeCount = 0;
        foreach ($participantEntities as $participantEntity) {
            $participant = $participantEntity->jsonSerialize();
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a VotingRound, optionally with preset participant UUIDs.
     *
     * @param string        $motionId             The motion UUID
     * @param string        $meetingId            The meeting UUID
     * @param string        $votingMethod         The voting method (for-against-abstain, show-of-hands, etc.)
     * @param bool          $isSecret             Whether the ballot is secret
     * @param string|null   $closedAt             Optional pre-defined close time
     * @param array<string> $presetParticipantIds Optional array of participant UUIDs for a voting group preset
     *
     * @return array<string,mixed> The created voting round object with excludedPresetUuids key if any UUIDs were excluded
     *
     * @throws \RuntimeException When quorum is not met or lifecycle transition fails
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function openVotingRound(
        string $motionId,
        string $meetingId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        array $presetParticipantIds=[],
    ): array {
        $quorumMet = $this->checkQuorum(meetingId: $meetingId);
        if ($quorumMet === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        $objectService = $this->objectService();

        $votingRound = [
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'closedAt'     => $closedAt,
            'quorumMet'    => $quorumMet,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
            'relations'    => [
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => $motionId],
            ],
        ];

        // Validate preset UUIDs against active memberships.
        $excludedUuids = [];
        if (count($presetParticipantIds) > 0) {
            $participantArrays  = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
            $activeParticipants = array_column($participantArrays, 'id', 'id');

            foreach ($presetParticipantIds as $uuid) {
                if (isset($activeParticipants[$uuid]) === false) {
                    $excludedUuids[] = $uuid;
                }
            }

            // Store eligible preset UUIDs as relations.
            $eligibleUuids = array_diff($presetParticipantIds, $excludedUuids);
            foreach ($eligibleUuids as $uuid) {
                $votingRound['relations'][] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $uuid];
            }
        }//end if

        $created = $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $votingRound);

        // Transition motion lifecycle to 'voting' via the guarded state machine.
        try {
            $this->motionService->transitionLifecycle(
                objectId: $motionId,
                objectType: 'motion',
                newState: 'voting',
                actorId: 'system',
            );
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Cannot open voting round: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to transition motion lifecycle', ['error' => $e->getMessage()]);
        }

        $result = ($created ?? $votingRound);
        if (count($excludedUuids) > 0) {
            $result['excludedPresetUuids'] = $excludedUuids;
        }

        return $result;

    }//end openVotingRound()

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
     *
     * @return array<string,mixed> The created/updated Vote object
     *
     * @throws \RuntimeException When the round is not open, the caller is not a meeting member,
     *                           or proxy rules are violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function castVote(string $votingRoundId, string $participantId, string $value, bool $isProxy, ?string $delegatorId): array
    {
        $objectService = $this->objectService();

        $roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round       = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['closedAt'] ?? null) !== null && strtotime($round['closedAt']) < time()) {
            throw new \RuntimeException('Stemronde is gesloten');
        }

        if (($round['openedAt'] ?? null) === null) {
            throw new \RuntimeException('Stemronde is nog niet geopend');
        }

        // #300: Verify the participant is actually a member of the meeting that owns this round.
        // The round is linked to a Motion; the Motion is linked to a Meeting via its relations.
        $meetingId = null;
        foreach (($round['relations'] ?? []) as $roundRel) {
            if (($roundRel['schema'] ?? '') === 'motion') {
                $motionId = ($roundRel['id'] ?? null);
                if ($motionId !== null) {
                    $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
                    if ($motionEntity !== null) {
                        $motionData = $motionEntity->jsonSerialize();
                        foreach (($motionData['relations'] ?? []) as $motionRel) {
                            if (($motionRel['schema'] ?? '') === 'meeting') {
                                $meetingId = ($motionRel['id'] ?? null);
                                break;
                            }
                        }
                    }
                }

                break;
            }
        }

        if ($meetingId !== null) {
            // Verify the participant belongs to the meeting's governance body.
            $meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
            $memberIds           = array_column($meetingParticipants, 'id');
            if (in_array($participantId, $memberIds, true) === false) {
                throw new \RuntimeException('Deelnemer is geen lid van de vergadering');
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
                throw new \RuntimeException('Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever');
            }

            // Enforce one-proxy-per-round: check for existing proxy vote from this delegator.
            // For secret rounds, participant relations are suppressed for anonymity, so dedup
            // is keyed on a deterministic delegatorToken (HMAC) to avoid DNS-style rebinding.
            if ($isSecret === true) {
                $delegatorToken = hash_hmac('sha256', $delegatorId.':proxy:'.$votingRoundId, $this->voterTokenSecret());
                $objectService->setRegister('decidesk');
                $objectService->setSchema('vote');
                $existingProxyEntities = $objectService->findAll(
                        [
                            'filters' => [
                                'relations.voting-round' => $votingRoundId,
                                'delegatorToken'         => $delegatorToken,
                            ],
                        ]
                        );
                if (empty($existingProxyEntities) === false) {
                    throw new \RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
                }
            } else {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('vote');
                $existingProxyEntities = $objectService->findAll(
                        [
                            'filters' => [
                                'relations.voting-round' => $votingRoundId,
                                'isProxy'                => true,
                            ],
                        ]
                        );

                foreach ($existingProxyEntities as $proxyVoteEntity) {
                    $proxyVote = $proxyVoteEntity->jsonSerialize();
                    foreach (($proxyVote['relations'] ?? []) as $rel) {
                        if (($rel['schema'] ?? '') === 'participant' && ($rel['id'] ?? '') === $delegatorId && ($rel['type'] ?? '') === 'delegator') {
                            throw new \RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
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
            $existingVoteEntities = $objectService->findAll(
                    [
                        'filters' => [
                            'relations.voting-round' => $votingRoundId,
                            'voterToken'             => $voterToken,
                        ],
                    ]
                    );
        } else {
            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            $existingVoteEntities = $objectService->findAll(
                    [
                        'filters' => [
                            'relations.voting-round' => $votingRoundId,
                            'relations.participant'  => $participantId,
                        ],
                    ]
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

        $vote = [
            'value'     => $value,
            'weight'    => 1,
            'isProxy'   => $isProxy,
            'castAt'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'relations' => $relations,
        ];

        // Store opaque dedup token for secret rounds (never contains participant identity).
        if ($isSecret === true) {
            $vote['voterToken'] = hash_hmac('sha256', $participantId.':'.$votingRoundId, $this->voterTokenSecret());
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

        return ($saved ?? $vote);

    }//end castVote()

    /**
     * Close a VotingRound, optionally anonymising vote values.
     *
     * @param string $votingRoundId The voting round UUID
     * @param bool   $anonymise     Whether to nullify individual vote values (GDPR anonymisation)
     *
     * @return array<string,mixed> The closed voting round object
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function closeVotingRound(string $votingRoundId, bool $anonymise=false): array
    {
        $tally = $this->tallyResults(votingRoundId: $votingRoundId);

        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round !== null && ($round['closedAt'] ?? null) === null) {
            $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        $result = ($tally['result'] ?? 'invalid');

        // Transition motion lifecycle based on result.
        // #318: Re-throw \InvalidArgumentException (bad state-machine transition) so the
        // caller learns the round was closed but motion lifecycle could not be updated.
        // Transient/infrastructure errors are still logged-and-continued so a network hiccup
        // does not leave the round un-closed; however they are logged at ERROR level so they
        // surface in monitoring rather than silently disappearing.
        if ($round !== null) {
            foreach (($round['relations'] ?? []) as $rel) {
                if (($rel['schema'] ?? '') === 'motion') {
                    $motionId = ($rel['id'] ?? null);
                    if ($motionId !== null) {
                        // Only transition to defined terminal states via the guarded state machine.
                        $motionLifecycle = match ($result) {
                            'adopted'  => 'adopted',
                            'rejected' => 'rejected',
                            default    => null,
                        };

                        if ($motionLifecycle !== null) {
                            try {
                                $this->motionService->transitionLifecycle(
                                    objectId: $motionId,
                                    objectType: 'motion',
                                    newState: $motionLifecycle,
                                    actorId: 'system',
                                );

                                // Create dossier folder if adopted.
                                if ($motionLifecycle === 'adopted') {
                                    $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
                                    $motion       = null;
                                    if ($motionEntity !== null) {
                                        $motion = $motionEntity->jsonSerialize();
                                    }

                                    $motionTitle = (string) ($motion['title'] ?? $motionId);
                                    $this->createDossierFolder(motionId: $motionId, motionTitle: $motionTitle);
                                }
                            } catch (\InvalidArgumentException $e) {
                                // State-machine violation: re-throw so the caller can surface it.
                                // #318: Previously swallowed silently.
                                throw new \RuntimeException(
                                    'Stemronde gesloten maar motie kon niet worden bijgewerkt: '.$e->getMessage(),
                                    0,
                                    $e
                                );
                            } catch (\Throwable $e) {
                                // Transient infrastructure failure: log at ERROR level and continue.
                                // #318: Previously logged at WARNING and lost in monitoring noise.
                                $this->logger->error(
                                    'Decidesk: lifecycle transition after close failed — round is closed but motion state may be stale',
                                    ['votingRoundId' => $votingRoundId, 'motionId' => $motionId, 'error' => $e->getMessage()]
                                );
                            }//end try
                        }//end if
                    }//end if

                    break;
                }//end if
            }//end foreach
        }//end if

        // Trigger ORI publication.
        // #318: Publication failures are now surfaced to callers when they indicate a real
        // configuration or protocol error (not "endpoint not configured" — that is still
        // swallowed, as `publish()` returns silently when no endpoint is set).
        // Infrastructure/network errors are logged at ERROR level (was INFO) so they surface
        // in monitoring.
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
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
                $voteEntities = $objectService->findAll(['filters' => ['relations.voting-round' => $votingRoundId]]);

                foreach ($voteEntities as $voteEntity) {
                    $vote          = $voteEntity->jsonSerialize();
                    $vote['value'] = null;
                    $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);
                }

                $this->logger->info('Decidesk: votes anonymised', ['votingRoundId' => $votingRoundId]);
            } catch (\Throwable $e) {
                $this->logger->warning('Decidesk: vote anonymisation failed', ['error' => $e->getMessage()]);
            }
        }

        return ($round ?? []);

    }//end closeVotingRound()

    /**
     * Tally all votes in a VotingRound and update the round with counts and result.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed> Tally with votesFor, votesAgainst, votesAbstain, result
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');
        $voteEntities = $objectService->findAll(['filters' => ['relations.voting-round' => $votingRoundId]]);

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

        $total = ($for + $against + $abstain);

        if ($total === 0) {
            $result = 'invalid';
        } else if ($for > $against) {
            $result = 'adopted';
        } else if ($against > $for) {
            $result = 'rejected';
        } else {
            $result = 'tied';
        }

        // Update VotingRound with tally.
        $roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round       = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round !== null) {
            $round['votesFor']     = $for;
            $round['votesAgainst'] = $against;
            $round['votesAbstain'] = $abstain;
            $round['result']       = $result;
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        return [
            'votesFor'     => $for,
            'votesAgainst' => $against,
            'votesAbstain' => $abstain,
            'total'        => $total,
            'result'       => $result,
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
     * @throws \RuntimeException When the round is not found or is not a show-of-hands round
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
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
            throw new \RuntimeException("VotingRound $votingRoundId not found");
        }

        if (($round['votingMethod'] ?? '') !== 'show-of-hands') {
            throw new \RuntimeException('saveShowOfHandsTally is only valid for show-of-hands rounds');
        }

        // #302: Validate submitted counts against the actual participant count for the meeting.
        // The round relates to a motion, which relates to a meeting; count active participants.
        $meetingId = null;
        foreach (($round['relations'] ?? []) as $roundRel) {
            if (($roundRel['schema'] ?? '') === 'motion') {
                $motionId = ($roundRel['id'] ?? null);
                if ($motionId !== null) {
                    $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
                    if ($motionEntity !== null) {
                        $motionData = $motionEntity->jsonSerialize();
                        foreach (($motionData['relations'] ?? []) as $motionRel) {
                            if (($motionRel['schema'] ?? '') === 'meeting') {
                                $meetingId = ($motionRel['id'] ?? null);
                                break;
                            }
                        }
                    }
                }

                break;
            }
        }

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
                throw new \RuntimeException(
                    "Ingevoerde tellingen ({$submittedTotal}) overschrijden het aantal actieve deelnemers ({$activeCount})"
                );
            }
        }//end if

        $total  = ($votesFor + $votesAgainst + $votesAbstain);
        $result = match (true) {
            $total === 0      => 'invalid',
            $votesFor > $votesAgainst  => 'adopted',
            $votesAgainst > $votesFor  => 'rejected',
            default           => 'tied',
        };

        $round['votesFor']     = $votesFor;
        $round['votesAgainst'] = $votesAgainst;
        $round['votesAbstain'] = $votesAbstain;
        $round['result']       = $result;

        $saved = $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        return ($saved ?? $round);

    }//end saveShowOfHandsTally()

    /**
     * Grant proxy: delegate voting right from one participant to another for a VotingRound.
     *
     * Validates that the receiver has a voting role (not observer/guest).
     * Sends notification to the delegate.
     *
     * @param string $votingRoundId     The voting round UUID
     * @param string $fromParticipantId The delegating participant UUID
     * @param string $toParticipantId   The receiving participant UUID
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the receiver cannot receive proxies
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void
    {
        if ($fromParticipantId === $toParticipantId) {
            throw new \InvalidArgumentException('Een deelnemer kan geen volmacht aan zichzelf verlenen');
        }

        $objectService = $this->objectService();

        $toParticipantEntity = $objectService->find(id: $toParticipantId, register: 'decidesk', schema: 'participant');
        $toParticipant       = null;
        if ($toParticipantEntity !== null) {
            $toParticipant = $toParticipantEntity->jsonSerialize();
        }

        if ($toParticipant !== null) {
            $role = strtolower($toParticipant['role'] ?? '');
            if (in_array($role, self::NON_VOTING_ROLES, true) === true) {
                throw new \InvalidArgumentException(
                    "Deelnemer met rol '{$role}' kan geen volmacht ontvangen"
                );
            }
        }

        $proxyRecord = [
            'fromParticipantId' => $fromParticipantId,
            'toParticipantId'   => $toParticipantId,
            'votingRoundId'     => $votingRoundId,
            'grantedAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Store proxy as a structured note on the VotingRound.
        $roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round       = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round !== null) {
            $notes          = ($round['notes'] ?? []);
            $notes[]        = [
                'title' => 'Proxy',
                'body'  => json_encode($proxyRecord),
            ];
            $round['notes'] = $notes;
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        // Notify delegate — resolve the Nextcloud UID from the participant object.
        try {
            if ($toParticipant === null) {
                return;
            }

            $nextcloudUserId = $toParticipant['nextcloudUserId'] ?? null;

            // Fall back to email lookup when nextcloudUserId is not stored on the participant.
            if ($nextcloudUserId === null) {
                $email = $toParticipant['email'] ?? null;
                if ($email !== null) {
                    $userManager = $this->container->get(\OCP\IUserManager::class);
                    $users       = $userManager->getByEmail($email);
                    if (count($users) === 1) {
                        $nextcloudUserId = $users[0]->getUID();
                    }
                }
            }

            if ($nextcloudUserId !== null) {
                $notificationManager = $this->notificationManager();
                $notification        = $notificationManager->createNotification();
                $notification->setApp('decidesk')
                    ->setUser($nextcloudUserId)
                    ->setDateTime(new \DateTime())
                    ->setObject('voting-round', $votingRoundId)
                    ->setSubject('proxy_granted', ['from' => $fromParticipantId, 'votingRoundId' => $votingRoundId]);
                $notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: proxy grant notification failed', ['error' => $e->getMessage()]);
        }//end try

    }//end grantProxy()

    /**
     * Revoke proxy: remove proxy delegation before the round opens.
     *
     * @param string $votingRoundId     The voting round UUID
     * @param string $fromParticipantId The participant revoking their proxy
     *
     * @return void
     *
     * @throws \RuntimeException When the round is already open
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function revokeProxy(string $votingRoundId, string $fromParticipantId): void
    {
        $objectService = $this->objectService();
        $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round         = null;
        if ($roundEntity !== null) {
            $round = $roundEntity->jsonSerialize();
        }

        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['openedAt'] ?? null) !== null) {
            throw new \RuntimeException('Stemronde is al geopend — volmacht kan niet meer worden ingetrokken');
        }

        $notes    = ($round['notes'] ?? []);
        $filtered = array_values(
                array_filter(
                $notes,
                static function (array $note) use ($fromParticipantId): bool {
                    if (($note['title'] ?? '') !== 'Proxy') {
                        return true;
                    }

                    $body = json_decode($note['body'] ?? '{}', true);
                    return ($body['fromParticipantId'] ?? '') !== $fromParticipantId;
                }
                )
                );

        $round['notes'] = $filtered;
        $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

    }//end revokeProxy()

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
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: dossier folder creation failed', ['motionId' => $motionId, 'error' => $e->getMessage()]);
        }

    }//end createDossierFolder()

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
