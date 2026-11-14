<?php

/**
 * Decidiq Amendment Text Merger
 *
 * Works one adopted amendment's structured change into a motion's wording and
 * records the step, so an adopted motion has a consolidated text instead of
 * the amendments pasted underneath it (#1394).
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Pure text merge of an amendment into a motion; no storage, no dependencies.
 *
 * The amendment's change is read as:
 * - `targetPassage` set: replace the first occurrence of that passage with
 *   `proposedText`; a passage that is not in the text is refused.
 * - no passage: `proposedText` (or, for a legacy amendment, `text`) becomes
 *   the whole new wording, the same reading the amendment diff view uses.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class AmendmentTextMerger {
	/**
	 * A change adopted by vote.
	 *
	 * @var string
	 */
	public const KIND_AMENDMENT = 'amendment';

	/**
	 * A change the chair or secretary accepted without a vote.
	 *
	 * @var string
	 */
	public const KIND_RECOMMENDATION = 'recommendation';

	/**
	 * Return the motion fields after working the amendment into its text.
	 *
	 * Returns null when the amendment is already in the motion's history, so
	 * re-closing a round never applies the same change twice.
	 *
	 * @param array<string, mixed> $motionData The parent motion's object data
	 * @param array<string, mixed> $amendmentData The adopted amendment's object data
	 * @param string $amendmentId UUID of the amendment
	 * @param string $kind What the change is: `amendment` (adopted by vote) or
	 *                     `recommendation` (accepted by the chair or secretary).
	 *                     The history entry names the change under this key.
	 *
	 * @throws RuntimeException When the amendment carries no replacement, or its passage is not in the text
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 *
	 * @return array{text: string, originalText: string, amendmentHistory: list<array<string, string>>}|null
	 */
	public function merge(array $motionData, array $amendmentData, string $amendmentId, string $kind = self::KIND_AMENDMENT): ?array {
		$history = array_values((array) ($motionData['amendmentHistory'] ?? []));
		if ($this->isApplied(history: $history, amendmentId: $amendmentId, kind: $kind) === true) {
			return null;
		}

		$before = (string) ($motionData['text'] ?? '');
		[$mode, $after] = $this->applyChange(before: $before, amendmentData: $amendmentData, amendmentId: $amendmentId);

		$original = (string) ($motionData['originalText'] ?? '');
		if ($original === '') {
			$original = $before;
		}

		$entry = [
			'amendment' => $amendmentId,
			'mode'      => $mode,
			'before'    => $before,
			'after'     => $after,
			'appliedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
		if ($kind === self::KIND_RECOMMENDATION) {
			// A recommendation is not an amendment: the entry names it under
			// its own key, so the history tells the two apart (REQ-MCR-003).
			unset($entry['amendment']);
			$entry = ['recommendation' => $amendmentId, 'kind' => self::KIND_RECOMMENDATION] + $entry;
		}

		$history[] = $entry;

		return [
			'text'             => $after,
			'originalText'     => $original,
			'amendmentHistory' => $history,
		];

	}//end merge()

	/**
	 * Whether the amendment already has an entry in the motion's history.
	 *
	 * @param array<int, mixed> $history The motion's amendmentHistory
	 * @param string $amendmentId UUID of the amendment
	 * @param string $kind The key the history entry names the change under
	 *
	 * @return bool
	 */
	private function isApplied(array $history, string $amendmentId, string $kind): bool {
		foreach ($history as $entry) {
			if (is_array($entry) === true && ($entry[$kind] ?? null) === $amendmentId) {
				return true;
			}
		}

		return false;

	}//end isApplied()

	/**
	 * Apply the amendment's change to a text.
	 *
	 * @param string $before The motion text before this amendment
	 * @param array<string, mixed> $amendmentData The amendment's object data
	 * @param string $amendmentId UUID of the amendment, for the error message
	 *
	 * @throws RuntimeException When there is no replacement, or the passage is not in the text
	 *
	 * @return array{0: string, 1: string} The mode (passage or full) and the new text
	 */
	private function applyChange(string $before, array $amendmentData, string $amendmentId): array {
		$passage = (string) ($amendmentData['targetPassage'] ?? '');
		$replace = (string) ($amendmentData['proposedText'] ?? '');

		if ($passage === '') {
			if ($replace === '') {
				$replace = (string) ($amendmentData['text'] ?? '');
			}

			if ($replace === '') {
				throw new RuntimeException("Amendment $amendmentId carries no replacement text");
			}

			return ['full', $replace];
		}

		$position = mb_strpos($before, $passage);
		if ($position === false) {
			throw new RuntimeException("Amendment $amendmentId: the passage to replace does not occur in the motion text");
		}

		$after = mb_substr($before, 0, $position).$replace.mb_substr($before, ($position + mb_strlen($passage)));

		return ['passage', $after];

	}//end applyChange()
}//end class
