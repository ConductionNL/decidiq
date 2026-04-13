<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk Voting Service
 *
 * Service for managing voting rounds, casting votes, tallying results,
 * and handling proxy delegation within Decidesk meetings.
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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing voting rounds, casting votes, tallying results,
 * and handling proxy delegation within Decidesk meetings.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingService
{
    /**
     * Constructor for the VotingService.
     *
     * @param ContainerInterface $container     The dependency injection container
     * @param LoggerInterface    $logger        The logger
     * @param IAppConfig         $appConfig     The app config interface
     * @param MotionService      $motionService The motion service for lifecycle transitions
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private MotionService $motionService,
    ) {
    }//end __construct()

    /**
     * Retrieve the ObjectService from the OpenRegister app.
     *
     * @return object The ObjectService instance
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Check whether a meeting has quorum based on active participants.
     *
     * Fetches the meeting by ID, determines the quorum requirement, then counts
     * all active participants (those who have not left) for the governance body.
     *
     * @param string $meetingId The ID of the meeting to check quorum for
     *
     * @return bool True if quorum is met, false otherwise
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function checkQuorum(string $meetingId): bool
    {
        $objectService = $this->getObjectService();

        $meeting        = $objectService->getObject('meeting', $meetingId);
        $quorumRequired = $meeting['quorumRequired'] ?? 0;

        $governanceBodyId = $meeting['governanceBody'] ?? null;
        if ($governanceBodyId === null) {
            $this->logger->warning('Meeting has no governance body assigned', ['meetingId' => $meetingId]);
            return false;
        }

        $participants = $objectService->getObjects(
            'participant',
            [
                'governanceBody' => $governanceBodyId,
            ]
        );

        $activeCount = 0;
        foreach ($participants as $participant) {
            if (($participant['leftAt'] ?? null) === null) {
                $activeCount++;
            }
        }

        return $activeCount >= $quorumRequired;
    }//end checkQuorum()

    /**
     * Open a new voting round for a given motion.
     *
     * Verifies quorum is met for the related meeting, creates a VotingRound
     * object with isOpen=true, relates it to the motion, and transitions the
     * motion to the 'voting' state via MotionService::transitionLifecycle().
     *
     * @param string      $motionId     The ID of the motion to vote on
     * @param string      $votingMethod The voting method (e.g. 'show_of_hands', 'roll_call')
     * @param bool        $isSecret     Whether the vote is secret
     * @param string      $actorId      The UID of the actor opening the round
     * @param string|null $closedAt     Optional scheduled close time (ISO 8601)
     *
     * @return array The created VotingRound as an associative array
     *
     * @throws \InvalidArgumentException When the motion lifecycle transition is not allowed
     * @throws \RuntimeException         When quorum is not met
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function openVotingRound(
        string $motionId,
        string $votingMethod,
        bool $isSecret,
        string $actorId,
        ?string $closedAt=null,
    ): array {
        $objectService = $this->getObjectService();

        // Fetch the motion and its related meeting.
        $motion    = $objectService->getObject('motion', $motionId);
        $meetingId = $motion['meeting'] ?? null;

        if ($meetingId === null) {
            throw new \RuntimeException('Motion heeft geen gekoppelde vergadering');
        }

        // Verify quorum.
        if ($this->checkQuorum(meetingId: $meetingId) === false) {
            throw new \RuntimeException('Quorum niet bereikt');
        }

        // Create VotingRound object. isOpen=true marks the round as active for casting.
        $votingRound = $objectService->saveObject(
                'votingRound',
                [
                    'votingMethod' => $votingMethod,
                    'isSecret'     => $isSecret,
                    'isOpen'       => true,
                    'openedAt'     => (new \DateTimeImmutable())->format('c'),
                    'closedAt'     => $closedAt,
                    'quorumMet'    => true,
                    'result'       => null,
                    'votesFor'     => 0,
                    'votesAgainst' => 0,
                    'votesAbstain' => 0,
                ]
                );

        // Create relation VotingRound -> Motion.
        $objectService->saveObject(
                'objectRelation',
                [
                    'from' => $votingRound['id'],
                    'to'   => $motionId,
                    'type' => 'VotingRound->Motion',
                ]
                );

        // Transition motion to 'voting' state via lifecycle guard.
        $this->motionService->transitionLifecycle($motionId, 'motion', 'voting', $actorId);

        $this->logger->info(
                'Voting round opened',
                [
                    'votingRoundId' => $votingRound['id'],
                    'motionId'      => $motionId,
                    'votingMethod'  => $votingMethod,
                ]
                );

        return $votingRound;
    }//end openVotingRound()

    /**
     * Cast a vote in an open voting round.
     *
     * Validates the vote value, checks the round is still open, handles
     * existing votes (overwrite), and enforces the one-proxy-per-round rule.
     *
     * @param string      $votingRoundId The ID of the voting round
     * @param string      $participantId The ID of the participant casting the vote
     * @param string      $value         The vote value: 'for', 'against', or 'abstain'
     * @param bool        $isProxy       Whether this is a proxy vote
     * @param string|null $delegatorId   The ID of the delegating participant (if proxy)
     *
     * @return array The created or updated Vote as an associative array
     *
     * @throws \InvalidArgumentException When the vote value is invalid
     * @throws \RuntimeException         When the voting round is closed or proxy rules violated
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy=false,
        ?string $delegatorId=null,
    ): array {
        // Validate vote value.
        $allowedValues = ['for', 'against', 'abstain'];
        if (in_array($value, $allowedValues, true) === false) {
            throw new \InvalidArgumentException(
                "Ongeldige stemwaarde: '{$value}'. Toegestaan: for, against, abstain"
            );
        }

        $objectService = $this->getObjectService();

        // Fetch VotingRound and check it is still open.
        $votingRound = $objectService->getObject('votingRound', $votingRoundId);
        if (($votingRound['closedAt'] ?? null) !== null) {
            throw new \RuntimeException('Stemronde is gesloten');
        }

        // Check for existing vote from this participant in this round.
        $existingVotes = $objectService->getObjects(
            'vote',
            [
                'votingRound' => $votingRoundId,
                'participant' => $participantId,
            ]
        );

        // If proxy: check no other proxy vote exists from the delegator in this round.
        if ($isProxy === true && $delegatorId !== null) {
            $existingProxyVotes = $objectService->getObjects(
                'vote',
                [
                    'votingRound' => $votingRoundId,
                    'delegator'   => $delegatorId,
                    'isProxy'     => true,
                ]
            );

            if (empty($existingProxyVotes) === false) {
                throw new \RuntimeException(
                    'Er is al een volmachtstem uitgebracht voor deze delegator in deze stemronde'
                );
            }
        }

        // Build vote data.
        $voteData = [
            'value'       => $value,
            'weight'      => 1,
            'isProxy'     => $isProxy,
            'castAt'      => (new \DateTimeImmutable())->format('c'),
            'votingRound' => $votingRoundId,
            'participant' => $participantId,
        ];

        if ($isProxy === true && $delegatorId !== null) {
            $voteData['delegator'] = $delegatorId;
        }

        // If existing vote, update it (overwrite).
        if (empty($existingVotes) === false) {
            $existingVote   = reset($existingVotes);
            $voteData['id'] = $existingVote['id'];
            $vote           = $objectService->saveObject('vote', $voteData);

            // Log only voteId + votingRoundId to avoid linking identity to ballot value.
            $this->logger->info(
                    'Vote updated',
                    [
                        'voteId'        => $vote['id'],
                        'votingRoundId' => $votingRoundId,
                    ]
                    );

            return $vote;
        }

        // Save new Vote.
        $vote = $objectService->saveObject('vote', $voteData);

        // Create relation Vote -> VotingRound.
        $objectService->saveObject(
                'objectRelation',
                [
                    'from' => $vote['id'],
                    'to'   => $votingRoundId,
                    'type' => 'Vote->VotingRound',
                ]
                );

        // Create Vote -> Participant relation only for non-secret ballots.
        if (($votingRound['isSecret'] ?? false) === false) {
            $objectService->saveObject(
                    'objectRelation',
                    [
                        'from' => $vote['id'],
                        'to'   => $participantId,
                        'type' => 'Vote->Participant',
                    ]
                    );
        }

        // If proxy, create relation Vote -> Participant (delegator) — skip for secret ballots.
        if ($isProxy === true && $delegatorId !== null && ($votingRound['isSecret'] ?? false) === false) {
            $objectService->saveObject(
                    'objectRelation',
                    [
                        'from' => $vote['id'],
                        'to'   => $delegatorId,
                        'type' => 'Vote->Participant',
                    ]
                    );
        }

        // Log only voteId + votingRoundId to avoid linking identity to ballot value.
        $this->logger->info(
                'Vote cast',
                [
                    'voteId'        => $vote['id'],
                    'votingRoundId' => $votingRoundId,
                    'isProxy'       => $isProxy,
                ]
                );

        return $vote;
    }//end castVote()

    /**
     * Close a voting round and determine the outcome.
     *
     * Tallies the results, updates the VotingRound with a close timestamp,
     * transitions the related motion lifecycle, and logs publication and
     * dossier folder creation if applicable.
     *
     * @param string $votingRoundId The ID of the voting round to close
     *
     * @return array The updated VotingRound as an associative array
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function closeVotingRound(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        // Fetch VotingRound.
        $votingRound = $objectService->getObject('votingRound', $votingRoundId);

        // Tally results (returns counts + result without saving).
        $tally = $this->tallyResults(votingRoundId: $votingRoundId);

        // Perform single authoritative save with tally + closedAt.
        $votingRound['closedAt']     = (new \DateTimeImmutable())->format('c');
        $votingRound['votesFor']     = $tally['votesFor'];
        $votingRound['votesAgainst'] = $tally['votesAgainst'];
        $votingRound['votesAbstain'] = $tally['votesAbstain'];
        $votingRound['result']       = $tally['result'];
        $votingRound = $objectService->saveObject('votingRound', $votingRound);

        // Determine motion lifecycle based on result.
        $result = $tally['result'];
        if ($result === 'adopted' || $result === 'rejected') {
            // Fetch the related motion.
            $relations = $objectService->getObjects(
                'objectRelation',
                [
                    'from' => $votingRoundId,
                    'type' => 'VotingRound->Motion',
                ]
            );

            if (empty($relations) === false) {
                $relation = reset($relations);
                $motion   = $objectService->getObject('motion', $relation['to']);
                $motion['lifecycle'] = $result;
                $objectService->saveObject('motion', $motion);

                $this->logger->info(
                        'Motion lifecycle updated',
                        [
                            'motionId'  => $motion['id'],
                            'lifecycle' => $result,
                        ]
                        );
            }
        }//end if

        // Check if ORI publication is configured.
        $oriEnabled = $this->appConfig->getValueString('decidesk', 'ori_publication', '');
        if ($oriEnabled !== '') {
            $this->logger->info(
                    'ORI publication configured; voting round results eligible for publication',
                    [
                        'votingRoundId' => $votingRoundId,
                        'result'        => $result,
                    ]
                    );
        }

        // If result is adopted, log dossier folder creation.
        if ($result === 'adopted') {
            $this->logger->info(
                    'Motion adopted; dossier folder creation should be triggered',
                    [
                        'votingRoundId' => $votingRoundId,
                    ]
                    );
        }

        $this->logger->info(
                'Voting round closed',
                [
                    'votingRoundId' => $votingRoundId,
                    'result'        => $result,
                ]
                );

        return $votingRound;
    }//end closeVotingRound()

    /**
     * Close a voting round with manually entered show-of-hands counts.
     *
     * Used for show-of-hands voting where individual Vote objects are not cast.
     * Persists the supplied counts, determines the result, and closes the round.
     *
     * @param string $votingRoundId The ID of the voting round to close
     * @param int    $votesFor      Manually counted votes in favour
     * @param int    $votesAgainst  Manually counted votes against
     * @param int    $votesAbstain  Manually counted abstentions
     *
     * @return array The updated VotingRound as an associative array
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function closeVotingRoundWithHandsCount(
        string $votingRoundId,
        int $votesFor,
        int $votesAgainst,
        int $votesAbstain,
    ): array {
        $objectService = $this->getObjectService();

        $votingRound = $objectService->getObject('votingRound', $votingRoundId);

        if ($votesFor > $votesAgainst) {
            $result = 'adopted';
        } else if ($votesAgainst > $votesFor) {
            $result = 'rejected';
        } else if (($votesFor + $votesAgainst + $votesAbstain) === 0) {
            $result = 'invalid';
        } else {
            $result = 'tied';
        }

        $votingRound['closedAt']     = (new \DateTimeImmutable())->format('c');
        $votingRound['votesFor']     = $votesFor;
        $votingRound['votesAgainst'] = $votesAgainst;
        $votingRound['votesAbstain'] = $votesAbstain;
        $votingRound['result']       = $result;
        $votingRound = $objectService->saveObject('votingRound', $votingRound);

        $this->logger->info(
                'Voting round closed with hands count',
                [
                    'votingRoundId' => $votingRoundId,
                    'result'        => $result,
                ]
                );

        return $votingRound;
    }//end closeVotingRoundWithHandsCount()

    /**
     * Tally the results of a voting round.
     *
     * Counts all votes cast in the round and determines the outcome based
     * on simple majority: adopted if for > against, rejected if against > for,
     * tied if equal, or invalid if no votes were cast.
     *
     * Returns tally data only — the caller is responsible for persisting
     * the counts to the VotingRound object.
     *
     * @param string $votingRoundId The ID of the voting round to tally
     *
     * @return array{votesFor: int, votesAgainst: int, votesAbstain: int, result: string}
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function tallyResults(string $votingRoundId): array
    {
        $objectService = $this->getObjectService();

        // Fetch all votes for this round.
        $votes = $objectService->getObjects(
            'vote',
            [
                'votingRound' => $votingRoundId,
            ]
        );

        $votesFor     = 0;
        $votesAgainst = 0;
        $votesAbstain = 0;

        foreach ($votes as $vote) {
            switch ($vote['value'] ?? null) {
                case 'for':
                    $votesFor++;
                    break;
                case 'against':
                    $votesAgainst++;
                    break;
                case 'abstain':
                    $votesAbstain++;
                    break;
            }
        }

        // Determine result.
        if (($votesFor + $votesAgainst + $votesAbstain) === 0) {
            $result = 'invalid';
        } else if ($votesFor > $votesAgainst) {
            $result = 'adopted';
        } else if ($votesAgainst > $votesFor) {
            $result = 'rejected';
        } else {
            $result = 'tied';
        }

        return [
            'votesFor'     => $votesFor,
            'votesAgainst' => $votesAgainst,
            'votesAbstain' => $votesAbstain,
            'result'       => $result,
        ];
    }//end tallyResults()

    /**
     * Grant a proxy vote delegation from one participant to another.
     *
     * Verifies the receiving participant is eligible (not an observer or guest),
     * then records the proxy as a note on the VotingRound.
     *
     * @param string $votingRoundId     The ID of the voting round
     * @param string $fromParticipantId The ID of the delegating participant
     * @param string $toParticipantId   The ID of the receiving participant
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the receiving participant is an observer or guest
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function grantProxy(
        string $votingRoundId,
        string $fromParticipantId,
        string $toParticipantId,
    ): void {
        $objectService = $this->getObjectService();

        // Fetch toParticipant and verify role.
        $toParticipant = $objectService->getObject('participant', $toParticipantId);
        $role          = $toParticipant['role'] ?? '';

        if ($role === 'observer' || $role === 'guest') {
            throw new \InvalidArgumentException(
                "Deelnemer met rol '{$role}' kan geen volmacht ontvangen"
            );
        }

        // Store proxy as a note on VotingRound.
        $votingRound = $objectService->getObject('votingRound', $votingRoundId);
        $notes       = $votingRound['notes'] ?? [];
        $notes[]     = [
            'title'   => 'Proxy granted',
            'type'    => 'proxy',
            'from'    => $fromParticipantId,
            'to'      => $toParticipantId,
            'addedAt' => (new \DateTimeImmutable())->format('c'),
        ];
        $votingRound['notes'] = $notes;
        $objectService->saveObject('votingRound', $votingRound);

        $this->logger->info(
                'Proxy granted',
                ['votingRoundId' => $votingRoundId]
                );
    }//end grantProxy()

    /**
     * Revoke a previously granted proxy delegation.
     *
     * Can only revoke a proxy if the voting round has not yet been opened
     * for casting (isOpen === false).
     *
     * @param string $votingRoundId     The ID of the voting round
     * @param string $fromParticipantId The ID of the delegating participant
     *
     * @return void
     *
     * @throws \RuntimeException When the voting round is already open for casting
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function revokeProxy(
        string $votingRoundId,
        string $fromParticipantId,
    ): void {
        $objectService = $this->getObjectService();

        // Fetch VotingRound and check if round is already open for casting.
        $votingRound = $objectService->getObject('votingRound', $votingRoundId);
        $isOpen      = $votingRound['isOpen'] ?? false;

        if ($isOpen === true) {
            throw new \RuntimeException('Kan volmacht niet intrekken: stemronde is al geopend');
        }

        // Remove proxy note.
        $notes         = $votingRound['notes'] ?? [];
        $filteredNotes = [];
        foreach ($notes as $note) {
            if (($note['type'] ?? '') === 'proxy' && ($note['from'] ?? '') === $fromParticipantId) {
                continue;
            }

            $filteredNotes[] = $note;
        }

        $votingRound['notes'] = $filteredNotes;
        $objectService->saveObject('votingRound', $votingRound);

        $this->logger->info(
                'Proxy revoked',
                ['votingRoundId' => $votingRoundId]
                );
    }//end revokeProxy()
}//end class
