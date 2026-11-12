<?php

/**
 * Unit tests for VotingDeadlineReminderJob.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
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

/**
 * Tests the hourly interval and the delegation to the reminder service.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingDeadlineReminderJobTest extends TestCase {

	/**
	 * The job runs hourly and delegates the sweep with the factory time.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testHourlyIntervalAndDelegation(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1781265600);

		$service = $this->createMock(VotingDeadlineReminderService::class);
		$service->expects(self::once())
			->method('run')
			->with(self::equalTo(1781265600))
			->willReturn(2);

		$job = new VotingDeadlineReminderJob(
			time: $time,
			reminderService: $service,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame(expected: 3600, actual: $job->getInterval());

		$reflection = new \ReflectionMethod($job, 'run');
		$reflection->invoke($job, null);

	}//end testHourlyIntervalAndDelegation()

	/**
	 * Service failures never escape the job (fail soft).
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testServiceFailureIsSwallowed(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1781265600);

		$service = $this->createMock(VotingDeadlineReminderService::class);
		$service->method('run')->willThrowException(new \RuntimeException('register down'));

		$job = new VotingDeadlineReminderJob(
			time: $time,
			reminderService: $service,
			logger: $this->createMock(LoggerInterface::class),
		);

		$reflection = new \ReflectionMethod($job, 'run');
		$reflection->invoke($job, null);

		// Reaching this point without an exception is the assertion.
		self::assertTrue(condition: true);

	}//end testServiceFailureIsSwallowed()
}//end class
