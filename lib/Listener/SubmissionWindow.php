<?php

/**
 * Decidiq Submission Window
 *
 * The rules of a meeting's submission window for motions and amendments: when
 * it opens, when it closes, and whether a window is inverted.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

/**
 * Pure rules over a meeting's `submissionOpensAt` and `submissionDeadline`.
 *
 * SubmissionDeadlineListener reads the meeting and applies these; keeping them
 * apart keeps the listener to routing and lookups.
 *
 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md
 */
class SubmissionWindow {

	/**
	 * The message for a submission after the deadline.
	 *
	 * @var string
	 */
	public const DEADLINE_PASSED_MESSAGE = 'The submission deadline for this meeting has passed; new motions and amendments can no longer be submitted.';

	/**
	 * The message for a submission before the window opens; `%s` is the opening time.
	 *
	 * @var string
	 */
	public const NOT_YET_OPEN_MESSAGE = 'Submission of motions and amendments for this meeting opens on %s.';

	/**
	 * The message for a meeting whose window opens after it closes.
	 *
	 * @var string
	 */
	public const INVERTED_WINDOW_MESSAGE = 'The submission window opens after it closes.';

	/**
	 * Parse a stored date-time into a unix timestamp.
	 *
	 * @param mixed $value The stored value
	 *
	 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-001-a-meeting-can-open-submission-at-a-set-time
	 *
	 * @return int|null The timestamp, or null when empty or unparseable
	 */
	public function timestamp(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}//end timestamp()

	/**
	 * Read the window off a meeting payload.
	 *
	 * @param array<string, mixed> $meeting Meeting payload
	 *
	 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return array{opensAt: int|null, deadline: int|null}
	 */
	public function fromMeeting(array $meeting): array {
		return [
			'opensAt'  => $this->timestamp(value: ($meeting['submissionOpensAt'] ?? null)),
			'deadline' => $this->timestamp(value: ($meeting['submissionDeadline'] ?? null)),
		];
	}//end fromMeeting()

	/**
	 * Whether a meeting's window opens at or after its deadline.
	 *
	 * @param array<string, mixed> $meeting Meeting payload being saved
	 *
	 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return bool True when both times are set and the opening is not before the deadline
	 */
	public function isInverted(array $meeting): bool {
		$window = $this->fromMeeting(meeting: $meeting);
		if ($window['opensAt'] === null || $window['deadline'] === null) {
			return false;
		}

		return $window['opensAt'] >= $window['deadline'];
	}//end isInverted()

	/**
	 * The refusal for a submission at a moment, or null when it may go ahead.
	 *
	 * @param array{opensAt: int|null, deadline: int|null} $window The meeting's window
	 * @param int                                           $now    The submission moment
	 *
	 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return array<string, string>|null The errors for the event, or null
	 */
	public function refusal(array $window, int $now): ?array {
		if ($window['opensAt'] !== null && $window['opensAt'] > $now) {
			return [
				'message'           => sprintf(self::NOT_YET_OPEN_MESSAGE, date('j F Y H:i', $window['opensAt'])),
				'submissionOpensAt' => date(DATE_ATOM, $window['opensAt']),
			];
		}

		if ($window['deadline'] !== null && $window['deadline'] < $now) {
			return [
				'message'            => self::DEADLINE_PASSED_MESSAGE,
				'submissionDeadline' => date(DATE_ATOM, $window['deadline']),
			];
		}

		return null;
	}//end refusal()
}//end class
