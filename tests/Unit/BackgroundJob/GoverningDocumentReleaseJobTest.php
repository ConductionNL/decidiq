<?php

/**
 * Tests for GoverningDocumentReleaseJob.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\GoverningDocumentReleaseJob;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentRelease;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The daily job runs the release and reports what is overdue.
 *
 * @covers \OCA\Decidiq\BackgroundJob\GoverningDocumentReleaseJob
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */
class GoverningDocumentReleaseJobTest extends TestCase {

	private GoverningDocumentRelease&MockObject $release;
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->release = $this->createMock(GoverningDocumentRelease::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return GoverningDocumentReleaseJob
	 */
	private function runJob(): GoverningDocumentReleaseJob {
		$job = new GoverningDocumentReleaseJob(time: $this->createMock(ITimeFactory::class), release: $this->release, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * Once a day; overdue documents are a warning naming them, releases an info line.
	 *
	 * @return void
	 */
	public function testItRunsDailyAndReportsTheOverdue(): void {
		$this->release->expects(self::once())->method('sweep')->willReturn(['released' => ['a'], 'overdue' => ['b', 'c'], 'failed' => []]);
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('without being approved'), ['documents' => ['b', 'c']]);
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('release finished'), ['released' => 1, 'failed' => 0]);

		$job = $this->runJob();

		self::assertSame(86400, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testItRunsDailyAndReportsTheOverdue()

	/**
	 * A quiet day logs nothing.
	 *
	 * @return void
	 */
	public function testAQuietDayLogsNothing(): void {
		$this->release->method('sweep')->willReturn(['released' => [], 'overdue' => [], 'failed' => []]);
		$this->logger->expects(self::never())->method('warning');
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::never())->method('error');

		$this->runJob();
	}//end testAQuietDayLogsNothing()

	/**
	 * A failing release is logged, never thrown into cron.
	 *
	 * @return void
	 */
	public function testAFailingReleaseIsLogged(): void {
		$this->release->method('sweep')->willThrowException(new RuntimeException('down'));
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('release failed'), ['reason' => 'down']);

		$this->runJob();
	}//end testAFailingReleaseIsLogged()
}//end class
