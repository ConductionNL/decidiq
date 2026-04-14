<?php

/**
 * Decidesk Voting Service
 *
 * Service for managing voting rounds, vote casting, proxy delegation, and result tallying.
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
 * Service for managing voting rounds, vote casting, proxy delegation, and result tallying.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

    /**
     * Roles that are NOT allowed to receive a proxy vote.
     *
     * @var array<string>
     */
    private const DISALLOWED_PROXY_ROLES = ['observer', 'guest'];

    /**
     * Constructor for VotingService.
     *
     * @param ContainerInterface    $container             The DI container
     * @param LoggerInterface       $logger                The logger
     * @param MotionService         $motionService         The motion service
     * @param OriPublicationService $oriPublicationService The ORI publication service
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
     * Retrieve the OpenRegister ObjectService from the DI container.
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
     * Retrieve the OpenRegister NotificationService from the DI container.
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
     * Retrieve the OpenRegister FileService from the DI container.
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
     * Check whether quorum is met for the given meeting.
     *
     * Counts active Participants (non-null leftAt) related to the GovernanceBody
     * and compares against Meeting.quorumRequired.
     *
     * @param string $meetingId The UUID of the Meeting
     *
     * @return bool True when quorum is met or quorumRequired is 0
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
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
            return true;
        }

        $governanceBodyId = ($meeting['governanceBodyId'] ?? ($meeting['relations']['governance-body'][0]['id'] ?? null));
        if ($governanceBodyId === null) {
            // Cannot determine governance body; allow opening.
            return true;
        }

        $participants = $objectService->findAll(
            register: 'decidesk',
            schema: 'participant',
            filters: ['governanceBodyId' => $governanceBodyId],
        );

        $activeCount = 0;
        foreach (($participants ?? []) as $participant) {
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a new VotingRound for the given motion.
     *
     * Verifies quorum, creates the VotingRound object, transitions the Motion
     * to the "voting" lifecycle, and optionally creates a calendar event if
     * a deadline is provided.
     *
     * @param string      $motionId     The UUID of the Motion
     * @param string      $meetingId    The UUID of the Meeting (for quorum check)
     * @param string      $votingMethod One of: for-against-abstain, ranked-choice, weighted, show-of-hands
     * @param bool        $isSecret     Whether this is a secret ballot
     * @param string|null $closedAt     Optional ISO-8601 deadline (triggers calendar event)
     * @param string      $actorId      Nextcloud user ID of the chair
     *
     * @return array<string,mixed> The created VotingRound object
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function openVotingRound(
        string $motionId,
        string $meetingId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        string $actorId,
    ): array {
        if ($this->checkQuorum(meetingId: $meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        $objectService = $this->getObjectService();

        $votingRound = [
            'motionId'     => $motionId,
            'meetingId'    => $meetingId,
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'closedAt'     => null,
            'quorumMet'    => true,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
            'status'       => 'open',
        ];

        if ($closedAt !== null) {
            $votingRound['deadline'] = $closedAt;
        }

        $savedRound = $objectService->saveObject(
            register: 'decidesk',
            schema: 'voting-round',
            object: $votingRound
        );

        // Transition motion to voting state.
        try {
            $this->motionService->transitionLifecycle(
                objectId: $motionId,
                objectType: 'motion',
                newState: 'voting',
                actorId: $actorId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not transition motion to voting',
                ['motionId' => $motionId, 'error' => $e->getMessage()]
            );
        }

        // Optionally create a calendar event for the deadline.
        if ($closedAt !== null) {
            try {
                $calendarService = $this->container->get('OCA\OpenRegister\Service\CalendarEventService');
                $calendarService->createEvent(
                    title: 'Stemronde sluit',
                    start: $closedAt,
                    end: $closedAt,
                    description: "Stemronde voor motie {$motionId}",
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: could not create calendar event',
                    ['error' => $e->getMessage()]
                );
            }
        }

        $this->logger->info(
            'Decidesk: voting round opened',
            ['motionId' => $motionId, 'votingRoundId' => ($savedRound['id'] ?? 'unknown'), 'actor' => $actorId]
        );

        return $savedRound;

    }//end openVotingRound()

    /**
     * Cast a vote in a voting round.
     *
     * Checks the round is open, checks for an existing vote from the participant
     * (updates instead of duplicating), enforces one-proxy-per-round for proxy
     * votes, and saves the Vote object.
     *
     * @param string      $votingRoundId The UUID of the VotingRound
     * @param string      $participantId The Nextcloud user ID of the voter
     * @param string      $value         One of: for, against, abstain
     * @param bool        $isProxy       Whether this is a proxy vote
     * @param string|null $delegatorId   Participant ID on whose behalf this is cast
     *
     * @return array<string,mixed> The saved Vote object
     *
     * @throws \RuntimeException When the round is not open, or proxy rules are violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
    ): array {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['closedAt'] ?? null) !== null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} is already closed");
        }

        if (($round['status'] ?? '') !== 'open') {
            throw new \RuntimeException("VotingRound {$votingRoundId} is not open");
        }

        // Proxy enforcement: one proxy per participant per round.
        if ($isProxy === true) {
            if ($delegatorId === null) {
                throw new \RuntimeException('delegatorId is required for proxy votes');
            }

            $existingProxies = $objectService->findAll(
                register: 'decidesk',
                schema: 'vote',
                filters: [
                    'votingRoundId' => $votingRoundId,
                    'delegatorId'   => $delegatorId,
                    'isProxy'       => true,
                ]
            );

            if (empty($existingProxies) === false) {
                throw new \RuntimeException("A proxy vote for delegator {$delegatorId} already exists in this round");
            }
        }

        // Check for existing vote from this participant (overwrite, not duplicate).
        $existingVotes = $objectService->findAll(
            register: 'decidesk',
            schema: 'vote',
            filters: [
                'votingRoundId' => $votingRoundId,
                'participantId' => $participantId,
                'isProxy'       => false,
            ]
        );

        $vote = [
            'votingRoundId' => $votingRoundId,
            'participantId' => $participantId,
            'value'         => $value,
            'weight'        => 1,
            'isProxy'       => $isProxy,
            'delegatorId'   => $delegatorId,
            'castAt'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if ($isProxy === false && empty($existingVotes) === false) {
            // Update existing vote.
            $existing   = reset($existingVotes);
            $vote['id'] = ($existing['id'] ?? $existing['uuid'] ?? null);
        }

        $savedVote = $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);

        $this->logger->info(
            'Decidesk: vote cast',
            [
                'votingRoundId' => $votingRoundId,
                'participantId' => $participantId,
                'value'         => $value,
                'isProxy'       => $isProxy,
            ]
        );

        return $savedVote;

    }//end castVote()

    /**
     * Tally votes for a voting round and determine the result.
     *
     * Counts Vote objects by value, calculates the result
     * (adopted/rejected/tied/invalid), updates the VotingRound fields,
     * and returns the tally array.
     *
     * @param string $votingRoundId The UUID of the VotingRound
     *
     * @return array<string,mixed> Tally data: votesFor, votesAgainst, votesAbstain, result
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        $votes = $objectService->findAll(
            register: 'decidesk',
            schema: 'vote',
            filters: ['votingRoundId' => $votingRoundId]
        );

        $votesFor     = 0;
        $votesAgainst = 0;
        $votesAbstain = 0;

        foreach (($votes ?? []) as $vote) {
            $weight = (int) ($vote['weight'] ?? 1);
            match ($vote['value'] ?? '') {
                'for'     => $votesFor     += $weight,
                'against' => $votesAgainst += $weight,
                'abstain' => $votesAbstain += $weight,
                default   => null,
            };
        }

        $totalVotes = ($votesFor + $votesAgainst + $votesAbstain);

        if ($totalVotes === 0) {
            $result = 'invalid';
        } else if ($votesFor > $votesAgainst) {
            $result = 'adopted';
        } else if ($votesAgainst > $votesFor) {
            $result = 'rejected';
        } else {
            $result = 'tied';
        }

        $tally = [
            'votesFor'     => $votesFor,
            'votesAgainst' => $votesAgainst,
            'votesAbstain' => $votesAbstain,
            'result'       => $result,
        ];

        // Persist tally back to the round.
        $round = array_merge($round, $tally);
        $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        return $tally;

    }//end tallyResults()

    /**
     * Close a voting round.
     *
     * Calls tallyResults(), transitions the Motion lifecycle based on the result,
     * optionally publishes to ORI, and creates a dossier folder if adopted.
     *
     * @param string $votingRoundId The UUID of the VotingRound
     * @param string $actorId       Nextcloud user ID of the chair
     *
     * @return array<string,mixed> The updated VotingRound object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function closeVotingRound(string $votingRoundId, string $actorId): array
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        $tally  = $this->tallyResults(votingRoundId: $votingRoundId);
        $result = $tally['result'];

        $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $round['status']   = 'closed';
        $round = array_merge($round, $tally);

        $savedRound = $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        // Transition the motion lifecycle.
        $motionId = ($round['motionId'] ?? null);
        if ($motionId !== null) {
            $lifecycleMap = [
                'adopted'  => 'adopted',
                'rejected' => 'rejected',
                'tied'     => 'rejected',
                'invalid'  => 'rejected',
            ];
            $newLifecycle = ($lifecycleMap[$result] ?? 'rejected');

            try {
                $this->motionService->transitionLifecycle(
                    objectId: $motionId,
                    objectType: 'motion',
                    newState: $newLifecycle,
                    actorId: $actorId,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: could not transition motion after round close',
                    ['motionId' => $motionId, 'error' => $e->getMessage()]
                );
            }

            // Create dossier folder if motion was adopted.
            if ($result === 'adopted') {
                try {
                    $fileService = $this->getFileService();
                    $motion      = $objectService->getObject(register: 'decidesk', schema: 'motion', uuid: $motionId);
                    $motionSlug  = ($motion['@self']['slug'] ?? $motionId);
                    $fileService->createFolder("motions/{$motionSlug}/");
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Decidesk: could not create dossier folder',
                        ['motionId' => $motionId, 'error' => $e->getMessage()]
                    );
                }
            }
        }//end if

        // Publish to ORI if configured.
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: ORI publication failed',
                ['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
            );
        }

        $this->logger->info(
            'Decidesk: voting round closed',
            ['votingRoundId' => $votingRoundId, 'result' => $result, 'actor' => $actorId]
        );

        return $savedRound;

    }//end closeVotingRound()

    /**
     * Grant a proxy vote from one participant to another.
     *
     * Validates that the delegate is not an observer or guest, stores the proxy
     * relation, and notifies the delegate.
     *
     * @param string $votingRoundId     The UUID of the VotingRound
     * @param string $fromParticipantId The delegating participant's user ID
     * @param string $toParticipantId   The delegate's user ID
     *
     * @return void
     *
     * @throws \RuntimeException When proxy rules are violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void
    {
        $objectService = $this->getObjectService();

        // Validate delegate is not observer/guest.
        $delegate = $objectService->findAll(
            register: 'decidesk',
            schema: 'participant',
            filters: ['userId' => $toParticipantId]
        );

        if (empty($delegate) === false) {
            $role = strtolower((string) ($delegate[0]['role'] ?? ''));
            if (in_array($role, self::DISALLOWED_PROXY_ROLES, true) === true) {
                throw new \RuntimeException(
                    "Participant with role '{$role}' cannot receive a proxy vote"
                );
            }
        }

        // Store proxy as a vote placeholder with isProxy flag.
        $proxy = [
            'votingRoundId' => $votingRoundId,
            'participantId' => $toParticipantId,
            'delegatorId'   => $fromParticipantId,
            'isProxy'       => true,
            'value'         => null,
            'status'        => 'pending',
            'grantedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $proxy);

        // Notify delegate.
        try {
            $notificationService = $this->getNotificationService();
            $notificationService->sendNotification(
                userId: $toParticipantId,
                subject: 'proxy_granted',
                message: "U heeft een stemvolmacht ontvangen van {$fromParticipantId}.",
                objectType: 'voting-round',
                objectId: $votingRoundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send proxy notification',
                ['error' => $e->getMessage()]
            );
        }

        $this->logger->info(
            'Decidesk: proxy granted',
            [
                'votingRoundId' => $votingRoundId,
                'from'          => $fromParticipantId,
                'to'            => $toParticipantId,
            ]
        );

    }//end grantProxy()

    /**
     * Revoke a proxy before the voting round opens.
     *
     * Verifies the round has not yet been opened and removes the proxy relation.
     *
     * @param string $votingRoundId     The UUID of the VotingRound
     * @param string $fromParticipantId The delegating participant's user ID
     *
     * @return void
     *
     * @throws \RuntimeException When the round is already open
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function revokeProxy(string $votingRoundId, string $fromParticipantId): void
    {
        $objectService = $this->getObjectService();

        $round = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);
        if ($round !== null && ($round['openedAt'] ?? null) !== null) {
            throw new \RuntimeException("Cannot revoke proxy: round {$votingRoundId} is already open");
        }

        // Find and delete the proxy vote object.
        $proxies = $objectService->findAll(
            register: 'decidesk',
            schema: 'vote',
            filters: [
                'votingRoundId' => $votingRoundId,
                'delegatorId'   => $fromParticipantId,
                'isProxy'       => true,
                'status'        => 'pending',
            ]
        );

        foreach (($proxies ?? []) as $proxy) {
            $proxyId = ($proxy['id'] ?? $proxy['uuid'] ?? null);
            if ($proxyId !== null) {
                $objectService->deleteObject(register: 'decidesk', schema: 'vote', uuid: $proxyId);
            }
        }

        $this->logger->info(
            'Decidesk: proxy revoked',
            ['votingRoundId' => $votingRoundId, 'from' => $fromParticipantId]
        );

    }//end revokeProxy()
}//end class
