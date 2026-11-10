<?php

/**
 * Decidiq Vote Tally
 *
 * The weighted for, against and abstain counts a result is computed from.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Weighted counts of one voting round.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
final class VoteTally {
	/**
	 * Describe one tally.
	 *
	 * @param int $votesFor     Weighted for-votes
	 * @param int $votesAgainst Weighted against-votes
	 * @param int $abstentions  Weighted abstentions
	 */
	public function __construct(
		public readonly int $votesFor,
		public readonly int $votesAgainst,
		public readonly int $abstentions,
	) {
	}//end __construct()

	/**
	 * Every vote cast, abstentions included.
	 *
	 * @return int The total
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function total(): int {
		return ($this->votesFor + $this->votesAgainst + $this->abstentions);
	}//end total()

	/**
	 * The calculation base: for plus against, or every vote when abstentions count.
	 *
	 * @param string $abstentionHandling The effective abstention mode ('exclude' or 'count')
	 *
	 * @return int The base
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function base(string $abstentionHandling): int {
		if ($abstentionHandling === 'count') {
			return $this->total();
		}

		return ($this->votesFor + $this->votesAgainst);
	}//end base()
}//end class
