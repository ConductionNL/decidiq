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
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

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
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly OriPublicationService $oriPublicationService,
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
     * Resolve OpenRegister NotificationService.
     *
     * @return object
     */
    private function notificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');

    }//end notificationService()

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
        $meeting       = $objectService->getObject(register: 'decidesk', schema: 'meeting', uuid: $meetingId);

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
            // No governance body linked — cannot verify quorum.
            return true;
        }

        $participants = $objectService->findObjects(
            register: 'decidesk',
            schema: 'participant',
            filters: ['relations.governance-body' => $governanceBodyId]
        );

        $activeCount = 0;
        foreach (($participants['results'] ?? []) as $participant) {
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a new VotingRound for a Motion.
     *
     * Verifies quorum, creates the VotingRound object, transitions the Motion to
     * 'voting' lifecycle, and optionally creates a calendar deadline event.
     *
     * @param string      $motionId     The motion UUID
     * @param string      $meetingId    The meeting UUID (for quorum check)
     * @param string      $votingMethod for-against-abstain | ranked-choice | weighted | show-of-hands
     * @param bool        $isSecret     Whether the ballot is secret
     * @param string|null $closedAt     Optional ISO-8601 deadline for the round
     *
     * @return array<string,mixed> The created VotingRound object
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function openVotingRound(string $motionId, string $meetingId, string $votingMethod, bool $isSecret, ?string $closedAt): array
    {
        if ($this->checkQuorum(meetingId: $meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        $objectService = $this->objectService();

        $votingRound = [
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'closedAt'     => $closedAt,
            'quorumMet'    => true,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
            'relations'    => [
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => $motionId],
            ],
        ];

        $created = $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $votingRound);

        // Transition motion lifecycle to 'voting'.
        try {
            $motion = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
            if ($motion !== null) {
                $motion['lifecycle'] = 'voting';
                $motion['status']    = 'voting';
                $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to transition motion lifecycle', ['error' => $e->getMessage()]);
        }

        return ($created ?? $votingRound);

    }//end openVotingRound()

    /**
     * Cast a vote in a VotingRound.
     *
     * Checks the round is open, prevents duplicates (overwrites existing vote),
     * and enforces one-proxy-per-round for proxy votes.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The participant UUID
     * @param string      $value         for | against | abstain
     * @param bool        $isProxy       True when the participant is voting as proxy for another
     * @param string|null $delegatorId   The participant UUID being delegated (required when isProxy=true)
     *
     * @return array<string,mixed> The created/updated Vote object
     *
     * @throws \RuntimeException When the round is not open, or proxy rules are violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function castVote(string $votingRoundId, string $participantId, string $value, bool $isProxy, ?string $delegatorId): array
    {
        $objectService = $this->objectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['closedAt'] ?? null) !== null && strtotime($round['closedAt']) < time()) {
            throw new \RuntimeException('Stemronde is gesloten');
        }

        if (($round['openedAt'] ?? null) === null) {
            throw new \RuntimeException('Stemronde is nog niet geopend');
        }

        // Enforce one-proxy-per-round: check for existing proxy vote from this delegator.
        if ($isProxy === true && $delegatorId !== null) {
            $existingProxies = $objectService->findObjects(
                register: 'decidesk',
                schema: 'vote',
                filters: [
                    'relations.voting-round' => $votingRoundId,
                    'isProxy'                => true,
                ]
            );

            foreach (($existingProxies['results'] ?? []) as $proxyVote) {
                foreach (($proxyVote['relations'] ?? []) as $rel) {
                    if (($rel['schema'] ?? '') === 'participant' && ($rel['id'] ?? '') === $delegatorId && ($rel['type'] ?? '') === 'delegator') {
                        throw new \RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
                    }
                }
            }
        }

        // Check for existing vote by this participant — overwrite if found.
        $existingVotes = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: ['relations.voting-round' => $votingRoundId, 'relations.participant' => $participantId]
        );

        $existingVote = null;
        foreach (($existingVotes['results'] ?? []) as $v) {
            $existingVote = $v;
            break;
        }

        $isSecret = (bool) ($round['isSecret'] ?? false);

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

        if ($existingVote !== null) {
            $vote['id']   = ($existingVote['id'] ?? null);
            $vote['uuid'] = ($existingVote['uuid'] ?? null);
        }

        $saved = $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);

        return ($saved ?? $vote);

    }//end castVote()

    /**
     * Close a VotingRound: tally votes, set result, publish to ORI, create dossier folder.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed> The updated VotingRound
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function closeVotingRound(string $votingRoundId): array
    {
        $tally = $this->tallyResults(votingRoundId: $votingRoundId);

        $objectService = $this->objectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round !== null && ($round['closedAt'] ?? null) === null) {
            $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        $result = ($tally['result'] ?? 'invalid');

        // Transition motion lifecycle based on result.
        if ($round !== null) {
            foreach (($round['relations'] ?? []) as $rel) {
                if (($rel['schema'] ?? '') === 'motion') {
                    $motionId = ($rel['id'] ?? null);
                    if ($motionId !== null) {
                        try {
                            $motion = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
                            if ($motion !== null) {
                                // Only transition to defined terminal states; tied/invalid leaves lifecycle unchanged.
                                $motionLifecycle = match ($result) {
                                    'adopted'  => 'adopted',
                                    'rejected' => 'rejected',
                                    default    => null,
                                };

                                if ($motionLifecycle !== null) {
                                    $motionTitle         = (string) ($motion['title'] ?? $motionId);
                                    $motion['lifecycle'] = $motionLifecycle;
                                    $motion['status']    = $motionLifecycle;
                                    $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

                                    // Create dossier folder if adopted.
                                    if ($motionLifecycle === 'adopted') {
                                        $this->createDossierFolder(motionId: $motionId, motionTitle: $motionTitle);
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $this->logger->warning('Decidesk: lifecycle transition after close failed', ['error' => $e->getMessage()]);
                        }//end try
                    }//end if

                    break;
                }//end if
            }//end foreach
        }//end if

        // Trigger ORI publication (fails silently if not configured).
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->info('Decidesk: ORI publication deferred', ['error' => $e->getMessage()]);
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
        $votes         = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: ['relations.voting-round' => $votingRoundId]
        );

        $for     = 0;
        $against = 0;
        $abstain = 0;

        foreach (($votes['results'] ?? []) as $vote) {
            $val = ($vote['value'] ?? '');
            if ($val === 'for') {
                $for++;
            } else if ($val === 'against') {
                $against++;
            } else if ($val === 'abstain') {
                $abstain++;
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
        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
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
        $objectService = $this->objectService();

        $toParticipant = $objectService->getObject(register: 'decidesk', schema: 'participant', uuid: $toParticipantId);
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
        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round !== null) {
            $notes          = ($round['notes'] ?? []);
            $notes[]        = [
                'title' => 'Proxy',
                'body'  => json_encode($proxyRecord),
            ];
            $round['notes'] = $notes;
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        // Notify delegate — use the participant UUID as Nextcloud UID, not the email address.
        try {
            $this->notificationService()->createNotification(
                userId: $toParticipantId,
                app: 'decidesk',
                subject: 'proxy_granted',
                subjectParameters: ['from' => $fromParticipantId, 'votingRoundId' => $votingRoundId],
                object: 'voting-round',
                objectId: $votingRoundId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: proxy grant notification failed', ['error' => $e->getMessage()]);
        }

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
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

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
}//end class
