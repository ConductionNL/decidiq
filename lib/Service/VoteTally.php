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
	 * @param int $for     Weighted for-votes
	 * @param int $against Weighted against-votes
	 * @param int $abstain Weighted abstentions
	 */
	public function __construct(
		public readonly int $for,
		public readonly int $against,
		public readonly int $abstain,
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
		return ($this->for + $this->against + $this->abstain);
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

		return ($this->for + $this->against);
	}//end base()
}//end class
