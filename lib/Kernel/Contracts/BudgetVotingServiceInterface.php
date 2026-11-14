<?php

/**
 * Contract of BudgetVotingService
 *
 * The public surface of BudgetVotingService that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use RuntimeException;

/**
 * Contract of BudgetVotingService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetVotingServiceInterface {

	/**
	 * Cast one advisory vote on a validated proposal during the voting window.
	 *
	 * Enforces the window guard (round in 'voting' + before votingDeadline,
	 * server-side) and that the proposal is 'validated', then delegates the
	 * one-vote integrity + atomic tally to the shared AdvisoryVoteService.
	 *
	 * The window guard fails CLOSED: an unresolvable or missing round is
	 * refused, not waved through.
	 *
	 * @param string $proposalId The BudgetProposal UUID.
	 * @param string $voterId The authenticated citizen NC UID.
	 * @param string $value 'voor' | 'tegen'.
	 *
	 * @return array<string, mixed> ['vote' => ..., 'votesFor' => int, 'votesAgainst' => int].
	 *
	 * @throws RuntimeException When the proposal is missing or not validated.
	 * @throws ParticipationWindowClosedException When the round cannot be established or is not in its voting window (HTTP 400).
	 * @throws InvalidArgumentException When the value is invalid.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function castAdvisoryVote(string $proposalId, string $voterId, string $value): array;

	/**
	 * Submit a budget proposal during a round's submission phase.
	 *
	 * Validates server-side that the round accepts proposals (status +
	 * deadline) and that requestedAmount is positive and within totalAmount.
	 *
	 * @param string $budgetId The ParticipatoryBudget round UUID.
	 * @param string $title The proposal title.
	 * @param string $description The proposal description.
	 * @param float $requested The requested amount.
	 * @param string $submitterId The citizen's NC UID.
	 * @param string $category Optional category.
	 *
	 * @return array<string, mixed> The created BudgetProposal object.
	 *
	 * @throws RuntimeException When the round is missing.
	 * @throws ParticipationWindowClosedException When the round is not accepting proposals (HTTP 400).
	 * @throws ParticipationValidationException When the amount is not positive or exceeds the round (HTTP 422).
	 * @throws InvalidArgumentException When the title is empty.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function submitProposal(
		string $budgetId,
		string $title,
		string $description,
		float $requested,
		string $submitterId,
		string $category = '',
	): array;

	/**
	 * Staff-validate a submitted proposal (submitted -> validated | rejected).
	 *
	 * Only 'validated' proposals are votable.
	 *
	 * @param string $proposalId The proposal UUID.
	 * @param bool $approve True to validate, false to reject.
	 *
	 * @return array<string, mixed> The updated proposal object.
	 *
	 * @throws RuntimeException When the proposal is not found.
	 * @throws InvalidArgumentException When the proposal is not in 'submitted'.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function validateProposal(string $proposalId, bool $approve): array;
}//end interface
