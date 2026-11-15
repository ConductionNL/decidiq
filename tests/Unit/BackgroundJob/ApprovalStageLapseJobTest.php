<?php

/**
 * ApprovalStageLapseJob unit tests.
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

use OCA\Decidiq\BackgroundJob\ApprovalStageLapseJob;
use OCA\Decidiq\Service\ApprovalStageLapseService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The hourly sweep reports only what it did, and a failure is logged, not thrown.
 *
 * @covers \OCA\Decidiq\BackgroundJob\ApprovalStageLapseJob
 */
class ApprovalStageLapseJobTest extends TestCase {

	/**
	 * The lapse service.
	 *
	 * @var ApprovalStageLapseService|MockObject
	 */
	private ApprovalStageLapseService $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(ApprovalStageLapseService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return ApprovalStageLapseJob The job
	 */
	private function runJob(): ApprovalStageLapseJob {
		$job = new ApprovalStageLapseJob(time: $this->createMock(ITimeFactory::class), lapseService: $this->service, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * A sweep that changed something is logged with its counts, on an hourly interval.
	 *
	 * @return void
	 */
	public function testASweepThatChangedSomethingIsLogged(): void {
		$this->service->expects(self::once())->method('sweep')->willReturn(['lapsed' => 2, 'substitutesAsked' => 1]);
		$this->logger->expects(self::once())->method('info')->with('Decidiq: approval stage lapse sweep finished', ['lapsed' => 2, 'substitutesAsked' => 1]);

		$job = $this->runJob();

		self::assertSame(3600, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testASweepThatChangedSomethingIsLogged()

	/**
	 * An idle sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAnIdleSweepLogsNothing(): void {
		$this->service->method('sweep')->willReturn(['lapsed' => 0, 'substitutesAsked' => 0]);
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::never())->method('error');

		$this->runJob();
	}//end testAnIdleSweepLogsNothing()

	/**
	 * A failed sweep is logged with its reason.
	 *
	 * @return void
	 */
	public function testAFailedSweepIsLogged(): void {
		$this->service->method('sweep')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::once())->method('error')->with('Decidiq: the approval stage lapse sweep failed', ['reason' => 'register down']);
		$this->logger->expects(self::never())->method('info');

		$this->runJob();
	}//end testAFailedSweepIsLogged()
}//end class
