<?php

/**
 * Decidesk Voting Behaviour Service
 *
 * Service for computing voting behaviour statistics aggregated from Vote objects.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Stateless service computing voting behaviour statistics on-demand from Vote objects.
 *
 * Traversal path for closed voting rounds:
 *   governanceBodyId → motions (relations.governance-body = $bodyId)
 *   → voting-rounds (relations.motion = $motionId)
 *   → votes (relations.voting-round = $roundId, relations.participant = $participantId)
 *
 * This mirrors the schema design: voting-rounds are linked to a motion (not directly
 * to a governance-body), and votes are linked to a round and a participant via
 * their relations array — there are no scalar foreign-key fields.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourService
{
    /**
     * Constructor for VotingBehaviourService.
     *
     * @param ContainerInterface $container The DI container
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
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
     * Get voting behaviour statistics for a participant across all closed VotingRounds.
     *
     * Aggregates vote counts, participation rate, and proxy behaviour from all Vote
     * objects linked to the participant in closed voting rounds of the given governance body.
     *
     * Traversal:
     *   1. Find all motions with relations.governance-body = $governanceBodyId
     *   2. For each motion, find all closed voting-rounds with relations.motion = $motionId
     *   3. For each closed round, find votes with
     *      relations.voting-round = $roundId AND relations.participant = $participantId
     *
     * @param string $participantId    The participant UUID
     * @param string $governanceBodyId The governance body UUID
     *
     * @return array<string,mixed> Statistics array with totalRounds, participated, participationRate,
     *                             votesFor, votesAgainst, votesAbstain, proxiesGiven, proxiesReceived
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    public function getStats(string $participantId, string $governanceBodyId): array
    {
        $closedRounds = $this->closedRoundsForBody(governanceBodyId: $governanceBodyId);
        $totalRounds  = count($closedRounds);

        $tally = [
            'participated'    => 0,
            'votesFor'        => 0,
            'votesAgainst'    => 0,
            'votesAbstain'    => 0,
            'proxiesGiven'    => 0,
            'proxiesReceived' => 0,
        ];

        foreach ($closedRounds as $round) {
            $roundId = ($round['id'] ?? ($round['uuid'] ?? null));
            if ($roundId === null) {
                continue;
            }

            $this->accumulateRound(
                tally: $tally,
                roundId: (string) $roundId,
                participantId: $participantId
            );
        }

        // Participation rate as percentage.
        $participationRate = 0.0;
        if ($totalRounds > 0) {
            $participationRate = round((($tally['participated'] / $totalRounds) * 100), 1);
        }

        return array_merge(
            [
                'participantId'     => $participantId,
                'governanceBodyId'  => $governanceBodyId,
                'totalRounds'       => $totalRounds,
                'participated'      => $tally['participated'],
                'participationRate' => $participationRate,
            ],
            [
                'votesFor'        => $tally['votesFor'],
                'votesAgainst'    => $tally['votesAgainst'],
                'votesAbstain'    => $tally['votesAbstain'],
                'proxiesGiven'    => $tally['proxiesGiven'],
                'proxiesReceived' => $tally['proxiesReceived'],
            ]
        );

    }//end getStats()

    /**
     * Collect every closed voting round of a governance body.
     *
     * Walks governance-body -> motion -> voting-round, keeping only rounds that
     * carry a closedAt.
     *
     * @param string $governanceBodyId The governance body UUID
     *
     * @return array<int, array<string,mixed>> The closed rounds.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function closedRoundsForBody(string $governanceBodyId): array
    {
        $objectService = $this->objectService();

        // Step 1: Fetch all motions for this governance body. ADR-005: motions
        // are `decision` objects selected by the decisionType discriminator.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $motionEntities = $objectService->findAll(
            [
                'filters' => DecisionSchema::filters(
                    decisionType: DecisionSchema::MOTION,
                    extra: ['_relations.governance-body' => $governanceBodyId]
                ),
            ]
        );

        // Step 2: For each motion, collect all closed voting-rounds.
        $closedRounds = [];
        foreach ($motionEntities as $motionEntity) {
            $motion   = $motionEntity->jsonSerialize();
            $motionId = ($motion['id'] ?? ($motion['uuid'] ?? null));
            if ($motionId === null) {
                continue;
            }

            $closedRounds = array_merge(
                $closedRounds,
                $this->closedRoundsForMotion(motionId: (string) $motionId)
            );
        }

        return $closedRounds;

    }//end closedRoundsForBody()

    /**
     * The closed voting rounds of one motion.
     *
     * @param string $motionId The motion UUID
     *
     * @return array<int, array<string,mixed>> The closed rounds.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function closedRoundsForMotion(string $motionId): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('voting-round');
        $roundEntities = $objectService->findAll(
            ['filters' => ['_relations.motion' => $motionId]]
        );

        $closed = [];
        foreach ($roundEntities as $roundEntity) {
            $round = $roundEntity->jsonSerialize();
            // Only include closed rounds (closedAt is not null).
            if (isset($round['closedAt']) === true && $round['closedAt'] !== null) {
                $closed[] = $round;
            }
        }

        return $closed;

    }//end closedRoundsForMotion()

    /**
     * Fold one closed round's ballots into the running tally.
     *
     * @param array<string,int> $tally         The running tally, updated in place
     * @param string            $roundId       The voting round UUID
     * @param string            $participantId The participant UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function accumulateRound(array &$tally, string $roundId, string $participantId): void
    {
        $votes = $this->participantVotes(roundId: $roundId, participantId: $participantId);
        if (count($votes) > 0) {
            $tally['participated']++;
            foreach ($votes as $vote) {
                $this->accumulateVote(tally: $tally, vote: $vote);
            }
        }

        // Count proxies received: find proxy votes in this round where this
        // participant was the delegator (a relation entry with type='delegator').
        $tally['proxiesReceived'] += $this->proxiesReceived(
            roundId: $roundId,
            participantId: $participantId
        );

    }//end accumulateRound()

    /**
     * Fold one ballot's value and proxy status into the running tally.
     *
     * @param array<string,int>   $tally The running tally, updated in place
     * @param array<string,mixed> $vote  The ballot
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function accumulateVote(array &$tally, array $vote): void
    {
        $countKey = match (($vote['value'] ?? null)) {
            'for'     => 'votesFor',
            'against' => 'votesAgainst',
            'abstain' => 'votesAbstain',
            default   => null,
        };

        if ($countKey !== null) {
            $tally[$countKey]++;
        }

        // An isProxy=true ballot means this participant voted as proxy on
        // behalf of someone else (they cast the proxy, i.e. proxiesGiven).
        if ((bool) ($vote['isProxy'] ?? false) === true) {
            $tally['proxiesGiven']++;
        }

    }//end accumulateVote()

    /**
     * The participant's own ballots in one round.
     *
     * @param string $roundId       The voting round UUID
     * @param string $participantId The participant UUID
     *
     * @return array<int, array<string,mixed>> The ballots.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function participantVotes(string $roundId, string $participantId): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');
        $voteEntities = $objectService->findAll(
            [
                'filters' => [
                    '_relations.voting-round' => $roundId,
                    '_relations.participant'  => $participantId,
                ],
            ]
        );

        return array_map(static fn($e) => $e->jsonSerialize(), $voteEntities);

    }//end participantVotes()

    /**
     * How many proxy votes in one round name this participant as delegator.
     *
     * @param string $roundId       The voting round UUID
     * @param string $participantId The participant UUID
     *
     * @return int The number of proxies received.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function proxiesReceived(string $roundId, string $participantId): int
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');
        $proxyVoteEntities = $objectService->findAll(
            [
                'filters' => [
                    '_relations.voting-round' => $roundId,
                    'isProxy'                 => true,
                ],
            ]
        );

        $received = 0;
        foreach ($proxyVoteEntities as $proxyVoteEntity) {
            $proxyVote = $proxyVoteEntity->jsonSerialize();
            if ($this->namesDelegator(relations: ($proxyVote['relations'] ?? []), participantId: $participantId) === true) {
                $received++;
            }
        }

        return $received;

    }//end proxiesReceived()

    /**
     * Whether a vote's relations name the participant as delegator.
     *
     * @param mixed  $relations     The vote's relations structure
     * @param string $participantId The participant UUID
     *
     * @return bool True when the participant is the delegator.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    private function namesDelegator(mixed $relations, string $participantId): bool
    {
        foreach (($relations ?? []) as $rel) {
            if (is_array($rel) === true
                && ($rel['schema'] ?? '') === 'participant'
                && ($rel['id'] ?? '') === $participantId
                && ($rel['type'] ?? '') === 'delegator'
            ) {
                return true;
            }
        }

        return false;

    }//end namesDelegator()
}//end class
