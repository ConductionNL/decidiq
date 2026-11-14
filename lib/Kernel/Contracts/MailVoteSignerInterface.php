<?php

/**
 * Contract of MailVoteSigner
 *
 * The public surface of MailVoteSigner that other components call, so they depend
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
 * Contract of MailVoteSigner.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailVoteSignerInterface {

	/**
	 * Compute the HMAC for a _mail entry.
	 *
	 * The signed payload is the canonical concatenation:
	 *   participantId + ':' + roundId + ':' + timestamp
	 * Covers the three fields that identify a unique, time-bound vote instruction.
	 * `replyBody` is intentionally excluded from the signed payload so that the
	 * ingestion path does not need to know the vote value at signing time (the vote
	 * body is trusted only after the signature is verified).
	 *
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID
	 * @param string $timestamp ISO 8601 timestamp written by the ingestion path
	 *
	 * @return string The hex HMAC
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function compute(string $participantId, string $roundId, string $timestamp): string;

	/**
	 * Sign a _mail entry array and return it with the hmac field set.
	 *
	 * @param array<string,mixed> $entry The raw _mail entry (must contain participantId and timestamp)
	 * @param string $roundId The VotingRound UUID (used to derive the secret)
	 *
	 * @return array<string,mixed> The entry with hmac appended
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function sign(array $entry, string $roundId): array;
}//end interface
