<?php

/**
 * Contract of MailVoteReplyProcessor
 *
 * The public surface of MailVoteReplyProcessor that other components call, so they depend
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
 * Contract of MailVoteReplyProcessor.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailVoteReplyProcessorInterface {

	/**
	 * Parse the first non-empty line of an email reply for a vote keyword.
	 *
	 * Returns the canonical vote value (for/against/abstain) or null if unrecognised.
	 *
	 * @param string $body The email reply body
	 *
	 * @return string|null The canonical vote value or null
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function parseVoteKeyword(string $body): ?string;

	/**
	 * Process the mail reply metadata on a single VotingRound.
	 *
	 * Persists the round only when at least one entry actually changed.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<string,mixed> $round The VotingRound object
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function processRound(object $objectService, array $round, string $roundId): void;
}//end interface
