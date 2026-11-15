<?php

/**
 * Decidiq Broadcast Windows
 *
 * The arithmetic of a broadcast's public windows, kept apart from the service
 * that talks to the streaming service. A window is `{start, end?,
 * recordingStart}` in seconds: `start` and `end` from the meeting's opening
 * (the origin TranscriptAlignmentService uses), `recordingStart` the second in
 * the service's recording where the window begins.
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Open, close and read a broadcast's public windows.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
 */
final class BroadcastWindows {
	/**
	 * The stored windows as a list of integer maps; entries without a start are dropped.
	 *
	 * @param array<string, mixed> $broadcast The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return list<array<string, int>>
	 */
	public function read(array $broadcast): array {
		$windows = [];
		foreach ((array)($broadcast['publicWindows'] ?? []) as $window) {
			if (is_array($window) === false || isset($window['start']) === false) {
				continue;
			}

			$windows[] = array_map('intval', $window);
		}

		return $windows;
	}//end read()

	/**
	 * The windows with a new open one at a second.
	 *
	 * `recordingStart` is what the service reported, else the sum of the earlier
	 * windows (a service that cuts the paused time).
	 *
	 * @param array<string, mixed> $broadcast The broadcast
	 * @param int                  $second    Seconds from the meeting's opening
	 * @param array<string, mixed> $answer    The service's answer, which may name recordingStart
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return list<array<string, int>>
	 */
	public function open(array $broadcast, int $second, array $answer): array {
		$windows   = $this->read(broadcast: $broadcast);
		$recording = 0;
		foreach ($windows as $window) {
			$recording += max(0, (int)($window['end'] ?? $window['start']) - (int)$window['start']);
		}

		$reported = ($answer['recordingStart'] ?? null);
		if (is_int($reported) === true && $reported >= 0) {
			$recording = $reported;
		}

		$windows[] = ['start' => $second, 'recordingStart' => $recording];
		return $windows;
	}//end open()

	/**
	 * The windows with the open one closed at a second, never before its start.
	 *
	 * @param array<string, mixed> $broadcast The broadcast
	 * @param int                  $second    Seconds from the meeting's opening
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return list<array<string, int>>
	 */
	public function close(array $broadcast, int $second): array {
		$windows = $this->read(broadcast: $broadcast);
		$last    = (count($windows) - 1);
		if ($last >= 0 && isset($windows[$last]['end']) === false) {
			$windows[$last]['end'] = max((int)$windows[$last]['start'], $second);
		}

		return $windows;
	}//end close()
}//end class
