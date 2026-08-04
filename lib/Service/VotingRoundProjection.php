<?php
/**
 * Decidesk Voting Round Projection
 *
 * The unauthenticated public-state view of a voting round used by projection
 * displays: aggregate counts only, never individual ballot values, and only for
 * rounds the chair has explicitly published.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Public projection state for a voting round.
 *
 * #303: a round is treated as not-found (null) when it is a secret ballot — a
 * secret round must not leak even aggregate counts — or when its lifecycle is
 * anything other than 'published'.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundProjection
{
    /**
     * Constructor for VotingRoundProjection.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService resolution)
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Get public-state for a VotingRound for projection display.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string, mixed>|null The public-state array, or null if not found / not accessible
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function publicState(string $votingRoundId): ?array
    {
        $round = $this->loadPublishedRound(votingRoundId: $votingRoundId);
        if ($round === null) {
            return null;
        }

        $votesFor     = (int) ($round['votesFor'] ?? 0);
        $votesAgainst = (int) ($round['votesAgainst'] ?? 0);
        $votesAbstain = (int) ($round['votesAbstain'] ?? 0);

        return [
            'motionTitle'       => $this->resolveMotionTitle(round: $round),
            'votingMethod'      => ($round['votingMethod'] ?? ''),
            'isOpen'            => ($round['closedAt'] ?? null) === null,
            'votesFor'          => $votesFor,
            'votesAgainst'      => $votesAgainst,
            'votesAbstain'      => $votesAbstain,
            'preselectedOption' => $this->preselectedOption(for: $votesFor, against: $votesAgainst, abstain: $votesAbstain),
            'openedAt'          => ($round['openedAt'] ?? null),
        ];

    }//end publicState()

    /**
     * Load the round only when it may be shown to an anonymous caller (#303).
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string, mixed>|null The serialised round, or null when not publicly visible
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function loadPublishedRound(string $votingRoundId): ?array
    {
        $entity = $this->objectService()->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        $round  = null;
        if ($entity !== null) {
            $round = $entity->jsonSerialize();
        }

        if ($round === null) {
            return null;
        }

        // Secret voting rounds must never be surfaced to anonymous projection callers.
        if ((bool) ($round['isSecret'] ?? false) === true) {
            return null;
        }

        // Only rounds that have been explicitly published are visible to
        // unauthenticated callers. Draft, open, and closed-but-unpublished rounds
        // must not leak to the public projection endpoint.
        $lifecycle = $round['lifecycle'] ?? $round['status'] ?? '';
        if ($lifecycle !== 'published') {
            return null;
        }

        return $round;

    }//end loadPublishedRound()

    /**
     * Resolve the title of the motion the round decides.
     *
     * @param array<string, mixed> $round The serialised voting round
     *
     * @return string The motion title, or an empty string when unresolvable
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function resolveMotionTitle(array $round): string
    {
        foreach (($round['relations'] ?? []) as $rel) {
            if (($rel['schema'] ?? '') !== 'motion') {
                continue;
            }

            $motionId = ($rel['id'] ?? null);
            if ($motionId === null) {
                return '';
            }

            $entity = $this->objectService()->find(id: $motionId, register: 'decidesk', schema: 'motion');
            $motion = null;
            if ($entity !== null) {
                $motion = $entity->jsonSerialize();
            }

            return (string) ($motion['title'] ?? '');
        }

        return '';

    }//end resolveMotionTitle()

    /**
     * Determine the option a projection display should preselect.
     *
     * @param int $for     The aggregate for-count
     * @param int $against The aggregate against-count
     * @param int $abstain The aggregate abstain-count
     *
     * @return string|null 'for' | 'against' | 'abstain', or null when there is no strict leader
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function preselectedOption(int $for, int $against, int $abstain): ?string
    {
        if ($for > $against && $for > $abstain) {
            return 'for';
        }

        if ($against > $for && $against > $abstain) {
            return 'against';
        }

        if ($abstain > $for && $abstain > $against) {
            return 'abstain';
        }

        return null;

    }//end preselectedOption()

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
