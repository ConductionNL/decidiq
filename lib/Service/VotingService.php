<?php

/**
 * Decidesk Voting Service
 *
 * Business logic for voting round management, quorum enforcement, vote casting,
 * proxy delegation, result tallying, and post-close automation.
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
 * Service for VotingRound open/close, quorum, vote casting, proxy, and tally.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

    /**
     * Roles that are NOT allowed to receive a proxy delegation.
     *
     * @var array<string>
     */
    private const NON_VOTING_ROLES = ['observer', 'guest'];

    /**
     * Constructor.
     *
     * @param ContainerInterface    $container             DI container (OpenRegister services)
     * @param LoggerInterface       $logger                PSR logger
     * @param MotionService         $motionService         Motion lifecycle service
     * @param OriPublicationService $oriPublicationService ORI publication service
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private MotionService $motionService,
        private OriPublicationService $oriPublicationService,
    ) {
    }//end __construct()

    /**
     * Get ObjectService from the DI container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get NotificationService from the DI container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');
    }//end getNotificationService()

    /**
     * Get FileService from the DI container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    private function getFileService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');
    }//end getFileService()

    /**
     * Check whether the meeting has enough active Participants to form a quorum.
     *
     * Counts Participants with `leftAt === null` related to the GovernanceBody associated
     * with the given Meeting, then compares against `Meeting.quorumRequired`.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @return bool True when quorum is met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->getObjectService();

        $meeting = $objectService->getObject(register: 'decidesk', schema: 'meeting', uuid: $meetingId);
        if ($meeting === null) {
            return false;
        }

        $quorumRequired = (int) ($meeting['quorumRequired'] ?? 0);
        if ($quorumRequired === 0) {
            // No quorum configured — always met.
            return true;
        }

        // Count active Participants (leftAt is null) for the GovernanceBody.
        $governanceBodyId = null;
        $relations        = $meeting['relations'] ?? [];
        if (isset($relations['governance-body']) === true) {
            $gb = $relations['governance-body'];
            if (is_array($gb) === true) {
                $governanceBodyId = $gb[0]['id'] ?? $gb[0] ?? null;
            } else {
                $governanceBodyId = $gb;
            }
        }

        if ($governanceBodyId === null) {
            // No governance body linked — cannot verify quorum.
            return false;
        }

        $participants = $objectService->findObjects(
            register: 'decidesk',
            schema: 'participant',
            filters: ['relations.governance-body' => $governanceBodyId]
        ) ?? [];

        $activeCount = 0;
        foreach ($participants as $participant) {
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;
    }//end checkQuorum()

    /**
     * Open a new VotingRound for a Motion.
     *
     * Checks quorum, creates the VotingRound object, transitions the Motion to `voting`,
     * and optionally creates a calendar event for the voting deadline.
     *
     * @param string      $motionId     UUID of the Motion
     * @param string      $votingMethod Voting method (for-against-abstain, ranked-choice, weighted, show-of-hands)
     * @param bool        $isSecret     Whether the ballot is secret
     * @param string|null $closedAt     Optional ISO-8601 datetime for voting deadline
     * @param string      $actorId      Nextcloud user ID of the chair opening the round
     * @param string|null $meetingId    UUID of the Meeting (for quorum check)
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @return array<string,mixed> The created VotingRound object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function openVotingRound(
        string $motionId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        string $actorId='',
        ?string $meetingId=null,
    ): array {
        // Quorum check (if meeting is known).
        $quorumMet = true;
        if ($meetingId !== null) {
            $quorumMet = $this->checkQuorum(meetingId: $meetingId);
            if ($quorumMet === false) {
                throw new \RuntimeException('Quorum niet bereikt');
            }
        }

        $objectService = $this->getObjectService();

        $votingRound = [
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'closedAt'     => null,
            'quorumMet'    => $quorumMet,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
        ];

        $savedRound = $objectService->saveObject(
            register: 'decidesk',
            schema: 'voting-round',
            object: $votingRound
        );

        // Create relation: VotingRound → Motion.
        $roundId = $savedRound['id'] ?? $savedRound['uuid'] ?? null;
        if ($roundId !== null) {
            $objectService->addRelation(
                register: 'decidesk',
                schema: 'voting-round',
                uuid: $roundId,
                relationSchema: 'motion',
                relationUuid: $motionId
            );
        }

        // Transition Motion to voting lifecycle.
        try {
            $this->motionService->transitionLifecycle(
                objectId: $motionId,
                objectType: 'motion',
                newState: 'voting',
                actorId: $actorId
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Could not transition motion to voting: {$e->getMessage()}");
        }

        // Create calendar event for voting deadline if closedAt is set.
        if ($closedAt !== null) {
            try {
                $calendarService = $this->container->get('OCA\OpenRegister\Service\CalendarEventService');
                $calendarService->createEvent(
                    title: 'Stemronde sluit',
                    startAt: $closedAt,
                    endAt: $closedAt,
                    description: "Stemronde voor motie {$motionId} sluit automatisch."
                );
            } catch (\Throwable $e) {
                $this->logger->warning("CalendarEventService unavailable: {$e->getMessage()}");
            }
        }

        $this->logger->info("VotingRound opened for motion {$motionId} by {$actorId}");
        return $savedRound;
    }//end openVotingRound()

    /**
     * Cast a vote in an open VotingRound.
     *
     * Enforces: round must be open, no duplicate votes (update on duplicate),
     * one-proxy-per-round rule for proxy votes.
     *
     * @param string      $votingRoundId UUID of the VotingRound
     * @param string      $participantId UUID of the voting Participant
     * @param string      $value         Vote value: "for", "against", or "abstain"
     * @param bool        $isProxy       Whether this is a proxy vote
     * @param string|null $delegatorId   UUID of the Participant being represented (required when isProxy)
     *
     * @throws \RuntimeException When the round is closed, or proxy rules are violated
     *
     * @return array<string,mixed> The created or updated Vote object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy=false,
        ?string $delegatorId=null,
    ): array {
        $objectService = $this->getObjectService();

        // Verify round is open.
        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['closedAt'] ?? null) !== null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} is already closed");
        }

        // Proxy enforcement: check one-proxy-per-round for this delegator.
        if ($isProxy === true && $delegatorId !== null) {
            $existingProxies = $objectService->findObjects(
                register: 'decidesk',
                schema: 'vote',
                filters: [
                    'relations.voting-round' => $votingRoundId,
                    'isProxy'                => true,
                ]
            ) ?? [];

            foreach ($existingProxies as $existingProxy) {
                $proxyDelegator = $existingProxy['relations']['delegator'] ?? null;
                if (is_array($proxyDelegator) === true) {
                    $existingDelegatorId = $proxyDelegator[0]['id'] ?? $proxyDelegator[0] ?? null;
                } else {
                    $existingDelegatorId = $proxyDelegator;
                }

                if ($existingDelegatorId === $delegatorId) {
                    throw new \RuntimeException(
                        "Participant {$delegatorId} already has a proxy vote in round {$votingRoundId}"
                    );
                }
            }
        }//end if

        // Check for existing vote from this participant (update instead of duplicate).
        $existingVotes = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: [
                'relations.voting-round' => $votingRoundId,
                'relations.participant'  => $participantId,
                'isProxy'                => false,
            ]
        ) ?? [];

        $voteData = [
            'value'   => $value,
            'weight'  => 1,
            'isProxy' => $isProxy,
            'castAt'  => (new \DateTimeImmutable())->format(\DateTime::ATOM),
        ];

        if (empty($existingVotes) === false && $isProxy === false) {
            // Update existing vote.
            $existingVote           = reset($existingVotes);
            $existingVote['value']  = $value;
            $existingVote['castAt'] = $voteData['castAt'];
            $savedVote = $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $existingVote);
        } else {
            $savedVote = $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $voteData);

            // Add relations: Vote → VotingRound, Vote → Participant.
            $voteId = $savedVote['id'] ?? $savedVote['uuid'] ?? null;
            if ($voteId !== null) {
                $objectService->addRelation(
                    register: 'decidesk',
                    schema: 'vote',
                    uuid: $voteId,
                    relationSchema: 'voting-round',
                    relationUuid: $votingRoundId
                );
                $objectService->addRelation(
                    register: 'decidesk',
                    schema: 'vote',
                    uuid: $voteId,
                    relationSchema: 'participant',
                    relationUuid: $participantId
                );

                if ($isProxy === true && $delegatorId !== null) {
                    $objectService->addRelation(
                        register: 'decidesk',
                        schema: 'vote',
                        uuid: $voteId,
                        relationSchema: 'participant',
                        relationUuid: $delegatorId,
                        relationName: 'delegator'
                    );
                }
            }//end if
        }//end if

        $this->logger->info("Vote cast in round {$votingRoundId} by participant {$participantId}: {$value}");
        return $savedVote;
    }//end castVote()

    /**
     * Close a VotingRound: tally results, update Motion lifecycle, trigger ORI publication
     * and dossier folder creation on adoption.
     *
     * @param string $votingRoundId UUID of the VotingRound to close
     * @param string $actorId       Nextcloud user ID of the chair closing the round
     *
     * @throws \RuntimeException When the round cannot be found
     *
     * @return array<string,mixed> The updated VotingRound object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function closeVotingRound(string $votingRoundId, string $actorId=''): array
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);
        $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        // Tally results.
        $tallied = $this->tallyResults(votingRoundId: $votingRoundId);
        $result  = $tallied['result'] ?? 'invalid';

        // Transition Motion lifecycle.
        $motionRelations = $round['relations']['motion'] ?? [];
        $motionId        = null;
        if (empty($motionRelations) === false) {
            if (is_array($motionRelations) === true) {
                $motionRef = reset($motionRelations);
            } else {
                $motionRef = $motionRelations;
            }

            if (is_array($motionRef) === true) {
                $motionId = $motionRef['id'] ?? $motionRef['uuid'] ?? null;
            } else {
                $motionId = $motionRef;
            }
        }

        if ($motionId !== null) {
            if ($result === 'adopted') {
                $newLifecycle = 'adopted';
            } else {
                $newLifecycle = 'rejected';
            }

            try {
                $this->motionService->transitionLifecycle(
                    objectId: $motionId,
                    objectType: 'motion',
                    newState: $newLifecycle,
                    actorId: $actorId
                );
            } catch (\Throwable $e) {
                $this->logger->warning("Could not transition motion after close: {$e->getMessage()}");
            }

            // If adopted: create dossier folder.
            if ($result === 'adopted') {
                try {
                    $motion      = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
                    $slug        = $motion['@self']['slug'] ?? ('motion-'.$motionId);
                    $fileService = $this->getFileService();
                    $fileService->createFolder(path: "motions/{$slug}");
                } catch (\Throwable $e) {
                    $this->logger->warning("FileService unavailable for dossier creation: {$e->getMessage()}");
                }
            }
        }//end if

        // ORI publication (if configured).
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->warning("OriPublicationService failed: {$e->getMessage()}");
        }

        $this->logger->info("VotingRound {$votingRoundId} closed by {$actorId}, result: {$result}");

        // Return the fresh round state.
        return $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId) ?? $round;
    }//end closeVotingRound()

    /**
     * Tally Vote objects for a VotingRound and determine the result.
     *
     * Updates the VotingRound with counts and result. Majority = more For than Against.
     * Tied = equal For and Against. Invalid = no votes cast.
     *
     * @param string $votingRoundId UUID of the VotingRound
     *
     * @return array<string,mixed> Tally data including votesFor, votesAgainst, votesAbstain, result
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        $votes = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: ['relations.voting-round' => $votingRoundId]
        ) ?? [];

        $for     = 0;
        $against = 0;
        $abstain = 0;

        foreach ($votes as $vote) {
            $weight = (int) ($vote['weight'] ?? 1);
            switch ($vote['value'] ?? '') {
                case 'for':
                    $for += $weight;
                    break;
                case 'against':
                    $against += $weight;
                    break;
                case 'abstain':
                    $abstain += $weight;
                    break;
            }
        }

        $total  = $for + $against + $abstain;
        $result = 'invalid';
        if ($total > 0) {
            if ($for > $against) {
                $result = 'adopted';
            } else if ($against > $for) {
                $result = 'rejected';
            } else {
                $result = 'tied';
            }
        }

        $tally = [
            'votesFor'     => $for,
            'votesAgainst' => $against,
            'votesAbstain' => $abstain,
            'result'       => $result,
        ];

        // Update the VotingRound with tally data.
        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round !== null) {
            $round = array_merge($round, $tally);
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        return $tally;
    }//end tallyResults()

    /**
     * Grant a proxy vote from one Participant to another for a specific VotingRound.
     *
     * Validates that the receiver is a voting-eligible role (not observer/guest),
     * stores the proxy delegation, and notifies the delegate.
     *
     * @param string $votingRoundId     UUID of the VotingRound
     * @param string $fromParticipantId UUID of the delegating Participant
     * @param string $toParticipantId   UUID of the receiving Participant (delegate)
     *
     * @throws \RuntimeException When the round is already open, or receiver is ineligible
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round !== null && ($round['openedAt'] ?? null) !== null && ($round['closedAt'] ?? null) === null) {
            throw new \RuntimeException("Cannot grant proxy: VotingRound {$votingRoundId} is already open");
        }

        // Check receiver role.
        $delegate = $objectService->getObject(register: 'decidesk', schema: 'participant', uuid: $toParticipantId);
        if ($delegate !== null) {
            $role = strtolower($delegate['role'] ?? '');
            if (in_array($role, self::NON_VOTING_ROLES, true) === true) {
                throw new \RuntimeException("Participant {$toParticipantId} with role '{$role}' cannot receive a proxy");
            }
        }

        // Store proxy delegation as a note on the VotingRound for audit.
        if ($round !== null) {
            $round['notes']   = $round['notes'] ?? [];
            $round['notes'][] = [
                'title' => 'Proxy delegation',
                'body'  => json_encode(
                        [
                            'from'      => $fromParticipantId,
                            'to'        => $toParticipantId,
                            'grantedAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
                        ]
                        ),
            ];
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        // Notify the delegate.
        try {
            $notificationService = $this->getNotificationService();
            $notificationService->sendNotification(
                userId: $toParticipantId,
                subject: 'Volmacht ontvangen',
                message: "U heeft een volmacht ontvangen van deelnemer {$fromParticipantId} voor stemronde {$votingRoundId}.",
                link: "/apps/decidesk"
            );
        } catch (\Throwable $e) {
            $this->logger->warning("NotificationService unavailable: {$e->getMessage()}");
        }

        $this->logger->info("Proxy granted: {$fromParticipantId} → {$toParticipantId} in round {$votingRoundId}");
    }//end grantProxy()

    /**
     * Revoke a proxy delegation before the VotingRound opens.
     *
     * Verifies the round has not yet opened, removes the proxy delegation record,
     * and notifies the former delegate.
     *
     * @param string $votingRoundId     UUID of the VotingRound
     * @param string $fromParticipantId UUID of the Participant revoking their proxy
     *
     * @throws \RuntimeException When the round is already open
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     */
    public function revokeProxy(string $votingRoundId, string $fromParticipantId): void
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round !== null && ($round['openedAt'] ?? null) !== null && ($round['closedAt'] ?? null) === null) {
            throw new \RuntimeException("Cannot revoke proxy: VotingRound {$votingRoundId} is already open");
        }

        if ($round !== null) {
            $notes         = $round['notes'] ?? [];
            $delegateId    = null;
            $filteredNotes = [];

            foreach ($notes as $note) {
                if (($note['title'] ?? '') === 'Proxy delegation') {
                    $body = json_decode($note['body'] ?? '{}', true);
                    if (($body['from'] ?? '') === $fromParticipantId) {
                        $delegateId = $body['to'] ?? null;
                        // Remove this proxy note.
                        continue;
                    }
                }

                $filteredNotes[] = $note;
            }

            $round['notes'] = $filteredNotes;
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

            // Notify the former delegate.
            if ($delegateId !== null) {
                try {
                    $notificationService = $this->getNotificationService();
                    $notificationService->sendNotification(
                        userId: $delegateId,
                        subject: 'Volmacht ingetrokken',
                        message: "Deelnemer {$fromParticipantId} heeft de volmacht voor stemronde {$votingRoundId} ingetrokken.",
                        link: "/apps/decidesk"
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning("NotificationService unavailable on revoke: {$e->getMessage()}");
                }
            }
        }//end if

        $this->logger->info("Proxy revoked by {$fromParticipantId} in round {$votingRoundId}");
    }//end revokeProxy()
}//end class
