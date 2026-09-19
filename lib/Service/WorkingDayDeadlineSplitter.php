<?php

/**
 * Working Day Deadline Splitter
 *
 * One deadline for a whole review, divided evenly over its steps.
 *
 * WHY WORKING DAYS AND NOT CALENDAR DAYS
 * ---------------------------------------
 * A nine day review starting on a Thursday, split over three steps on calendar
 * days, puts step one on a Sunday. Nobody reads it, the step is overdue before
 * anybody is at their desk, and the whole point of splitting a deadline is to
 * tell each reviewer when their own part is expected. Weekends are not term.
 *
 * WHY THE LAST STEP LANDS EXACTLY ON THE DEADLINE
 * ------------------------------------------------
 * The deadline is the promise made to whoever asked for the review. Rounding it
 * forwards would quietly move that promise; rounding it backwards would make
 * every route report itself late a day early. So the last step is pinned and the
 * earlier ones are spread behind it.
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
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * Divides one deadline over a route's steps, in working days.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
 */
final class WorkingDayDeadlineSplitter {
	/**
	 * A due date per step, in order.
	 *
	 * @param DateTimeImmutable $from When the route starts.
	 * @param DateTimeImmutable $deadline The deadline for the whole route.
	 * @param int $steps How many steps to divide it over.
	 *
	 * @return array<int, string> One ISO-8601 instant per step, in order. Empty
	 *         when there is nothing to divide.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function split(DateTimeImmutable $from, DateTimeImmutable $deadline, int $steps): array {
		if ($steps < 1) {
			return [];
		}

		$available = $this->workingDaysBetween(from: $from, to: $deadline);
		if ($available < 1) {
			// A deadline today, in the past, or only across a weekend: every
			// step is due at the deadline. Spreading backwards from it would
			// produce due dates before the route existed.
			return array_fill(0, $steps, $deadline->format(DateTimeImmutable::ATOM));
		}

		$dueDates = [];
		for ($step = 1; $step <= $steps; $step++) {
			if ($step === $steps) {
				// Pinned. The deadline is the promise, and it is not ours to
				// round.
				$dueDates[] = $deadline->format(DateTimeImmutable::ATOM);
				continue;
			}

			$share = (int)floor((($available * $step) / $steps));
			$share = max(1, $share);
			$dueDates[] = $this->addWorkingDays(to: $from, days: $share)
				->setTime(
					(int)$deadline->format('H'),
					(int)$deadline->format('i'),
					(int)$deadline->format('s')
				)
				->format(DateTimeImmutable::ATOM);
		}

		return $dueDates;
	}//end split()

	/**
	 * How many working days lie between two instants.
	 *
	 * @param DateTimeImmutable $from The start.
	 * @param DateTimeImmutable $to The end.
	 *
	 * @return int The count, never negative.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function workingDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
		if ($to <= $from) {
			return 0;
		}

		$cursor = $from->setTime(0, 0);
		$end = $to->setTime(0, 0);
		$days = 0;

		while ($cursor < $end) {
			$cursor = $cursor->modify('+1 day');
			if ($this->isWorkingDay(day: $cursor) === true) {
				$days++;
			}
		}

		return $days;
	}//end workingDaysBetween()

	/**
	 * The instant a number of working days after another.
	 *
	 * @param DateTimeImmutable $to The start.
	 * @param int $days How many working days to add.
	 *
	 * @return DateTimeImmutable The result.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function addWorkingDays(DateTimeImmutable $to, int $days): DateTimeImmutable {
		$cursor = $to;
		$added = 0;

		while ($added < $days) {
			$cursor = $cursor->modify('+1 day');
			if ($this->isWorkingDay(day: $cursor) === true) {
				$added++;
			}
		}

		return $cursor;
	}//end addWorkingDays()

	/**
	 * Whether a day is a working day.
	 *
	 * Weekends only. Public holidays are deliberately NOT handled here: they
	 * differ per country and per organisation, and guessing them would make a
	 * step's due date silently wrong in a way nobody could trace back to this
	 * class. A holiday calendar is its own change.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return bool True on Monday to Friday.
	 */
	private function isWorkingDay(DateTimeImmutable $day): bool {
		return ((int)$day->format('N') <= 5);
	}//end isWorkingDay()
}//end class
