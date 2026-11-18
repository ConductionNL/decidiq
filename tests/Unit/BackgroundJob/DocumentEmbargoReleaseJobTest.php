<?php

/**
 * Tests for DocumentEmbargoReleaseJob: every fifteen minutes it releases for
 * the clock's moment, and a failed sweep is logged, not thrown (REQ-EMB-005).
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\DocumentEmbargoReleaseJob;
use OCA\Decidiq\BackgroundJob\DocumentEmbargoSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The job around a stand-in sweep.
 *
 * @covers \OCA\Decidiq\BackgroundJob\DocumentEmbargoReleaseJob
 */
class DocumentEmbargoReleaseJobTest extends TestCase {

	/**
	 * The job runs every fifteen minutes, the documented release granularity.
	 *
	 * @return void
	 */
	public function testItRunsEveryFifteenMinutes(): void {
		$job = new DocumentEmbargoReleaseJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(DocumentEmbargoSweep::class),
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(900, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testItRunsEveryFifteenMinutes()

	/**
	 * The job releases for the clock's moment and logs what it released.
	 *
	 * @return void
	 */
	public function testItReleasesForTheClocksMomentAndLogsIt(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)(new DateTimeImmutable('2027-03-01T08:15:00Z'))->format('U'));
		$sweep = $this->createMock(DocumentEmbargoSweep::class);
		$sweep->expects(self::once())->method('sweep')
			->with(self::callback(static fn (DateTimeImmutable $now): bool => $now->format('c') === '2027-03-01T08:15:00+00:00'))
			->willReturn(['released' => 2, 'failed' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq: document embargo release finished', ['released' => 2, 'failed' => 0]);

		$job = new DocumentEmbargoReleaseJob($time, $sweep, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testItReleasesForTheClocksMomentAndLogsIt()

	/**
	 * A failed sweep is logged and does not throw; a quiet sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAFailedSweepIsLoggedAndAQuietOneIsNot(): void {
		$time  = $this->createMock(ITimeFactory::class);
		$sweep = $this->createMock(DocumentEmbargoSweep::class);
		$sweep->method('sweep')->willThrowException(new \RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Decidiq: the document embargo release failed', ['reason' => 'register down']);
		$logger->expects(self::never())->method('info');

		$job = new DocumentEmbargoReleaseJob($time, $sweep, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$quiet = $this->createMock(DocumentEmbargoSweep::class);
		$quiet->method('sweep')->willReturn(['released' => 0, 'failed' => 0]);
		$silent = $this->createMock(LoggerInterface::class);
		$silent->expects(self::never())->method('info');
		$silent->expects(self::never())->method('error');
		$job = new DocumentEmbargoReleaseJob($time, $quiet, $silent);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testAFailedSweepIsLoggedAndAQuietOneIsNot()
}//end class
