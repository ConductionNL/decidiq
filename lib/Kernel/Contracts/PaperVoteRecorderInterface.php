<?php

/**
 * Contract of PaperVoteRecorder
 *
 * The public surface of PaperVoteRecorder that other components call, so they depend
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
 * Contract of PaperVoteRecorder.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaperVoteRecorderInterface {

	/**
	 * Record a named vote taken on paper: one ballot per member, entered by
	 * the chair or the secretary (board DcStemmenInvoeren).
	 *
	 * Every ballot goes through the caster a member's own vote goes through,
	 * so the round must be open and each member must sit in the meeting, hold
	 * a seat and not be recused. A ballot the caster refuses is reported with
	 * its reason and the others are still recorded. The caller's authority is
	 * checked by VotingController::record().
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param array<int, mixed> $votes Rows of `{participant, value}`, value for | against | abstain
	 * @param string $callerUid The Nextcloud UID of the chair or secretary entering them
	 *
	 * @return array{recorded: int, refused: array<int, array{participant: string, message: string}>}
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function recordVotes(string $votingRoundId, array $votes, string $callerUid): array;
}//end interface
