<?php
/**
 * Decidesk Vote Casting Service
 *
 * Orchestrates a single ballot: load the round, run every eligibility gate in
 * order, then hand the ballot to the recorder. The gates and the persistence
 * live in their own collaborators so this sequence stays readable.
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
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/user-settings/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The vote-casting sequence.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteCastingService
{

    /**
     * Fail-closed eligibility rules for the ballot.
     *
     * @var VoteEligibilityGuard
     */
    private readonly VoteEligibilityGuard $guard;

    /**
     * Ballot construction and persistence.
     *
     * @var VoteRecorder
     */
    private readonly VoteRecorder $recorder;

    /**
     * Constructor for VoteCastingService.
     *
     * @param ContainerInterface  $container           The DI container
     * @param LoggerInterface     $logger              The logger
     * @param MotionService       $motionService       The motion service (subject chain resolution)
     * @param ParticipantResolver $participantResolver Meeting-membership resolver
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        LoggerInterface $logger,
        MotionService $motionService,
        ParticipantResolver $participantResolver,
    ) {
        $this->guard = new VoteEligibilityGuard(
            container: $container,
            logger: $logger,
            motionService: $motionService,
            participantResolver: $participantResolver
        );

        $this->recorder = new VoteRecorder(container: $container, logger: $logger);

    }//end __construct()

    /**
     * Cast a vote in a VotingRound.
     *
     * Checks the round is open, verifies the participant is a member of the
     * meeting that owns the round (#300), prevents duplicates (overwrites an
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
     * @return array<string, mixed> The created/updated Vote object
     *
     * @throws RuntimeException When the round is not open, the caller is not a meeting member,
     *                           or proxy rules are violated
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/user-settings/spec.md
     */
    public function castVote(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
        ?string $callerUid=null,
    ): array {
        $round = $this->loadRound(votingRoundId: $votingRoundId);

        $this->guard->assertRoundVotable(round: $round);
        $this->guard->assertMeetingMembership(round: $round, participantId: $participantId);

        $isSecret = (bool) ($round['isSecret'] ?? false);

        if ($isProxy === true && $delegatorId !== null) {
            $this->guard->assertProxyPermitted(
                round: $round,
                votingRoundId: $votingRoundId,
                participantId: $participantId,
                delegatorId: $delegatorId,
                callerUid: $callerUid,
                isSecret: $isSecret
            );
        }

        return $this->recorder->record(
            votingRoundId: $votingRoundId,
            participantId: $participantId,
            value: $value,
            isProxy: $isProxy,
            delegatorId: $delegatorId,
            isSecret: $isSecret
        );

    }//end castVote()

    /**
     * Load the voting round, refusing an unknown id.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string, mixed> The serialised voting round
     *
     * @throws RuntimeException When the round does not exist
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function loadRound(string $votingRoundId): array
    {
        $entity = $this->objectService()->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round  = null;
        if ($entity !== null) {
            $round = $entity->jsonSerialize();
        }

        if ($round === null) {
            throw new RuntimeException("VotingRound {$votingRoundId} not found");
        }

        return $round;

    }//end loadRound()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object The OpenRegister ObjectService
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()
}//end class
