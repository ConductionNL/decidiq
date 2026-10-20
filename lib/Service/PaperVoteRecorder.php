<?php

/**
 * Decidiq Paper Vote Recorder
 *
 * A named vote taken on paper, entered by the chair or the secretary (board
 * DcStemmenInvoeren): one ballot per member, each cast through the caster a
 * member's own vote goes through.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Records paper ballots through VoteCastingService.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class PaperVoteRecorder {
	/**
	 * Constructor.
	 *
	 * @param VoteCastingService $caster Casts each ballot as the member's own vote
	 *
	 * @return void
	 */
	public function __construct(
		private readonly VoteCastingService $caster,
	) {
	}//end __construct()

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
	public function recordVotes(string $votingRoundId, array $votes, string $callerUid): array {
		$recorded = 0;
		$refused = [];
		foreach ($votes as $row) {
			$participant = '';
			$value = '';
			if (is_array($row) === true) {
				$participant = trim((string)($row['participant'] ?? ''));
				$value = (string)($row['value'] ?? '');
			}

			if ($participant === '' || in_array($value, ['for', 'against', 'abstain'], true) === false) {
				$refused[] = ['participant' => $participant, 'message' => 'A row needs a member and for, against or abstain'];
				continue;
			}

			try {
				$this->caster->castVote(
					votingRoundId: $votingRoundId,
					participantId: $participant,
					value: $value,
					isProxy: false,
					delegatorId: null,
					callerUid: $callerUid
				);
				$recorded++;
			} catch (\RuntimeException $e) {
				$refused[] = ['participant' => $participant, 'message' => $e->getMessage()];
			}
		}//end foreach

		return ['recorded' => $recorded, 'refused' => $refused];
	}//end recordVotes()
}//end class
