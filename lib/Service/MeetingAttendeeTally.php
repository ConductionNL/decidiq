<?php

/**
 * Counts who attended a meeting of one governance body.
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
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Closure;

/**
 * One count: the body's members seen, those marked present, and whether any
 * attendance was taken at all.
 *
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
final class MeetingAttendeeTally {

	/**
	 * Members of the body seen so far.
	 *
	 * @var integer
	 */
	private int $members = 0;

	/**
	 * Members marked present so far.
	 *
	 * @var integer
	 */
	private int $present = 0;

	/**
	 * Whether any member has an attendance status.
	 *
	 * @var boolean
	 */
	private bool $attendanceTaken = false;

	/**
	 * Create a tally.
	 *
	 * @param string  $bodyId  The meeting's governance body
	 * @param Closure $overlay Gives a participant this meeting's attendance status
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $bodyId,
		private readonly Closure $overlay,
	) {
	}//end __construct()

	/**
	 * Count one participant; one of another body, or no participant, is skipped.
	 *
	 * @param array<string,mixed>|null $participant The participant's data
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	public function add(?array $participant): void {
		if ($this->belongs(participant: $participant) === false) {
			return;
		}

		$this->members++;
		$this->record(status: (string)(($this->overlay)($participant)['attendanceStatus'] ?? ''));
	}//end add()

	/**
	 * Whether a participant is a member of the meeting's body; one without a body counts as a member.
	 *
	 * @param array<string,mixed>|null $participant The participant's data
	 *
	 * @return bool
	 */
	private function belongs(?array $participant): bool {
		return ($participant !== null && ($participant['governanceBody'] ?? $this->bodyId) === $this->bodyId);
	}//end belongs()

	/**
	 * Record one member's attendance status for this meeting.
	 *
	 * @param string $status The status, '' when none was recorded
	 *
	 * @return void
	 */
	private function record(string $status): void {
		$this->attendanceTaken = ($this->attendanceTaken === true || $status !== '');
		if ($status === 'present') {
			$this->present++;
		}
	}//end record()

	/**
	 * The members marked present, or every member when no attendance was taken.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	public function count(): int {
		if ($this->attendanceTaken === true) {
			return $this->present;
		}

		return $this->members;
	}//end count()
}//end class
