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
        $objectService = $this->objectService();

        // Step 1: Fetch all motions for this governance body.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $motionEntities = $objectService->findAll(
            ['filters' => ['relations.governance-body' => $governanceBodyId]]
        );

        // Step 2: For each motion, collect all closed voting-rounds.
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
                ['filters' => ['relations.motion' => $motionId]]
            );

            foreach ($roundEntities as $roundEntity) {
                $round = $roundEntity->jsonSerialize();
                // Only include closed rounds (closedAt is not null).
                if (isset($round['closedAt']) === true && $round['closedAt'] !== null) {
                    $closedRounds[] = $round;
                }
            }
        }//end foreach

        $totalRounds     = count($closedRounds);
        $participated    = 0;
        $votesFor        = 0;
        $votesAgainst    = 0;
        $votesAbstain    = 0;
        $proxiesGiven    = 0;
        $proxiesReceived = 0;

        // Step 3: For each closed round, fetch votes for this participant.
        foreach ($closedRounds as $round) {
            $roundId = ($round['id'] ?? ($round['uuid'] ?? null));
            if ($roundId === null) {
                continue;
            }

            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            $voteEntities = $objectService->findAll(
                    [
                        'filters' => [
                            'relations.voting-round' => $roundId,
                            'relations.participant'  => $participantId,
                        ],
                    ]
                    );

            $votes = array_map(fn($e) => $e->jsonSerialize(), $voteEntities);
            if (count($votes) > 0) {
                $participated++;

                // Count vote values.
                foreach ($votes as $vote) {
                    $value = ($vote['value'] ?? null);
                    if ($value === 'for') {
                        $votesFor++;
                    } else if ($value === 'against') {
                        $votesAgainst++;
                    } else if ($value === 'abstain') {
                        $votesAbstain++;
                    }

                    // Count proxy status: isProxy=true means this participant voted as proxy
                    // on behalf of someone else (they cast the proxy, i.e., proxiesGiven).
                    if ($vote['isProxy'] ?? false) {
                        $proxiesGiven++;
                    }
                }
            }//end if

            // Count proxies received: find proxy votes in this round where this
            // participant was the delegator (a relation entry with type='delegator').
            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            $proxyVoteEntities = $objectService->findAll(
                [
                    'filters' => [
                        'relations.voting-round' => $roundId,
                        'isProxy'                => true,
                    ],
                ]
            );
            foreach ($proxyVoteEntities as $proxyVoteEntity) {
                $proxyVote = $proxyVoteEntity->jsonSerialize();
                foreach (($proxyVote['relations'] ?? []) as $rel) {
                    if (is_array($rel) === true
                        && ($rel['schema'] ?? '') === 'participant'
                        && ($rel['id'] ?? '') === $participantId
                        && ($rel['type'] ?? '') === 'delegator'
                    ) {
                        $proxiesReceived++;
                        break;
                    }
                }
            }
        }//end foreach

        // Participation rate as percentage.
        $participationRate = 0.0;
        if ($totalRounds > 0) {
            $participationRate = round(($participated / $totalRounds) * 100, 1);
        }

        return [
            'participantId'     => $participantId,
            'governanceBodyId'  => $governanceBodyId,
            'totalRounds'       => $totalRounds,
            'participated'      => $participated,
            'participationRate' => $participationRate,
            'votesFor'          => $votesFor,
            'votesAgainst'      => $votesAgainst,
            'votesAbstain'      => $votesAbstain,
            'proxiesGiven'      => $proxiesGiven,
            'proxiesReceived'   => $proxiesReceived,
        ];

    }//end getStats()
}//end class
