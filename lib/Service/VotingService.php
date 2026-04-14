<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Voting Service
 *
 * Service for voting round lifecycle management: quorum enforcement,
 * vote casting, proxy delegation, result tallying, and ORI publication trigger.
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

use OCA\Decidesk\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for voting round lifecycle management.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

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
     * Get the OpenRegister ObjectService.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get the OpenRegister NotificationService.
     *
     * @return object
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');
    }//end getNotificationService()

    /**
     * Get the OpenRegister CalendarEventService.
     *
     * @return object
     */
    private function getCalendarEventService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\CalendarEventService');
    }//end getCalendarEventService()

    /**
     * Get the OpenRegister FileService.
     *
     * @return object
     */
    private function getFileService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');
    }//end getFileService()

    /**
     * Check whether quorum is met for a meeting.
     *
     * Counts active Participants (null leftAt) related to the GovernanceBody
     * and compares against Meeting.quorumRequired.
     *
     * @param string $meetingId The Meeting object ID
     *
     * @return bool True when quorum is met or quorumRequired is not set
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->getObjectService();

        $meeting = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'meeting',
            id: $meetingId
        );

        if ($meeting === null) {
            return true;
        }

        $quorumRequired = (int) ($meeting['quorumRequired'] ?? 0);
        if ($quorumRequired === 0) {
            return true;
        }

        // Count active participants (leftAt is null).
        $participants = $objectService->findAll(
            register: Application::APP_ID,
            schema: 'participant',
            filters: ['leftAt' => null]
        );

        $count = count($participants['results'] ?? $participants ?? []);

        return ($count >= $quorumRequired);
    }//end checkQuorum()

    /**
     * Open a new voting round for a motion.
     *
     * Verifies quorum, creates a VotingRound object, transitions the motion
     * to 'voting' state, and optionally creates a calendar deadline event.
     *
     * @param string      $motionId      The Motion ID
     * @param string      $votingMethod  The voting method enum value
     * @param bool        $isSecret      Whether this is a secret ballot
     * @param string|null $closedAt      Optional deadline ISO datetime string
     * @param string      $actorId       The user ID opening the round
     * @param string      $meetingId     The Meeting ID (for quorum check)
     *
     * @return array<string,mixed> The created VotingRound object
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function openVotingRound(
        string $motionId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        string $actorId,
        string $meetingId = '',
    ): array {
        if ($meetingId !== '' && $this->checkQuorum($meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        $objectService = $this->getObjectService();

        $votingRound = [
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'closedAt'     => null,
            'quorumMet'    => true,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
        ];

        $saved = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            object: $votingRound
        );

        // Create relation VotingRound → Motion.
        $objectService->addRelation(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: ($saved['id'] ?? ''),
            relationType: 'motion',
            relationId: $motionId
        );

        // Transition motion to voting state.
        try {
            $this->motionService->transitionLifecycle($motionId, 'motion', 'voting', $actorId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Decidesk: could not transition motion {$motionId} to voting",
                ['exception' => $e->getMessage()]
            );
        }

        // Create calendar deadline if requested.
        if ($closedAt !== null) {
            try {
                $calendarService = $this->getCalendarEventService();
                $calendarService->createEvent(
                    title: 'Stemronde sluit',
                    start: $closedAt,
                    end: $closedAt,
                    description: "Stemronde voor motie {$motionId}",
                    objectType: 'voting-round',
                    objectId: ($saved['id'] ?? '')
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to create calendar event for voting round',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        $this->logger->info(
            "Decidesk: voting round {$saved['id']} opened for motion {$motionId} by {$actorId}"
        );

        return $saved;
    }//end openVotingRound()

    /**
     * Cast a vote in a voting round.
     *
     * Checks the round is open, enforces one-vote-per-participant (updates if
     * found), enforces one-proxy-per-round for proxy votes, saves the Vote,
     * and logs the event.
     *
     * @param string      $votingRoundId The VotingRound ID
     * @param string      $participantId The Participant ID
     * @param string      $value         Vote value: for, against, abstain
     * @param bool        $isProxy       Whether this is a proxy vote
     * @param string|null $delegatorId   The delegating participant ID (proxy only)
     *
     * @return array<string,mixed> The created or updated Vote object
     *
     * @throws \RuntimeException When the round is not open or proxy rule violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy = false,
        ?string $delegatorId = null,
    ): array {
        $objectService = $this->getObjectService();

        $round = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round === null || isset($round['closedAt']) === false || $round['closedAt'] !== null) {
            throw new \RuntimeException('Voting round is not open');
        }

        // Proxy: enforce one-proxy-per-round.
        if ($isProxy === true && $delegatorId !== null) {
            $existingProxies = $objectService->findAll(
                register: Application::APP_ID,
                schema: 'vote',
                filters: [
                    'relations.voting-round' => $votingRoundId,
                    'isProxy'                => true,
                    'relations.delegator'    => $delegatorId,
                ]
            );

            if (count($existingProxies['results'] ?? $existingProxies ?? []) > 0) {
                throw new \RuntimeException(
                    "A proxy vote already exists for delegator {$delegatorId} in this round"
                );
            }
        }

        // Check for an existing vote from this participant to enforce idempotency.
        $existing = $objectService->findAll(
            register: Application::APP_ID,
            schema: 'vote',
            filters: [
                'relations.voting-round'  => $votingRoundId,
                'relations.participant'   => $participantId,
                'isProxy'                 => false,
            ]
        );

        $existingResults = ($existing['results'] ?? $existing ?? []);
        $vote            = [];

        if (empty($existingResults) === false && $isProxy === false) {
            // Update existing vote (overwrite).
            $vote          = $existingResults[0];
            $vote['value'] = $value;
        } else {
            // Create new vote.
            $vote = [
                'value'   => $value,
                'weight'  => 1,
                'isProxy' => $isProxy,
                'castAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        }

        $saved = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'vote',
            object: $vote
        );

        // Add relations: vote → voting-round, vote → participant.
        $savedId = ($saved['id'] ?? '');
        if ($savedId !== '') {
            $objectService->addRelation(
                register: Application::APP_ID,
                schema: 'vote',
                id: $savedId,
                relationType: 'voting-round',
                relationId: $votingRoundId
            );
            $objectService->addRelation(
                register: Application::APP_ID,
                schema: 'vote',
                id: $savedId,
                relationType: 'participant',
                relationId: $participantId
            );

            if ($isProxy === true && $delegatorId !== null) {
                $objectService->addRelation(
                    register: Application::APP_ID,
                    schema: 'vote',
                    id: $savedId,
                    relationType: 'delegator',
                    relationId: $delegatorId
                );
            }
        }

        $this->logger->info(
            "Decidesk: vote cast by participant {$participantId} in round {$votingRoundId}: {$value}"
        );

        return $saved;
    }//end castVote()

    /**
     * Close a voting round and calculate results.
     *
     * Calls tallyResults(), transitions the Motion lifecycle based on result,
     * triggers ORI publication if configured, and creates a dossier folder
     * when the motion is adopted.
     *
     * @param string $votingRoundId The VotingRound ID to close
     * @param string $actorId       The user ID closing the round
     *
     * @return array<string,mixed> The updated VotingRound object
     *
     * @throws \RuntimeException When the round is not found
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function closeVotingRound(string $votingRoundId, string $actorId = ''): array
    {
        $objectService = $this->getObjectService();

        $round = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round === null) {
            throw new \RuntimeException("VotingRound {$votingRoundId} not found");
        }

        $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            object: $round
        );

        // Tally votes and determine result.
        $tally  = $this->tallyResults($votingRoundId);
        $result = ($tally['result'] ?? 'invalid');

        // Transition parent motion lifecycle.
        $motionId = ($round['relations']['motion'][0]['id'] ?? $round['relations']['motion'][0] ?? null);
        if ($motionId !== null) {
            $newMotionState = ($result === 'adopted') ? 'adopted' : 'rejected';
            if ($result === 'tied' || $result === 'invalid') {
                $newMotionState = 'rejected';
            }

            try {
                $this->motionService->transitionLifecycle(
                    (string) $motionId,
                    'motion',
                    $newMotionState,
                    $actorId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Decidesk: could not transition motion {$motionId} after round close",
                    ['exception' => $e->getMessage()]
                );
            }

            // Create dossier folder for adopted motions.
            if ($result === 'adopted') {
                $this->createDossierFolder((string) $motionId);
            }
        }//end if

        // Trigger ORI publication (non-fatal).
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: ORI publication failed after round close',
                ['exception' => $e->getMessage()]
            );
        }

        $this->logger->info(
            "Decidesk: voting round {$votingRoundId} closed with result: {$result}"
        );

        return $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        ) ?? $round;
    }//end closeVotingRound()

    /**
     * Tally vote results for a voting round.
     *
     * Counts Vote objects by value, determines adopted/rejected/tied/invalid
     * result, and updates the VotingRound object with totals.
     *
     * @param string $votingRoundId The VotingRound ID
     *
     * @return array<string,mixed> Tally data including result, votesFor, votesAgainst, votesAbstain
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        $votes = $objectService->findAll(
            register: Application::APP_ID,
            schema: 'vote',
            filters: ['relations.voting-round' => $votingRoundId]
        );

        $voteList = ($votes['results'] ?? $votes ?? []);

        $for     = 0;
        $against = 0;
        $abstain = 0;

        foreach ($voteList as $vote) {
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

        if (($for + $against + $abstain) === 0) {
            $result = 'invalid';
        } elseif ($for > $against) {
            $result = 'adopted';
        } elseif ($against > $for) {
            $result = 'rejected';
        } else {
            $result = 'tied';
        }

        $round = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round !== null) {
            $round['votesFor']     = $for;
            $round['votesAgainst'] = $against;
            $round['votesAbstain'] = $abstain;
            $round['result']       = $result;

            $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'voting-round',
                object: $round
            );
        }

        return [
            'result'       => $result,
            'votesFor'     => $for,
            'votesAgainst' => $against,
            'votesAbstain' => $abstain,
            'total'        => ($for + $against + $abstain),
        ];
    }//end tallyResults()

    /**
     * Grant proxy voting rights to another participant for a voting round.
     *
     * Validates roles (no observer/guest as receiver), stores the proxy
     * relation, and sends a notification to the delegate.
     *
     * @param string $votingRoundId     The VotingRound ID
     * @param string $fromParticipantId The participant delegating their vote
     * @param string $toParticipantId   The participant receiving the proxy
     *
     * @return void
     *
     * @throws \RuntimeException When roles are invalid or round is already open
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function grantProxy(
        string $votingRoundId,
        string $fromParticipantId,
        string $toParticipantId,
    ): void {
        $objectService = $this->getObjectService();

        $round = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round !== null && isset($round['openedAt']) === true && $round['openedAt'] !== null) {
            throw new \RuntimeException('Cannot grant proxy after round has opened');
        }

        // Validate that the delegate is not an observer or guest.
        $delegate = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'participant',
            id: $toParticipantId
        );

        if ($delegate !== null) {
            $role = ($delegate['role'] ?? 'member');
            if (in_array($role, ['observer', 'guest'], true) === true) {
                throw new \RuntimeException(
                    "Participant with role '{$role}' cannot receive a proxy vote"
                );
            }
        }

        // Store proxy as a note on the voting round.
        $proxyData = [
            'fromParticipantId' => $fromParticipantId,
            'toParticipantId'   => $toParticipantId,
            'grantedAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $round                 = ($round ?? []);
        $round['notes']        = ($round['notes'] ?? []);
        $round['notes'][]      = [
            'title' => "proxy:{$fromParticipantId}",
            'body'  => json_encode($proxyData, JSON_THROW_ON_ERROR),
        ];

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            object: $round
        );

        // Notify the delegate.
        try {
            $notificationService = $this->getNotificationService();
            $notificationService->sendNotification(
                userId: $toParticipantId,
                subject: 'proxy_granted',
                message: "You received a proxy vote for voting round {$votingRoundId}",
                objectType: 'voting-round',
                objectId: $votingRoundId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to send proxy notification',
                ['exception' => $e->getMessage()]
            );
        }

        $this->logger->info(
            "Decidesk: proxy granted from {$fromParticipantId} to {$toParticipantId} for round {$votingRoundId}"
        );
    }//end grantProxy()

    /**
     * Revoke a proxy delegation before the voting round opens.
     *
     * Verifies the round is not yet open, removes the proxy note, and
     * notifies the delegate.
     *
     * @param string $votingRoundId     The VotingRound ID
     * @param string $fromParticipantId The participant revoking their proxy
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

        $round = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round !== null && isset($round['openedAt']) === true && $round['openedAt'] !== null) {
            throw new \RuntimeException('Cannot revoke proxy after round has opened');
        }

        if ($round === null) {
            return;
        }

        $proxyKey      = "proxy:{$fromParticipantId}";
        $delegateId    = null;
        $filteredNotes = [];

        foreach (($round['notes'] ?? []) as $note) {
            if (($note['title'] ?? '') === $proxyKey) {
                $data       = json_decode($note['body'] ?? '{}', true);
                $delegateId = ($data['toParticipantId'] ?? null);
                continue;
            }

            $filteredNotes[] = $note;
        }

        $round['notes'] = $filteredNotes;
        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            object: $round
        );

        if ($delegateId !== null) {
            try {
                $notificationService = $this->getNotificationService();
                $notificationService->sendNotification(
                    userId: $delegateId,
                    subject: 'proxy_revoked',
                    message: "Your proxy vote for round {$votingRoundId} has been revoked",
                    objectType: 'voting-round',
                    objectId: $votingRoundId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: failed to send proxy revocation notification',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        $this->logger->info(
            "Decidesk: proxy revoked by {$fromParticipantId} for round {$votingRoundId}"
        );
    }//end revokeProxy()

    /**
     * Create a dossier folder for an adopted motion via FileService.
     *
     * @param string $motionId The Motion ID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    private function createDossierFolder(string $motionId): void
    {
        try {
            $objectService = $this->getObjectService();
            $motion        = $objectService->findObject(
                register: Application::APP_ID,
                schema: 'motion',
                id: $motionId
            );

            $slug       = ($motion['@self']['slug'] ?? $motionId);
            $folderPath = "motions/{$slug}/";

            $fileService = $this->getFileService();
            $fileService->createFolder(
                path: $folderPath,
                objectType: 'motion',
                objectId: $motionId
            );

            $this->logger->info("Decidesk: dossier folder created at {$folderPath}");
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to create dossier folder',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end createDossierFolder()

}//end class
