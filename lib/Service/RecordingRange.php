<?php

/**
 * Decidiq Recording Range
 *
 * Reads an HTTP Range header for the meeting recording, so the browser's
 * player can seek to the moment an agenda item started.
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
 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * One byte range of a file, as a player asks for it.
 *
 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */
class RecordingRange {
	/**
	 * The byte range a Range header asks for.
	 *
	 * Serves one range: "bytes=a-", "bytes=a-b" or "bytes=-n". Anything else,
	 * several ranges included, is answered with the whole file.
	 *
	 * @param string|null $header The Range header, or null when absent
	 * @param int         $size   The file size in bytes
	 *
	 * @return array{0: int, 1: int}|null|false The first and last byte; null for the whole file; false when the range cannot be served
	 *
	 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function parse(?string $header, int $size): array|null|false {
		$matches = [];
		if ($header === null || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1) {
			return null;
		}

		[, $from, $to] = $matches;
		if ($from === '' && $to === '') {
			return null;
		}

		$last = ($size - 1);
		if ($from === '') {
			return [max(0, ($size - (int)$to)), $last];
		}

		if ((int)$from > $last) {
			return false;
		}

		$end = $last;
		if ($to !== '') {
			$end = min((int)$to, $last);
		}

		return [(int)$from, $end];
	}//end parse()
}//end class
