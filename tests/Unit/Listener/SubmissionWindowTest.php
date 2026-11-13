<?php

/**
 * Unit tests for SubmissionWindow.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @spec openspec/specs/motion-amendment/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\SubmissionWindow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for a meeting's motion submission window.
 *
 * @covers \OCA\Decidiq\Listener\SubmissionWindow
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class SubmissionWindowTest extends TestCase {
	public function testTimestampParsesStringsOnly(): void {
		$window = new SubmissionWindow();

		$this->assertSame(strtotime('2026-10-01T12:00:00+00:00'), $window->timestamp('2026-10-01T12:00:00+00:00'));
		$this->assertNull($window->timestamp(''));
		$this->assertNull($window->timestamp(null));
		$this->assertNull($window->timestamp(1700000000));
		$this->assertNull($window->timestamp('not a date at all'));
	}

	public function testFromMeetingReadsBothBounds(): void {
		$this->assertSame(
			['opensAt' => 1000, 'deadline' => null],
			(new SubmissionWindow())->fromMeeting(['submissionOpensAt' => '@1000', 'submissionDeadline' => 'garbage'])
		);
		$this->assertSame(['opensAt' => null, 'deadline' => null], (new SubmissionWindow())->fromMeeting([]));
	}

	public function testIsInvertedWhenItOpensAtOrAfterTheDeadline(): void {
		$window = new SubmissionWindow();

		$this->assertTrue($window->isInverted(['submissionOpensAt' => '@2000', 'submissionDeadline' => '@1000']));
		$this->assertTrue($window->isInverted(['submissionOpensAt' => '@1000', 'submissionDeadline' => '@1000']));
		$this->assertFalse($window->isInverted(['submissionOpensAt' => '@999', 'submissionDeadline' => '@1000']));
		$this->assertFalse($window->isInverted(['submissionOpensAt' => '@2000']));
		$this->assertFalse($window->isInverted(['submissionDeadline' => '@1000']));
	}

	public function testRefusesBeforeTheWindowOpens(): void {
		$this->assertSame(
			[
				'message' => sprintf(SubmissionWindow::NOT_YET_OPEN_MESSAGE, date('j F Y H:i', 2000)),
				'submissionOpensAt' => date(DATE_ATOM, 2000),
			],
			(new SubmissionWindow())->refusal(['opensAt' => 2000, 'deadline' => 3000], 1999)
		);
	}

	public function testRefusesAfterTheDeadline(): void {
		$this->assertSame(
			['message' => SubmissionWindow::DEADLINE_PASSED_MESSAGE, 'submissionDeadline' => date(DATE_ATOM, 3000)],
			(new SubmissionWindow())->refusal(['opensAt' => null, 'deadline' => 3000], 3001)
		);
	}

	public function testAcceptsWithinTheWindowIncludingItsBounds(): void {
		$window = new SubmissionWindow();

		$this->assertNull($window->refusal(['opensAt' => 2000, 'deadline' => 3000], 2000));
		$this->assertNull($window->refusal(['opensAt' => 2000, 'deadline' => 3000], 3000));
		$this->assertNull($window->refusal(['opensAt' => null, 'deadline' => null], 5));
	}
}
