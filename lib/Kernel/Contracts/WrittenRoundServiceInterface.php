<?php

/**
 * Contract of WrittenRoundService
 *
 * The public surface of WrittenRoundService that other components call, so they depend
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
 * Contract of WrittenRoundService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WrittenRoundServiceInterface {

	/**
	 * Open a voting round held in writing.
	 *
	 * @param array<string, mixed> $params The request body.
	 * @param string               $userId The Nextcloud user opening it.
	 *
	 * @return array<string, mixed> The stored round.
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public function open(array $params, string $userId): array;

	/**
	 * The round when it is held in writing, else null.
	 *
	 * @param string $roundId The round.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function writtenRound(string $roundId): ?array;

	/**
	 * Store a member's answer and settle the round.
	 *
	 * @param string $roundId The round.
	 * @param string $userId  The Nextcloud user answering.
	 * @param string $value   for|against|abstain|objection.
	 *
	 * @return array{vote: array<string, mixed>, round: array<string, mixed>}
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function answer(string $roundId, string $userId, string $value): array;
}//end interface
