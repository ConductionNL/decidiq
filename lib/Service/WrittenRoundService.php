<?php

/**
 * Voting rounds held in writing, without a meeting.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\WrittenRoundServiceInterface;

/**
 * The seam the voting controller calls for a written round: open, answer,
 * and whether a round is held in writing.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */
class WrittenRoundService implements WrittenRoundServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param WrittenRoundOpener   $opener   Opens a round.
	 * @param WrittenRoundAnswerer $answerer Stores an answer.
	 */
	public function __construct(
		private readonly WrittenRoundOpener $opener,
		private readonly WrittenRoundAnswerer $answerer,
	) {
	}//end __construct()

	/**
	 * Open a voting round held in writing.
	 *
	 * @param array<string, mixed> $params The request body.
	 * @param string               $userId The Nextcloud user opening it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public function open(array $params, string $userId): array {
		return $this->opener->open(params: $params, userId: $userId);
	}//end open()

	/**
	 * The round when it is held in writing, else null.
	 *
	 * @param string $roundId The round.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function writtenRound(string $roundId): ?array {
		return $this->answerer->writtenRound(roundId: $roundId);
	}//end writtenRound()

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
	public function answer(string $roundId, string $userId, string $value): array {
		return $this->answerer->answer(roundId: $roundId, userId: $userId, value: $value);
	}//end answer()
}//end class
