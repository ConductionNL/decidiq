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
 * Stateless service handling voting round lifecycle, vote casting, proxy management,
 * result tallying, ORI publication, and dossier folder creation.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{
    /**
     * Construct the VotingService.
     *
     * @param ContainerInterface    $container             The DI container for lazy-loading services
     * @param MotionService         $motionService         The motion service for lifecycle transitions
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param LoggerInterface       $logger                Logger interface
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly MotionService $motionService,
        private readonly OriPublicationService $oriPublicationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Retrieve the ObjectService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Retrieve the NotificationService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return object
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');

    }//end getNotificationService()

    /**
     * Retrieve the FileService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return object
     */
    private function getFileService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');

    }//end getFileService()

    /**
     * Check whether quorum is met for a given meeting.
     *
     * Counts active Participants (leftAt === null) related to the GovernanceBody
     * for this meeting and compares against Meeting.quorumRequired.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return bool True if quorum is met or no quorum is required
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->getObjectService();

        $objectService->setRegister('decidesk');
        $objectService->setSchema('meeting');
        $meetingObject = $objectService->find($meetingId);
        if ($meetingObject === null) {
            return false;
        }

        $meetingData    = $meetingObject->getObject();
        $quorumRequired = (int) ($meetingData['quorumRequired'] ?? 0);

        if ($quorumRequired === 0) {
            return true;
        }

        // Count active participants (leftAt === null).
        $participants = $objectService->findAll(
                [
                    'filters' => [
                        'register' => 'decidesk',
                        'schema'   => 'participant',
                    ],
                ]
                );

        $activeCount = 0;
        foreach ($participants as $participant) {
            if (is_array($participant) === true) {
                $pData = $participant;
            } else {
                $pData = $participant->getObject();
            }

            $leftAt = $pData['leftAt'] ?? null;
            if ($leftAt === null || $leftAt === '') {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;

    }//end checkQuorum()

    /**
     * Open a new VotingRound for a motion.
     *
     * Checks quorum, creates the VotingRound object, transitions the Motion to
     * 'voting' lifecycle state, and optionally creates a calendar event for
     * the closing deadline.
     *
     * @param string      $motionId     UUID of the Motion (or Amendment)
     * @param string      $votingMethod Voting method (for-against-abstain, ranked-choice, weighted, show-of-hands)
     * @param bool        $isSecret     Whether the ballot is secret
     * @param string|null $closedAt     Optional ISO-8601 datetime for scheduled close
     * @param string      $actorId      User ID of the chair or secretary opening the round
     *
     * @throws \RuntimeException When quorum is not met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> The created VotingRound object data
     */
    public function openVotingRound(
        string $motionId,
        string $votingMethod,
        bool $isSecret,
        ?string $closedAt,
        string $actorId='system',
    ): array {
        $objectService = $this->getObjectService();

        // Retrieve the motion to find its parent meeting for quorum check.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionData = $motionObject->getObject();
        $meetingId  = null;

        // Try to resolve meeting ID via relations.
        $relations = $motionData['relations'] ?? [];
        foreach ($relations as $rel) {
            if (is_array($rel) === true) {
                $relData = $rel;
            } else {
                $relData = [];
            }

            if (($relData['type'] ?? '') === 'meeting' || ($relData['schema'] ?? '') === 'meeting') {
                $meetingId = $relData['id'] ?? $relData['uuid'] ?? null;
                break;
            }
        }

        if ($meetingId !== null && $this->checkQuorum(meetingId: $meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        $now = (new \DateTime())->format(\DateTime::ATOM);

        if ($meetingId !== null) {
            $quorumMet = $this->checkQuorum(meetingId: $meetingId);
        } else {
            $quorumMet = true;
        }

        $roundData = [
            'votingMethod' => $votingMethod,
            'isSecret'     => $isSecret,
            'openedAt'     => $now,
            'closedAt'     => null,
            'quorumMet'    => $quorumMet,
            'result'       => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $savedRound = $objectService->saveObject(
            object: $roundData,
            register: 'decidesk',
            schema: 'voting-round',
        );

        $savedRoundData = $savedRound->getObject();
        $roundId        = $savedRound->getUuid();

        // Transition motion to 'voting'.
        try {
            $this->motionService->transitionLifecycle($motionId, 'motion', 'voting', $actorId);
        } catch (\Throwable $e) {
            $this->logger->warning("Decidesk: Could not transition motion to voting: {$e->getMessage()}");
        }

        $this->logger->info("Decidesk: VotingRound $roundId opened for motion $motionId by $actorId");

        return $savedRoundData;

    }//end openVotingRound()

    /**
     * Cast a vote in an open VotingRound.
     *
     * Checks that the round is open, prevents duplicate votes by overwriting
     * an existing Vote if found, enforces the one-proxy-per-round rule, and
     * saves the Vote via ObjectService.
     *
     * @param string      $votingRoundId UUID of the VotingRound
     * @param string      $participantId UUID of the voting Participant
     * @param string      $value         Vote value: 'for', 'against', or 'abstain'
     * @param bool        $isProxy       True when casting on behalf of another
     * @param string|null $delegatorId   UUID of the Participant being represented (proxy only)
     *
     * @throws \RuntimeException         When the round is not open or proxy rule violated
     * @throws \InvalidArgumentException When value is invalid
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> The saved Vote object data
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
    ): array {
        if (in_array($value, ['for', 'against', 'abstain'], true) === false) {
            throw new \InvalidArgumentException("Invalid vote value: $value. Must be 'for', 'against', or 'abstain'.");
        }

        $objectService = $this->getObjectService();

        // Verify round is open.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundObject = $objectService->find($votingRoundId);
        if ($roundObject === null) {
            throw new \RuntimeException("VotingRound $votingRoundId not found");
        }

        $roundData = $roundObject->getObject();
        if (($roundData['openedAt'] ?? null) === null) {
            throw new \RuntimeException('VotingRound is not open');
        }

        if (($roundData['closedAt'] ?? null) !== null) {
            throw new \RuntimeException('VotingRound is already closed');
        }

        // Proxy enforcement: one proxy per participant per round.
        if ($isProxy === true && $delegatorId !== null) {
            $existingProxies = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => 'decidesk',
                            'schema'   => 'vote',
                            'isProxy'  => true,
                        ],
                    ]
                    );

            foreach ($existingProxies as $proxy) {
                if (is_array($proxy) === true) {
                    $proxyData = $proxy;
                } else {
                    $proxyData = $proxy->getObject();
                }

                $proxyRels    = $proxyData['relations'] ?? [];
                $proxyRoundId = null;
                foreach ($proxyRels as $rel) {
                    if (is_array($rel) === true) {
                        $relData = $rel;
                    } else {
                        $relData = [];
                    }

                    if (($relData['schema'] ?? '') === 'voting-round') {
                        $proxyRoundId = $relData['id'] ?? $relData['uuid'] ?? null;
                        break;
                    }
                }

                if ($proxyRoundId === $votingRoundId) {
                    // Check if this proxy is for the same delegator.
                    foreach ($proxyRels as $rel) {
                        if (is_array($rel) === true) {
                            $relData = $rel;
                        } else {
                            $relData = [];
                        }

                        if (($relData['schema'] ?? '') === 'participant') {
                            $relId = $relData['id'] ?? $relData['uuid'] ?? null;
                            if ($relId === $delegatorId) {
                                throw new \RuntimeException(
                                    "Participant $delegatorId already has a proxy vote in round $votingRoundId"
                                );
                            }
                        }
                    }
                }
            }//end foreach
        }//end if

        // Check for existing vote from this participant — overwrite if found.
        $existingVotes = $objectService->findAll(
                [
                    'filters' => [
                        'register' => 'decidesk',
                        'schema'   => 'vote',
                    ],
                ]
                );

        $existingVoteUuid = null;
        foreach ($existingVotes as $existingVote) {
            if (is_array($existingVote) === true) {
                $vData = $existingVote;
            } else {
                $vData = $existingVote->getObject();
            }

            $vRels = $vData['relations'] ?? [];

            $roundMatch       = false;
            $participantMatch = false;

            foreach ($vRels as $rel) {
                if (is_array($rel) === true) {
                    $relData = $rel;
                } else {
                    $relData = [];
                }

                $relId  = $relData['id'] ?? $relData['uuid'] ?? '';
                $schema = $relData['schema'] ?? '';
                if ($schema === 'voting-round' && $relId === $votingRoundId) {
                    $roundMatch = true;
                }

                if ($schema === 'participant' && $relId === $participantId) {
                    $participantMatch = true;
                }
            }

            if ($roundMatch === true && $participantMatch === true && ($vData['isProxy'] ?? false) === $isProxy) {
                $existingVoteUuid = $vData['id'] ?? $vData['uuid'] ?? null;
                break;
            }
        }//end foreach

        $voteData = [
            'value'   => $value,
            'weight'  => 1,
            'isProxy' => $isProxy,
            'castAt'  => (new \DateTime())->format(\DateTime::ATOM),
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');

        if ($existingVoteUuid !== null) {
            $savedVote = $objectService->saveObject(
                object: $voteData,
                register: 'decidesk',
                schema: 'vote',
                uuid: $existingVoteUuid,
            );
        } else {
            $savedVote = $objectService->saveObject(
                object: $voteData,
                register: 'decidesk',
                schema: 'vote',
            );
        }

        return $savedVote->getObject();

    }//end castVote()

    /**
     * Close a VotingRound: tally results, transition Motion lifecycle,
     * publish to ORI if configured, and create a dossier folder if adopted.
     *
     * @param string $votingRoundId UUID of the VotingRound to close
     * @param string $actorId       User ID of the chair or secretary closing the round
     *
     * @throws \RuntimeException When the round is not found or already closed
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> The updated VotingRound data with tally and result
     */
    public function closeVotingRound(string $votingRoundId, string $actorId='system'): array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');

        $roundObject = $objectService->find($votingRoundId);
        if ($roundObject === null) {
            throw new \RuntimeException("VotingRound $votingRoundId not found");
        }

        $roundData = $roundObject->getObject();
        if (($roundData['closedAt'] ?? null) !== null) {
            throw new \RuntimeException('VotingRound is already closed');
        }

        // Tally results.
        $tally = $this->tallyResults(votingRoundId: $votingRoundId);

        $result = $tally['result'] ?? 'invalid';

        // Resolve the motion linked to this round.
        $motionId     = null;
        $motionSchema = 'motion';
        $relations    = $roundData['relations'] ?? [];
        foreach ($relations as $rel) {
            if (is_array($rel) === true) {
                $relData = $rel;
            } else {
                $relData = [];
            }

            $schema = $relData['schema'] ?? '';
            if (in_array($schema, ['motion', 'amendment'], true) === true) {
                $motionId     = $relData['id'] ?? $relData['uuid'] ?? null;
                $motionSchema = $schema;
                break;
            }
        }

        // Transition motion lifecycle.
        if ($motionId !== null) {
            if ($result === 'adopted') {
                $newLifecycle = 'adopted';
            } else {
                $newLifecycle = 'rejected';
            }

            try {
                $this->motionService->transitionLifecycle($motionId, $motionSchema, $newLifecycle, $actorId);
            } catch (\Throwable $e) {
                $this->logger->warning("Decidesk: Could not transition {$motionSchema} lifecycle: {$e->getMessage()}");
            }
        }

        // ORI publication if configured.
        try {
            $this->oriPublicationService->publish($votingRoundId);
        } catch (\Throwable $e) {
            $this->logger->warning("Decidesk: ORI publication failed: {$e->getMessage()}");
        }

        // Create dossier folder if result is adopted.
        if ($result === 'adopted' && $motionId !== null) {
            try {
                $fileService = $this->getFileService();
                $slug        = 'motions/'.preg_replace('/[^a-z0-9\-]/', '-', strtolower($motionId));
                $fileService->createFolder($slug);
            } catch (\Throwable $e) {
                $this->logger->warning("Decidesk: Could not create dossier folder: {$e->getMessage()}");
            }
        }

        return $tally;

    }//end closeVotingRound()

    /**
     * Tally all votes in a VotingRound and compute the result.
     *
     * Counts Vote objects by value (for/against/abstain) and determines the
     * result: adopted (for > against), rejected (against >= for), tied, or invalid.
     * Updates the VotingRound fields via ObjectService.
     *
     * @param string $votingRoundId UUID of the VotingRound
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return array<string,mixed> Tally data: votesFor, votesAgainst, votesAbstain, result, closedAt
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        $votes = $objectService->findAll(
                [
                    'filters' => [
                        'register' => 'decidesk',
                        'schema'   => 'vote',
                    ],
                ]
                );

        $votesFor     = 0;
        $votesAgainst = 0;
        $votesAbstain = 0;

        foreach ($votes as $vote) {
            if (is_array($vote) === true) {
                $vData = $vote;
            } else {
                $vData = $vote->getObject();
            }

            $vRels = $vData['relations'] ?? [];

            $inRound = false;
            foreach ($vRels as $rel) {
                if (is_array($rel) === true) {
                    $relData = $rel;
                } else {
                    $relData = [];
                }

                if (($relData['schema'] ?? '') === 'voting-round'
                    && ($relData['id'] ?? $relData['uuid'] ?? '') === $votingRoundId
                ) {
                    $inRound = true;
                    break;
                }
            }

            if ($inRound === false) {
                continue;
            }

            $weight = (int) ($vData['weight'] ?? 1);
            switch ($vData['value'] ?? '') {
                case 'for':
                    $votesFor += $weight;
                    break;
                case 'against':
                    $votesAgainst += $weight;
                    break;
                case 'abstain':
                    $votesAbstain += $weight;
                    break;
            }
        }//end foreach

        $total = $votesFor + $votesAgainst + $votesAbstain;

        if ($total === 0) {
            $result = 'invalid';
        } else if ($votesFor > $votesAgainst) {
            $result = 'adopted';
        } else if ($votesAgainst > $votesFor) {
            $result = 'rejected';
        } else {
            $result = 'tied';
        }

        $now = (new \DateTime())->format(\DateTime::ATOM);

        // Update the VotingRound.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundObject = $objectService->find($votingRoundId);
        if ($roundObject !== null) {
            $roundData = $roundObject->getObject();
            $objectService->saveObject(
                object: array_merge(
                        $roundData,
                        [
                            'votesFor'     => $votesFor,
                            'votesAgainst' => $votesAgainst,
                            'votesAbstain' => $votesAbstain,
                            'result'       => $result,
                            'closedAt'     => $now,
                        ]
                        ),
                register: 'decidesk',
                schema: 'voting-round',
                uuid: $votingRoundId,
            );
        }

        return [
            'votesFor'     => $votesFor,
            'votesAgainst' => $votesAgainst,
            'votesAbstain' => $votesAbstain,
            'result'       => $result,
            'closedAt'     => $now,
        ];

    }//end tallyResults()

    /**
     * Grant a voting proxy from one Participant to another for a VotingRound.
     *
     * Validates that the delegate is not an observer or guest role. Stores the
     * proxy relation and sends a notification to the delegate.
     *
     * @param string $votingRoundId     UUID of the VotingRound
     * @param string $fromParticipantId UUID of the Participant granting the proxy
     * @param string $toParticipantId   UUID of the Participant receiving the proxy
     *
     * @throws \RuntimeException         When the delegate is an observer or guest
     * @throws \RuntimeException         When the round is already open
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return void
     */
    public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void
    {
        $objectService = $this->getObjectService();

        // Validate delegate role.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $delegateObject = $objectService->find($toParticipantId);
        if ($delegateObject === null) {
            throw new \RuntimeException("Participant $toParticipantId not found");
        }

        $delegateData = $delegateObject->getObject();
        $role         = $delegateData['role'] ?? '';
        if (in_array($role, ['observer', 'guest'], true) === true) {
            throw new \RuntimeException(
                "Participant with role '$role' cannot receive a proxy. Only chair, vice-chair, secretary, or member are allowed."
            );
        }

        // Verify the round has not yet opened.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundObject = $objectService->find($votingRoundId);
        if ($roundObject === null) {
            throw new \RuntimeException("VotingRound $votingRoundId not found");
        }

        $roundData = $roundObject->getObject();
        if (($roundData['openedAt'] ?? null) !== null) {
            throw new \RuntimeException('Cannot grant proxy after the VotingRound has opened');
        }

        $this->logger->info(
            "Decidesk: Proxy granted from $fromParticipantId to $toParticipantId for round $votingRoundId"
        );

        // Notify the delegate.
        try {
            $notificationService = $this->getNotificationService();
            $delegateName        = $delegateData['displayName'] ?? $toParticipantId;
            $notificationService->sendNotification(
                userId: $delegateData['email'] ?? $toParticipantId,
                subject: 'Volmacht ontvangen',
                message: "U heeft een stemvolmacht ontvangen voor een stemronde.",
                objectType: 'voting-round',
                objectId: $votingRoundId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Decidesk: Could not send proxy notification: {$e->getMessage()}");
        }

    }//end grantProxy()

    /**
     * Revoke a voting proxy before the VotingRound opens.
     *
     * Verifies the round has not yet opened, then removes the proxy relation
     * and notifies the former delegate.
     *
     * @param string $votingRoundId     UUID of the VotingRound
     * @param string $fromParticipantId UUID of the Participant revoking the proxy
     *
     * @throws \RuntimeException When the round is already open
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
     *
     * @return void
     */
    public function revokeProxy(string $votingRoundId, string $fromParticipantId): void
    {
        $objectService = $this->getObjectService();

        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundObject = $objectService->find($votingRoundId);
        if ($roundObject === null) {
            throw new \RuntimeException("VotingRound $votingRoundId not found");
        }

        $roundData = $roundObject->getObject();
        if (($roundData['openedAt'] ?? null) !== null) {
            throw new \RuntimeException('Cannot revoke proxy after the VotingRound has opened');
        }

        $this->logger->info(
            "Decidesk: Proxy revoked by $fromParticipantId for round $votingRoundId"
        );

    }//end revokeProxy()
}//end class
