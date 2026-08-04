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
 * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
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
 * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
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
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
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
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    public function getStats(string $participantId, string $governanceBodyId): array
    {
        $closedRounds = $this->closedRoundsForBody(governanceBodyId: $governanceBodyId);
        $totalRounds  = count($closedRounds);
        $behaviour    = $this->tallyBehaviour(participantId: $participantId, closedRounds: $closedRounds);

        return array_merge(
            [
                'participantId'     => $participantId,
                'governanceBodyId'  => $governanceBodyId,
                'totalRounds'       => $totalRounds,
                'participated'      => $behaviour['participated'],
                'participationRate' => $this->participationRate(
                    participated: $behaviour['participated'],
                    totalRounds: $totalRounds
                ),
            ],
            $behaviour['counts']
        );

    }//end getStats()

    /**
     * Collect every closed voting round of a governance body.
     *
     * Traversal: governanceBodyId -> motions (relations.governance-body = $bodyId)
     * -> voting-rounds (relations.motion = $motionId), keeping only rounds whose
     * closedAt is set.
     *
     * @param string $governanceBodyId The governance body UUID
     *
     * @return array<int, array<string, mixed>> The serialised closed voting rounds
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function closedRoundsForBody(string $governanceBodyId): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $motionEntities = $objectService->findAll(
            ['filters' => ['_relations.governance-body' => $governanceBodyId]]
        );

        $closedRounds = [];
        foreach ($motionEntities as $motionEntity) {
            $motion   = $motionEntity->jsonSerialize();
            $motionId = ($motion['id'] ?? ($motion['uuid'] ?? null));
            if ($motionId === null) {
                continue;
            }

            $objectService->setRegister('decidesk');
            $objectService->setSchema('voting-round');
            $roundEntities = $objectService->findAll(
                ['filters' => ['_relations.motion' => $motionId]]
            );

            foreach ($roundEntities as $roundEntity) {
                $round = $roundEntity->jsonSerialize();
                // Only include closed rounds (closedAt is not null).
                if (isset($round['closedAt']) === true && $round['closedAt'] !== null) {
                    $closedRounds[] = $round;
                }
            }
        }//end foreach

        return $closedRounds;

    }//end closedRoundsForBody()

    /**
     * Aggregate the participant's behaviour across a set of closed rounds.
     *
     * @param string                           $participantId The participant UUID
     * @param array<int, array<string, mixed>> $closedRounds  The closed voting rounds
     *
     * @return array{participated: int, counts: array<string, int>} The participation count and the vote/proxy counters
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function tallyBehaviour(string $participantId, array $closedRounds): array
    {
        $participated = 0;
        $counts       = [
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

            $votes = $this->participantVotes(participantId: $participantId, roundId: $roundId);
            if (count($votes) > 0) {
                $participated++;
                $counts = $this->addVoteCounts(counts: $counts, votes: $votes);
            }

            $counts['proxiesReceived'] += $this->countProxiesReceived(
                participantId: $participantId,
                roundId: $roundId
            );
        }

        return ['participated' => $participated, 'counts' => $counts];

    }//end tallyBehaviour()

    /**
     * Fetch the participant's ballots in a single round.
     *
     * @param string $participantId The participant UUID
     * @param string $roundId       The voting round UUID
     *
     * @return array<int, array<string, mixed>> The serialised Vote objects
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function participantVotes(string $participantId, string $roundId): array
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

        return array_map(fn($e) => $e->jsonSerialize(), $voteEntities);

    }//end participantVotes()

    /**
     * Add a round's ballots to the running vote and proxy-given counters.
     *
     * A ballot with isProxy=true means this participant voted as proxy on behalf
     * of someone else (they cast the proxy, i.e. proxiesGiven).
     *
     * @param array<string, int>               $counts The running counters
     * @param array<int, array<string, mixed>> $votes  The participant's ballots in this round
     *
     * @return array<string, int> The updated counters
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function addVoteCounts(array $counts, array $votes): array
    {
        foreach ($votes as $vote) {
            $value = ($vote['value'] ?? null);
            if ($value === 'for') {
                $counts['votesFor']++;
            } else if ($value === 'against') {
                $counts['votesAgainst']++;
            } else if ($value === 'abstain') {
                $counts['votesAbstain']++;
            }

            if ($vote['isProxy'] ?? false) {
                $counts['proxiesGiven']++;
            }
        }

        return $counts;

    }//end addVoteCounts()

    /**
     * Count the proxy ballots in a round that were cast on this participant's behalf.
     *
     * Finds proxy votes in the round where this participant was the delegator (a
     * relation entry with type='delegator').
     *
     * @param string $participantId The participant UUID
     * @param string $roundId       The voting round UUID
     *
     * @return int The number of proxies received in this round
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function countProxiesReceived(string $participantId, string $roundId): int
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
            if ($this->isDelegatedBy(vote: $proxyVote, participantId: $participantId) === true) {
                $received++;
            }
        }

        return $received;

    }//end countProxiesReceived()

    /**
     * Whether a serialised proxy ballot names this participant as the delegator.
     *
     * @param array<string, mixed> $vote          The serialised Vote object
     * @param string               $participantId The participant UUID
     *
     * @return bool True when the ballot carries a 'delegator' relation to the participant
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function isDelegatedBy(array $vote, string $participantId): bool
    {
        foreach (($vote['relations'] ?? []) as $rel) {
            if (is_array($rel) === true
                && ($rel['schema'] ?? '') === 'participant'
                && ($rel['id'] ?? '') === $participantId
                && ($rel['type'] ?? '') === 'delegator'
            ) {
                return true;
            }
        }

        return false;

    }//end isDelegatedBy()

    /**
     * Express participation as a percentage of the closed rounds.
     *
     * @param int $participated The number of rounds the participant voted in
     * @param int $totalRounds  The number of closed rounds
     *
     * @return float The participation rate, rounded to one decimal
     *
     * @spec openspec/specs/member-voting-behaviour-tracking/spec.md
     */
    private function participationRate(int $participated, int $totalRounds): float
    {
        if ($totalRounds === 0) {
            return 0.0;
        }

        return round((($participated / $totalRounds) * 100), 1);

    }//end participationRate()
}//end class
