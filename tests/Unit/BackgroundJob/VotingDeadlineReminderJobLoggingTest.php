<?php

/**
 * VotingDeadlineReminderJob logging unit tests.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\VotingDeadlineReminderJob;
use OCA\Decidiq\Service\VotingDeadlineReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The hourly job sends what is due now, logs only when it sent something, and never throws.
 *
 * @covers \OCA\Decidiq\BackgroundJob\VotingDeadlineReminderJob
 */
class VotingDeadlineReminderJobLoggingTest extends TestCase {

	/**
	 * Run the job once with the clock at a moment.
	 *
	 * @param VotingDeadlineReminderService $service The service
	 * @param LoggerInterface $logger  The logger
	 *
	 * @return VotingDeadlineReminderJob The job
	 */
	private function runJob(VotingDeadlineReminderService $service, LoggerInterface $logger): VotingDeadlineReminderJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		$job = new VotingDeadlineReminderJob(time: $time, reminderService: $service, logger: $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * What was sent is logged, and the service is asked with the clock's time, hourly.
	 *
	 * @return void
	 */
	public function testWhatWasSentIsLogged(): void {
		$service = $this->createMock(VotingDeadlineReminderService::class);
		$service->expects(self::once())->method('run')->with(1790000000)->willReturn(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq: voting deadline reminder job sent notifications', ['sent' => 4]);

		$job = $this->runJob(service: $service, logger: $logger);

		self::assertSame(3600, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testWhatWasSentIsLogged()

	/**
	 * Nothing sent logs nothing.
	 *
	 * @return void
	 */
	public function testNothingSentLogsNothing(): void {
		$service = $this->createMock(VotingDeadlineReminderService::class);
		$service->method('run')->willReturn(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');
		$logger->expects(self::never())->method('error');

		$this->runJob(service: $service, logger: $logger);
	}//end testNothingSentLogsNothing()

	/**
	 * A failed run is logged with its message.
	 *
	 * @return void
	 */
	public function testAFailedRunIsLogged(): void {
		$service = $this->createMock(VotingDeadlineReminderService::class);
		$service->method('run')->willThrowException(new RuntimeException('mail down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Decidiq: voting deadline reminder job failed', ['exception' => 'mail down']);

		$this->runJob(service: $service, logger: $logger);
	}//end testAFailedRunIsLogged()
}//end class
