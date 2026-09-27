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
	 * Return the motion fields after working the amendment into its text.
	 *
	 * Returns null when the amendment is already in the motion's history, so
	 * re-closing a round never applies the same change twice.
	 *
	 * @param array<string, mixed> $motionData The parent motion's object data
	 * @param array<string, mixed> $amendmentData The adopted amendment's object data
	 * @param string $amendmentId UUID of the amendment
	 *
	 * @throws RuntimeException When the amendment carries no replacement, or its passage is not in the text
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return array{text: string, originalText: string, amendmentHistory: list<array<string, string>>}|null
	 */
	public function merge(array $motionData, array $amendmentData, string $amendmentId): ?array {
		$history = array_values((array) ($motionData['amendmentHistory'] ?? []));
		foreach ($history as $entry) {
			if (is_array($entry) === true && ($entry['amendment'] ?? null) === $amendmentId) {
				return null;
			}
		}

		$before  = (string) ($motionData['text'] ?? '');
		$passage = (string) ($amendmentData['targetPassage'] ?? '');
		$replace = (string) ($amendmentData['proposedText'] ?? '');
		if ($replace === '' && $passage === '') {
			$replace = (string) ($amendmentData['text'] ?? '');
		}

		$mode  = 'full';
		$after = $replace;
		if ($passage !== '') {
			$position = mb_strpos($before, $passage);
			if ($position === false) {
				throw new RuntimeException("Amendment $amendmentId: the passage to replace does not occur in the motion text");
			}

			$mode  = 'passage';
			$after = mb_substr($before, 0, $position).$replace.mb_substr($before, ($position + mb_strlen($passage)));
		} else if ($replace === '') {
			throw new RuntimeException("Amendment $amendmentId carries no replacement text");
		}

		$original = (string) ($motionData['originalText'] ?? '');
		if ($original === '') {
			$original = $before;
		}

		$history[] = [
			'amendment' => $amendmentId,
			'mode'      => $mode,
			'before'    => $before,
			'after'     => $after,
			'appliedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		return [
			'text'             => $after,
			'originalText'     => $original,
			'amendmentHistory' => $history,
		];

	}//end merge()
}//end class
