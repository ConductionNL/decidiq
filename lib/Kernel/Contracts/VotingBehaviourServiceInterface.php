<?php

/**
 * Contract of VotingBehaviourService
 *
 * The public surface of VotingBehaviourService that other components call, so they depend
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
 * Contract of VotingBehaviourService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingBehaviourServiceInterface {

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
	 * @param string $participantId The participant UUID
	 * @param string $governanceBodyId The governance body UUID
	 *
	 * @return array<string,mixed> Statistics array with totalRounds, participated, participationRate,
	 *                             votesFor, votesAgainst, votesAbstain, proxiesGiven, proxiesReceived
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function getStats(string $participantId, string $governanceBodyId): array;
}//end interface
