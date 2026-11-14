<?php

/**
 * Tests for ConfidentialityLiftByJob: daily, it sweeps for today and a failed
 * sweep is logged, not thrown (REQ-EMB-022).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\ConfidentialityLiftByJob;
use OCA\Decidiq\Service\ConfidentialityLiftByService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The job around a stand-in sweep.
 *
 * @covers \OCA\Decidiq\BackgroundJob\ConfidentialityLiftByJob
 */
class ConfidentialityLiftByJobTest extends TestCase {
	/**
	 * The job sweeps for the clock's day and logs what it placed.
	 *
	 * @return void
	 */
	public function testItSweepsForTodayAndLogsWhatChanged(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)(new DateTimeImmutable('2027-06-01T03:00:00Z'))->format('U'));
		$sweep = $this->createMock(ConfidentialityLiftByService::class);
		$sweep->expects(self::once())->method('sweep')
			->with(self::callback(static fn (DateTimeImmutable $day): bool => $day->format('Y-m-d') === '2027-06-01'))
			->willReturn(['placed' => 1, 'overdue' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq: confidentiality lifting sweep finished', ['placed' => 1, 'overdue' => 0]);

		$job = new ConfidentialityLiftByJob($time, $sweep, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testItSweepsForTodayAndLogsWhatChanged()

	/**
	 * A failed sweep is logged and does not throw; a quiet sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAFailedSweepIsLogged(): void {
		$time  = $this->createMock(ITimeFactory::class);
		$sweep = $this->createMock(ConfidentialityLiftByService::class);
		$sweep->method('sweep')->willThrowException(new \RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Decidiq: the confidentiality lifting sweep failed', ['reason' => 'register down']);
		$logger->expects(self::never())->method('info');

		$job = new ConfidentialityLiftByJob($time, $sweep, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testAFailedSweepIsLogged()
}//end class
