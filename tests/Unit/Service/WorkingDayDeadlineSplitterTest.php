<?php

/**
 * Unit tests for WorkingDayDeadlineSplitter.
 *
 * Date arithmetic on a fixed clock. The case the requirement names is here
 * verbatim: nine working days over three steps lands on working days three, six
 * and nine.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\WorkingDayDeadlineSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the split, the weekend skip, and the deadline that is pinned.
 */
final class WorkingDayDeadlineSplitterTest extends TestCase {
	/**
	 * The splitter under test.
	 *
	 * @var WorkingDayDeadlineSplitter
	 */
	private WorkingDayDeadlineSplitter $splitter;

	/**
	 * Build the splitter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->splitter = new WorkingDayDeadlineSplitter();
	}//end setUp()

	/**
	 * Nine working days over three steps, the case the requirement names.
	 *
	 * Monday 2026-09-14 plus nine working days is Friday 2026-09-25. The steps
	 * land on working days three (Thursday the 17th), six (Tuesday the 22nd) and
	 * nine (the deadline itself).
	 *
	 * @return void
	 */
	public function testNineWorkingDaysOverThreeSteps(): void {
		$from = new DateTimeImmutable('2026-09-14T09:00:00+00:00');
		$deadline = new DateTimeImmutable('2026-09-25T17:00:00+00:00');

		$this->assertSame(9, $this->splitter->workingDaysBetween(from: $from, to: $deadline));

		$dueDates = $this->splitter->split(from: $from, deadline: $deadline, steps: 3);

		$this->assertSame(
			[
				'2026-09-17T17:00:00+00:00',
				'2026-09-22T17:00:00+00:00',
				'2026-09-25T17:00:00+00:00',
			],
			$dueDates
		);
	}//end testNineWorkingDaysOverThreeSteps()

	/**
	 * No step lands on a Saturday or a Sunday.
	 *
	 * A step due on a weekend is overdue before anybody is at their desk, which
	 * is the whole reason the split counts working days.
	 *
	 * @return void
	 */
	public function testNoStepLandsOnAWeekend(): void {
		$from = new DateTimeImmutable('2026-09-17T09:00:00+00:00');
		$deadline = new DateTimeImmutable('2026-10-09T17:00:00+00:00');

		foreach ($this->splitter->split(from: $from, deadline: $deadline, steps: 5) as $dueAt) {
			$day = (int)(new DateTimeImmutable($dueAt))->format('N');
			$this->assertLessThanOrEqual(5, $day, sprintf('%s falls on a weekend.', $dueAt));
		}
	}//end testNoStepLandsOnAWeekend()

	/**
	 * The last step lands exactly on the deadline, never a day either side.
	 *
	 * @return void
	 */
	public function testTheLastStepIsPinnedToTheDeadline(): void {
		$from = new DateTimeImmutable('2026-09-14T09:00:00+00:00');
		$deadline = new DateTimeImmutable('2026-09-30T12:30:00+00:00');

		foreach ([1, 2, 3, 4, 7] as $steps) {
			$dueDates = $this->splitter->split(from: $from, deadline: $deadline, steps: $steps);

			$this->assertCount($steps, $dueDates);
			$this->assertSame(
				$deadline->format(DateTimeImmutable::ATOM),
				$dueDates[($steps - 1)],
				sprintf('With %d steps the last one moved off the deadline.', $steps)
			);
		}
	}//end testTheLastStepIsPinnedToTheDeadline()

	/**
	 * Every step's due date is on or after the one before it.
	 *
	 * @return void
	 */
	public function testTheDueDatesNeverGoBackwards(): void {
		$dueDates = $this->splitter->split(
			from: new DateTimeImmutable('2026-09-14T09:00:00+00:00'),
			deadline: new DateTimeImmutable('2026-10-16T17:00:00+00:00'),
			steps: 6,
		);

		for ($index = 1; $index < count($dueDates); $index++) {
			$this->assertGreaterThanOrEqual(
				$dueDates[($index - 1)],
				$dueDates[$index],
				'A later step was given an earlier due date.'
			);
		}
	}//end testTheDueDatesNeverGoBackwards()

	/**
	 * A deadline with no working days before it gives every step the deadline.
	 *
	 * Spreading backwards from it would produce due dates before the route
	 * existed, and a step due yesterday reads as overdue the moment it is made.
	 *
	 * @return void
	 */
	public function testADeadlineWithNoRoomGivesEveryStepTheDeadline(): void {
		$from = new DateTimeImmutable('2026-09-19T09:00:00+00:00');
		$deadline = new DateTimeImmutable('2026-09-20T17:00:00+00:00');

		$this->assertSame(
			[
				'2026-09-20T17:00:00+00:00',
				'2026-09-20T17:00:00+00:00',
			],
			$this->splitter->split(from: $from, deadline: $deadline, steps: 2)
		);
	}//end testADeadlineWithNoRoomGivesEveryStepTheDeadline()

	/**
	 * A deadline in the past does not produce due dates before it.
	 *
	 * @return void
	 */
	public function testADeadlineInThePastGivesEveryStepTheDeadline(): void {
		$from = new DateTimeImmutable('2026-09-18T09:00:00+00:00');
		$deadline = new DateTimeImmutable('2026-09-01T17:00:00+00:00');

		$dueDates = $this->splitter->split(from: $from, deadline: $deadline, steps: 3);

		$this->assertSame(array_fill(0, 3, '2026-09-01T17:00:00+00:00'), $dueDates);
	}//end testADeadlineInThePastGivesEveryStepTheDeadline()

	/**
	 * No steps, nothing to divide.
	 *
	 * @return void
	 */
	public function testNoStepsProducesNoDueDates(): void {
		$this->assertSame(
			[],
			$this->splitter->split(
				from: new DateTimeImmutable('2026-09-14T09:00:00+00:00'),
				deadline: new DateTimeImmutable('2026-09-25T17:00:00+00:00'),
				steps: 0,
			)
		);
	}//end testNoStepsProducesNoDueDates()

	/**
	 * A weekend is skipped when adding working days.
	 *
	 * @return void
	 */
	public function testAddingWorkingDaysSkipsTheWeekend(): void {
		$friday = new DateTimeImmutable('2026-09-18T09:00:00+00:00');

		$this->assertSame(
			'2026-09-21',
			$this->splitter->addWorkingDays(to: $friday, days: 1)->format('Y-m-d')
		);
		$this->assertSame(
			'2026-09-25',
			$this->splitter->addWorkingDays(to: $friday, days: 5)->format('Y-m-d')
		);
	}//end testAddingWorkingDaysSkipsTheWeekend()
}//end class
