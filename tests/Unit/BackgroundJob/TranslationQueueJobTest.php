<?php

/**
 * Unit tests for TranslationQueueJob.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\TranslationQueueJob;
use OCA\Decidiq\Service\MultilingualReconciliationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TranslationQueueJob.
 *
 * Uses a subclass to expose the protected run() method.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class TranslationQueueJobTest extends TestCase {

	/**
	 * run() delegates to MultilingualReconciliationService::processQueue.
	 *
	 * @return void
	 */
	public function testRunDelegatesToService(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$service = $this->createMock(MultilingualReconciliationService::class);
		$service->expects($this->once())
			->method('processQueue')
			->with(20)
			->willReturn([
				'success' => true,
				'processed' => 3,
				'completed' => 2,
				'failed' => 1,
				'message' => 'Processed 3 entries.',
			]);

		$job = new class($time, $service, $this->createMock(LoggerInterface::class)) extends TranslationQueueJob {
			/**
			 * Expose the protected run().
			 *
			 * @return void
			 */
			public function runForTest(): void {
				$this->run(null);
			}
		};

		$job->runForTest();

	}//end testRunDelegatesToService()

	/**
	 * run() swallows exceptions thrown by the service so cron does not die.
	 *
	 * @return void
	 */
	public function testRunSwallowsExceptions(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$service = $this->createMock(MultilingualReconciliationService::class);
		$service->method('processQueue')->willThrowException(new \RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('error');

		$job = new class($time, $service, $logger) extends TranslationQueueJob {
			/**
			 * Expose the protected run().
			 *
			 * @return void
			 */
			public function runForTest(): void {
				$this->run(null);
			}
		};

		$job->runForTest();

	}//end testRunSwallowsExceptions()

}//end class
