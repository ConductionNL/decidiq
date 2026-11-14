<?php

/**
 * Decidiq Voting Round Rules
 *
 * The configurable decision rules a voting round is opened under, carried as one
 * value instead of five loose parameters.
 *
 * Every field is nullable-by-omission on purpose: a null rule means "no explicit
 * caller value", which lets the opening body's process template supply the
 * default before the built-in fallback applies. That resolution order lives in
 * VotingRoundPreflight::resolveRules(); this object only carries the caller's
 * intent to it.
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
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Immutable carrier for the rules a voting round is opened under.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
 */
final class VotingRoundRules {
	/**
	 * Constructor for VotingRoundRules.
	 *
	 * @param string|null $voteThreshold Majority rule (see VotingService::VOTE_THRESHOLDS); null = body template default, then simple-majority
	 * @param string|null $abstentionHandling Abstention mode (see VotingService::ABSTENTION_MODES); null = body template default, then exclude
	 * @param string|null $tieBreakRule Tie-break rule (see VotingService::TIE_BREAK_RULES); null = body template default, then rejected
	 * @param string $subjectType What is being voted: 'motion' (default) or 'amendment' (fail closed)
	 * @param string|null $governanceBodyId Body opening the round; when set, its process template supplies rule defaults
	 * @param array<int, mixed> $options The options of a ranked-choice round (RankedBallotRules checks them); empty for every other method
	 *
	 * @return void
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function __construct(
		public readonly ?string $voteThreshold = null,
		public readonly ?string $abstentionHandling = null,
		public readonly ?string $tieBreakRule = null,
		public readonly string $subjectType = 'motion',
		public readonly ?string $governanceBodyId = null,
		public readonly array $options = [],
	) {

	}//end __construct()
}//end class
