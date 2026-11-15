<?php

/**
 * Contract of VoteBreakdownService
 *
 * The public surface of VoteBreakdownService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Contract of VoteBreakdownService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VoteBreakdownServiceInterface {

	/**
	 * Whether the current user can read the round. OpenRegister decides: the
	 * round is looked up with the user's own rights.
	 *
	 * @param string $roundId The voting round UUID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
	 */
	public function canReadRound(string $roundId): bool;

	/**
	 * The breakdown of one round, or null when the round cannot be read by
	 * the current user (OpenRegister's own access rules decide).
	 *
	 * @param string $roundId The voting round UUID
	 *
	 * @return array<string, mixed>|null secret, totals, members (by name) and factions (counts)
	 *
	 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
	 */
	public function forRound(string $roundId): ?array;
}//end interface
