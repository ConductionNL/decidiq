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
use DateTimeImmutable;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentRelease;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentSuccession;
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
	 * The succession the job runs first.
	 *
	 * @var GoverningDocumentSuccession&MockObject
	 */
	private GoverningDocumentSuccession&MockObject $succession;

	/**
	 * The clock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->release = $this->createMock(GoverningDocumentRelease::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->succession = $this->createMock(originalClassName: GoverningDocumentSuccession::class);
		$this->succession->method('settle')->willReturn(['replaced' => 0, 'failed' => 0]);
		$this->time = $this->createMock(originalClassName: ITimeFactory::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return GoverningDocumentReleaseJob
	 */
	private function runJob(): GoverningDocumentReleaseJob {
		$job = new GoverningDocumentReleaseJob(time: $this->time, release: $this->release, logger: $this->logger, succession: $this->succession);
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

	/**
	 * The run settles the versions for the clock's day and reports what moved, even when the release fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public function testItSettlesTheVersionsForTheClocksDay(): void {
		$this->time->method('getTime')->willReturn((int)(new DateTimeImmutable('2027-03-01T02:00:00Z'))->format('U'));
		$this->succession = $this->createMock(originalClassName: GoverningDocumentSuccession::class);
		$this->succession->expects(self::once())->method('settle')
			->with(self::callback(callback: static fn (DateTimeImmutable $today): bool => $today->format('Y-m-d') === '2027-03-01'))
			->willReturn(['replaced' => 2, 'failed' => 0]);
		$this->release->method('sweep')->willThrowException(new RuntimeException('register unreadable'));
		$this->logger->expects(self::once())->method('info')->with(self::stringContains(string: 'succession finished'), ['replaced' => 2, 'failed' => 0]);
		$this->logger->expects(self::once())->method('error')->with(self::stringContains(string: 'release failed'));

		$this->runJob();
	}//end testItSettlesTheVersionsForTheClocksDay()

	/**
	 * A failing succession is logged and the release still runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public function testAFailingSuccessionDoesNotHoldUpTheRelease(): void {
		$this->succession = $this->createMock(originalClassName: GoverningDocumentSuccession::class);
		$this->succession->method('settle')->willThrowException(new RuntimeException('register unreadable'));
		$this->release->expects(self::once())->method('sweep')->willReturn(['released' => [], 'overdue' => [], 'failed' => []]);
		$this->logger->expects(self::once())->method('error')->with(self::stringContains(string: 'succession failed'));

		$this->runJob();
	}//end testAFailingSuccessionDoesNotHoldUpTheRelease()
}//end class
