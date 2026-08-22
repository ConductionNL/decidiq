<?php

/**
 * Decidiq Voting Round Projection
 *
 * Builds the unauthenticated public-state view of a voting round for the
 * projection display: aggregate counts only, never individual vote values.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The public projection view of a voting round, extracted from VotingService.
 *
 * The visibility rules (#303) are the whole point of this class: a secret
 * ballot and an unpublished round both look like "not found" to an anonymous
 * caller, so neither leaks even aggregate counts.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundProjection {
	/**
	 * Constructor for the VotingRoundProjection.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get public-state for a VotingRound for projection display.
	 *
	 * Returns aggregate vote counts, preselected option, and no individual vote
	 * values. Accessible without authentication.
	 *
	 * #303: Returns null (treating as not-found) when:
	 * - The round has isSecret==true (secret ballots must not leak even aggregate
	 *   counts to unauthenticated projection displays until the chair explicitly
	 *   publishes results).
	 * - The round's lifecycle is not 'published' (draft/closed rounds are not
	 *   visible to anonymous callers).
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed>|null The public-state array, or null if not found / not accessible
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function publicState(string $votingRoundId): ?array {
		$round = $this->findObject(objectId: $votingRoundId, schema: 'voting-round');
		if ($round === null) {
			return null;
		}

		if ($this->isPubliclyVisible(round: $round) === false) {
			return null;
		}

		$votesFor = (int)($round['votesFor'] ?? 0);
		$votesAgainst = (int)($round['votesAgainst'] ?? 0);
		$votesAbstain = (int)($round['votesAbstain'] ?? 0);

		return [
			'motionTitle' => $this->motionTitle(round: $round),
			'votingMethod' => ($round['votingMethod'] ?? ''),
			'isOpen' => ($round['closedAt'] ?? null) === null,
			'votesFor' => $votesFor,
			'votesAgainst' => $votesAgainst,
			'votesAbstain' => $votesAbstain,
			'preselectedOption' => $this->preselectedOption(
				votesFor: $votesFor,
				votesAgainst: $votesAgainst,
				votesAbstain: $votesAbstain
			),
			'openedAt' => ($round['openedAt'] ?? null),
		];

	}//end publicState()

	/**
	 * Whether an anonymous caller may see this round at all (#303).
	 *
	 * @param array<string,mixed> $round The voting round
	 *
	 * @return bool True when the round is publicly visible.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function isPubliclyVisible(array $round): bool {
		// Secret voting rounds must never be surfaced to anonymous projection callers.
		if ((bool)($round['isSecret'] ?? false) === true) {
			return false;
		}

		// Only rounds that have been explicitly published are visible to
		// unauthenticated callers. Draft, open, and closed-but-unpublished
		// rounds must not leak to the public projection endpoint.
		$lifecycle = $round['lifecycle'] ?? $round['status'] ?? '';

		return $lifecycle === 'published';
	}//end isPubliclyVisible()

	/**
	 * Resolve the title of the motion the round decides on.
	 *
	 * @param array<string,mixed> $round The voting round
	 *
	 * @return string The motion title, or an empty string.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function motionTitle(array $round): string {
		foreach (($round['relations'] ?? []) as $rel) {
			if (($rel['schema'] ?? '') !== 'motion') {
				continue;
			}

			$motionId = ($rel['id'] ?? null);
			if ($motionId === null) {
				return '';
			}

			// ADR-005: the motion is a `decision` discriminated by decisionType;
			// the relation token above stays the subjectType VotingRoundPreflight
			// writes.
			$motion = $this->findObject(objectId: (string)$motionId, schema: 'decision');

			return (string)($motion['title'] ?? '');
		}

		return '';
	}//end motionTitle()

	/**
	 * The option leading on aggregate counts, or null when there is no clear lead.
	 *
	 * @param int $votesFor Weighted for-votes
	 * @param int $votesAgainst Weighted against-votes
	 * @param int $votesAbstain Weighted abstentions
	 *
	 * @return string|null 'for' | 'against' | 'abstain', or null when tied.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function preselectedOption(int $votesFor, int $votesAgainst, int $votesAbstain): ?string {
		if ($votesFor > $votesAgainst && $votesFor > $votesAbstain) {
			return 'for';
		}

		if ($votesAgainst > $votesFor && $votesAgainst > $votesAbstain) {
			return 'against';
		}

		if ($votesAbstain > $votesFor && $votesAbstain > $votesAgainst) {
			return 'abstain';
		}

		return null;
	}//end preselectedOption()

	/**
	 * Load any decidesk object as an array.
	 *
	 * @param string $objectId The object UUID
	 * @param string $schema The schema slug
	 *
	 * @return array<string,mixed>|null The object, or null when absent.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function findObject(string $objectId, string $schema): ?array {
		$entity = $this->objectService->find(id: $objectId, register: 'decidesk', schema: $schema);
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findObject()
}//end class
