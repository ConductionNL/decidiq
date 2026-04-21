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

        // Fetch all closed VotingRounds for this governance body.
        $roundsResult = $objectService->findObjects(
            register: 'decidesk',
            schema: 'voting-round',
            params: ['governanceBodyId' => $governanceBodyId]
        );

        $rounds = ($roundsResult['results'] ?? []);

        // Filter to closed rounds only (closedAt is not null).
        $closedRounds = array_filter(
                $rounds,
                static function (array $round) {
                    return isset($round['closedAt']) && $round['closedAt'] !== null;
                }
                );

        $totalRounds     = count($closedRounds);
        $participated    = 0;
        $votesFor        = 0;
        $votesAgainst    = 0;
        $votesAbstain    = 0;
        $proxiesGiven    = 0;
        $proxiesReceived = 0;

        // For each closed round, fetch votes for this participant.
        foreach ($closedRounds as $round) {
            $roundId = ($round['id'] ?? $round['uuid'] ?? null);
            if ($roundId === null) {
                continue;
            }

            $votesResult = $objectService->findObjects(
                register: 'decidesk',
                schema: 'vote',
                params: [
                    'participantId' => $participantId,
                    'votingRoundId' => $roundId,
                ]
            );

            $votes = ($votesResult['results'] ?? []);
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

                    // Count proxy status.
                    if ($vote['isProxy'] ?? false) {
                        $proxiesGiven++;
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
