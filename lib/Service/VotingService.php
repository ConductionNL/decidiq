<?php

/**
 * Decidesk Voting Service
 *
 * Service for managing VotingRound lifecycle, quorum enforcement, vote casting,
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

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing VotingRound lifecycle and vote casting.
 *
 * Handles quorum checking, opening/closing voting rounds, casting votes,
 * proxy delegation/revocation, tallying results, and triggering ORI publication.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{

    /**
     * Valid vote values.
     *
     * @var array<string>
     */
    private const VALID_VOTE_VALUES = ['for', 'against', 'abstain'];

    /**
     * Participant roles excluded from receiving proxies (REQ-PRX-005).
     *
     * @var array<string>
     */
    private const PROXY_EXCLUDED_ROLES = ['observer', 'guest'];

    /**
     * Constructor for VotingService.
     *
     * @param ContainerInterface     $container             The DI container
     * @param IAppConfig             $appConfig             The app config interface
     * @param LoggerInterface        $logger                The logger
     * @param OriPublicationService  $oriPublicationService The ORI publication service
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private OriPublicationService $oriPublicationService,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the OpenRegister NotificationService from the container.
     *
     * @return object
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');

    }//end getNotificationService()

    /**
     * Get the OpenRegister FileService from the container.
     *
     * @return object
     */
    private function getFileService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');

    }//end getFileService()

    /**
     * Check whether quorum is met for the given Meeting.
     *
     * Counts active Participants (non-null leftAt) related to the GovernanceBody
     * and compares against Meeting.quorumRequired.
     *
     * @param string $meetingId The meeting UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return bool True when quorum is met.
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->getObjectService();
        $meeting       = $objectService->getObject(register: 'decidesk', schema: 'meeting', id: $meetingId);

        $quorumRequired = (int) ($meeting['quorumRequired'] ?? 0);

        // Count active participants: those with no leftAt value.
        $activeParticipants = $objectService->findObjects(
            register: 'decidesk',
            schema: 'participant',
            filters: ['leftAt' => null],
        );

        return count($activeParticipants) >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a new VotingRound for the given Motion.
     *
     * Verifies quorum is met, creates a VotingRound object, transitions
     * the Motion to 'voting' lifecycle, and optionally creates a calendar
     * event when a closing deadline is set.
     *
     * @param string      $motionId     The motion UUID
     * @param string      $votingMethod The voting method (for-against-abstain, show-of-hands, etc.)
     * @param bool        $isSecret     Whether the vote is by secret ballot
     * @param string|null $closedAt     Optional ISO 8601 deadline for closing
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @return array<string,mixed> The created VotingRound object
     */
    public function openVotingRound(
        string $motionId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
    ): array {
        $objectService = $this->getObjectService();
        $motion        = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);

        // Resolve meeting from Motion relations for quorum check.
        $meetingId = ($motion['relations']['meeting'][0]['id'] ?? null);
        if ($meetingId !== null && $this->checkQuorum(meetingId: $meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        // Create VotingRound object.
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

        $savedRound = $objectService->saveObject(
            register: 'decidesk',
            schema: 'voting-round',
            object: $votingRound,
        );

        // Transition motion lifecycle to 'voting'.
        $motion['lifecycle'] = 'voting';
        $motion['status']    = 'voting';
        $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

        // Create a calendar event if a deadline is set.
        if ($closedAt !== null) {
            try {
                $calendarService = $this->container->get('OCA\OpenRegister\Service\CalendarEventService');
                $calendarService->createEvent(
                    title: "Stemronde sluit: " . ($motion['title'] ?? $motionId),
                    startAt: $closedAt,
                    endAt: $closedAt,
                    objectType: 'voting-round',
                    objectId: ($savedRound['id'] ?? ''),
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: could not create calendar event for voting round',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        $this->logger->info("Decidesk: VotingRound opened for motion {$motionId}");

        return $savedRound;

    }//end openVotingRound()

    /**
     * Cast a vote in an open VotingRound.
     *
     * Checks the round is open, enforces the one-proxy-per-round rule,
     * checks for an existing vote from the Participant (overwrites if found),
     * and saves the Vote via ObjectService.saveObject().
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The voting participant UUID
     * @param string      $value         Vote value: 'for', 'against', or 'abstain'
     * @param bool        $isProxy       Whether this is a proxy vote
     * @param string|null $delegatorId   The original voter UUID (required when isProxy = true)
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @throws \InvalidArgumentException When the vote value is invalid
     * @throws \RuntimeException         When the round is closed or proxy rule is violated
     *
     * @return array<string,mixed> The saved Vote object
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
    ): array {
        if (in_array(needle: $value, haystack: self::VALID_VOTE_VALUES, strict: true) === false) {
            throw new \InvalidArgumentException("Invalid vote value: {$value}");
        }

        $objectService = $this->getObjectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', id: $votingRoundId);

        if (($round['closedAt'] ?? null) !== null) {
            throw new \RuntimeException('VotingRound is already closed');
        }

        // Enforce one-proxy-per-round rule.
        if ($isProxy === true) {
            if ($delegatorId === null) {
                throw new \InvalidArgumentException('delegatorId is required for proxy votes');
            }

            $existingProxies = $objectService->findObjects(
                register: 'decidesk',
                schema: 'vote',
                filters: [
                    'votingRound' => $votingRoundId,
                    'delegator'   => $delegatorId,
                    'isProxy'     => true,
                ],
            );

            if (count($existingProxies) > 0) {
                throw new \RuntimeException('Only one proxy vote per participant per round is allowed');
            }
        }

        // Check for existing vote from this participant and overwrite if found.
        $existingVotes = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: [
                'votingRound' => $votingRoundId,
                'participant' => $participantId,
                'isProxy'     => false,
            ],
        );

        $voteObject = [
            'value'   => $value,
            'weight'  => 1,
            'isProxy' => $isProxy,
            'castAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if ($delegatorId !== null) {
            $voteObject['delegator'] = $delegatorId;
        }

        if (count($existingVotes) > 0) {
            // Overwrite existing vote.
            $voteObject['id'] = ($existingVotes[0]['id'] ?? null);
        }

        $savedVote = $objectService->saveObject(
            register: 'decidesk',
            schema: 'vote',
            object: $voteObject,
        );

        $this->logger->info(
            "Decidesk: Vote '{$value}' cast in round {$votingRoundId} by participant {$participantId}"
        );

        return $savedVote;

    }//end castVote()

    /**
     * Close a VotingRound and calculate the result.
     *
     * Calls tallyResults(), transitions the Motion lifecycle, triggers ORI
     * publication if configured, and creates a dossier folder if the motion
     * is adopted (REQ-RES-003).
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> The updated VotingRound with results
     */
    public function closeVotingRound(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', id: $votingRoundId);

        // Tally results and update the round.
        $round = $this->tallyResults(votingRoundId: $votingRoundId);

        // Set closedAt timestamp.
        $round['closedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $savedRound        = $objectService->saveObject(
            register: 'decidesk',
            schema: 'voting-round',
            object: $round,
        );

        $result = ($savedRound['result'] ?? null);

        // Find the linked Motion via relations and update its lifecycle.
        $motionId = ($round['relations']['motion'][0]['id'] ?? null);
        if ($motionId !== null) {
            $motion              = $objectService->getObject(register: 'decidesk', schema: 'motion', id: $motionId);
            $lifecycleState      = ($result === 'adopted' ? 'adopted' : ($result === 'rejected' ? 'rejected' : 'rejected'));
            $motion['lifecycle'] = $lifecycleState;
            $motion['status']    = $lifecycleState;
            $objectService->saveObject(register: 'decidesk', schema: 'motion', object: $motion);

            // Create dossier folder for adopted motions (REQ-RES-003).
            if ($result === 'adopted') {
                $this->createDossierFolder(motion: $motion, motionId: $motionId);
            }
        }

        // Trigger ORI publication if configured.
        try {
            $this->oriPublicationService->publish(votingRoundId: $votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: ORI publication failed on round close',
                ['exception' => $e->getMessage(), 'votingRoundId' => $votingRoundId]
            );
        }

        $this->logger->info("Decidesk: VotingRound {$votingRoundId} closed with result '{$result}'");

        return $savedRound;

    }//end closeVotingRound()

    /**
     * Tally all votes for a VotingRound and calculate the result.
     *
     * Counts Vote objects by value, determines adopted/rejected/tied/invalid,
     * and updates the VotingRound fields via ObjectService.saveObject().
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> The updated VotingRound object
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', id: $votingRoundId);

        $votes = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: ['votingRound' => $votingRoundId],
        );

        $votesFor     = 0;
        $votesAgainst = 0;
        $votesAbstain = 0;

        foreach ($votes as $vote) {
            $value  = ($vote['value'] ?? '');
            $weight = (int) ($vote['weight'] ?? 1);

            match ($value) {
                'for'     => $votesFor     += $weight,
                'against' => $votesAgainst += $weight,
                'abstain' => $votesAbstain += $weight,
                default   => null,
            };
        }

        // Determine result.
        $result = 'invalid';
        if ($votesFor > $votesAgainst) {
            $result = 'adopted';
        } elseif ($votesAgainst > $votesFor) {
            $result = 'rejected';
        } elseif ($votesFor === $votesAgainst && ($votesFor + $votesAgainst) > 0) {
            $result = 'tied';
        }

        $round['votesFor']     = $votesFor;
        $round['votesAgainst'] = $votesAgainst;
        $round['votesAbstain'] = $votesAbstain;
        $round['result']       = $result;

        return $objectService->saveObject(
            register: 'decidesk',
            schema: 'voting-round',
            object: $round,
        );

    }//end tallyResults()

    /**
     * Grant a proxy voting right for a VotingRound.
     *
     * Validates both Participants are active GovernanceBody members (not
     * observer/guest), stores the proxy relation, and notifies the delegate.
     *
     * @param string $votingRoundId      The voting round UUID
     * @param string $fromParticipantId  The delegating participant UUID
     * @param string $toParticipantId    The receiving participant UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @throws \RuntimeException When the receiving participant has an excluded role
     *
     * @return void
     */
    public function grantProxy(
        string $votingRoundId,
        string $fromParticipantId,
        string $toParticipantId,
    ): void {
        $objectService = $this->getObjectService();
        $toParticipant = $objectService->getObject(register: 'decidesk', schema: 'participant', id: $toParticipantId);

        $role = strtolower(($toParticipant['role'] ?? ''));
        if (in_array(needle: $role, haystack: self::PROXY_EXCLUDED_ROLES, strict: true) === true) {
            throw new \RuntimeException(
                "Participant with role '{$role}' cannot receive a proxy (observers and guests excluded)"
            );
        }

        // Store proxy as a Vote placeholder with isProxy=true and no value yet.
        $proxyRecord = [
            'isProxy'      => true,
            'votingRound'  => $votingRoundId,
            'participant'  => $toParticipantId,
            'delegator'    => $fromParticipantId,
            'value'        => null,
            'weight'       => 1,
        ];

        $objectService->saveObject(
            register: 'decidesk',
            schema: 'vote',
            object: $proxyRecord,
        );

        // Notify the delegate.
        try {
            $notificationService = $this->getNotificationService();
            $notificationService->sendNotification(
                userId: $toParticipantId,
                subject: 'proxy_granted',
                message: $fromParticipantId,
                objectType: 'voting-round',
                objectId: $votingRoundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not send proxy grant notification',
                ['exception' => $e->getMessage()]
            );
        }

        $this->logger->info(
            "Decidesk: Proxy granted from {$fromParticipantId} to {$toParticipantId} for round {$votingRoundId}"
        );

    }//end grantProxy()

    /**
     * Revoke a proxy voting right before the VotingRound opens.
     *
     * Verifies the round has not opened yet, removes the proxy record,
     * and notifies the previously-assigned delegate.
     *
     * @param string $votingRoundId     The voting round UUID
     * @param string $fromParticipantId The delegating participant UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @throws \RuntimeException When the round has already opened
     *
     * @return void
     */
    public function revokeProxy(string $votingRoundId, string $fromParticipantId): void
    {
        $objectService = $this->getObjectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', id: $votingRoundId);

        if (($round['openedAt'] ?? null) !== null && ($round['closedAt'] ?? null) === null) {
            // Round is actively open — check if votes have been cast yet.
            // Per spec, proxy is revocable before the round opens.
            $votesAlreadyCast = $objectService->findObjects(
                register: 'decidesk',
                schema: 'vote',
                filters: [
                    'votingRound' => $votingRoundId,
                    'delegator'   => $fromParticipantId,
                    'isProxy'     => true,
                    'value'       => ['for', 'against', 'abstain'],
                ],
            );

            if (count($votesAlreadyCast) > 0) {
                throw new \RuntimeException('Proxy vote has already been cast; revocation is not possible');
            }
        }

        // Find and delete the proxy record.
        $proxyRecords = $objectService->findObjects(
            register: 'decidesk',
            schema: 'vote',
            filters: [
                'votingRound' => $votingRoundId,
                'delegator'   => $fromParticipantId,
                'isProxy'     => true,
                'value'       => null,
            ],
        );

        foreach ($proxyRecords as $proxyRecord) {
            $toParticipantId = ($proxyRecord['participant'] ?? null);
            $objectService->deleteObject(register: 'decidesk', schema: 'vote', id: $proxyRecord['id']);

            // Notify the delegate of revocation.
            if ($toParticipantId !== null) {
                try {
                    $notificationService = $this->getNotificationService();
                    $notificationService->sendNotification(
                        userId: $toParticipantId,
                        subject: 'proxy_revoked',
                        message: $fromParticipantId,
                        objectType: 'voting-round',
                        objectId: $votingRoundId,
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Decidesk: could not send proxy revoke notification',
                        ['exception' => $e->getMessage()]
                    );
                }
            }
        }

        $this->logger->info(
            "Decidesk: Proxy revoked by {$fromParticipantId} for round {$votingRoundId}"
        );

    }//end revokeProxy()

    /**
     * Create a dossier folder for an adopted Motion (REQ-RES-003).
     *
     * Calls FileService.createFolder() under motions/{motionSlug}/
     * and attaches a _files metadata link to the Motion.
     *
     * @param array<string,mixed> $motion    The motion object array
     * @param string              $motionId  The motion UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return void
     */
    private function createDossierFolder(array $motion, string $motionId): void
    {
        $slug   = ($motion['@self']['slug'] ?? $motionId);
        $folder = "motions/{$slug}/";

        try {
            $fileService = $this->getFileService();
            $fileService->createFolder(path: $folder);

            // Attach _files metadata link to the Motion.
            $motion['_files'] = $folder;
            $this->getObjectService()->saveObject(
                register: 'decidesk',
                schema: 'motion',
                object: $motion,
            );

            $this->logger->info("Decidesk: Dossier folder created at {$folder} for motion {$motionId}");
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not create dossier folder',
                ['exception' => $e->getMessage(), 'motionId' => $motionId]
            );
        }

    }//end createDossierFolder()

}//end class
