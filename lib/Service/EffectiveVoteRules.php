<?php

/**
 * Decidiq Effective Vote Rules
 *
 * The rules a round's result is computed under, once unknown stored values
 * have fallen back to their defaults, plus the chair's casting vote.
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
 * The effective threshold, abstention mode, tie-break rule and casting vote.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
final class EffectiveVoteRules {
	/**
	 * The effective tie-break rule.
	 *
	 * @var string
	 */
	public string $tieBreakRule = 'rejected';

	/**
	 * The chair's casting vote as stored, null when none.
	 *
	 * @var mixed
	 */
	public mixed $chairCastingVote = null;

	/**
	 * Describe the effective rules; the tie-break rule and casting vote are set beside them.
	 *
	 * @param string $threshold          The effective majority threshold
	 * @param string $abstentionHandling The effective abstention mode
	 */
	public function __construct(
		public readonly string $threshold,
		public readonly string $abstentionHandling,
	) {
	}//end __construct()
}//end class
