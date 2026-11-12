<?php

/**
 * SignedCopyCollectorJob unit tests.
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

use OCA\Decidiq\BackgroundJob\SignedCopyCollectorJob;
use OCA\Decidiq\Service\SigningRoundService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Every quarter hour the job collects signed copies and logs only what it stored.
 *
 * @covers \OCA\Decidiq\BackgroundJob\SignedCopyCollectorJob
 */
class SignedCopyCollectorJobTest extends TestCase {

	/**
	 * Run the job once.
	 *
	 * @param SigningRoundService $rounds The signing rounds
	 * @param LoggerInterface     $logger The logger
	 *
	 * @return SignedCopyCollectorJob The job
	 */
	private function runJob(SigningRoundService $rounds, LoggerInterface $logger): SignedCopyCollectorJob {
		$job = new SignedCopyCollectorJob(time: $this->createMock(ITimeFactory::class), rounds: $rounds, logger: $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * Stored copies are logged, on a 15-minute interval.
	 *
	 * @return void
	 */
	public function testStoredCopiesAreLogged(): void {
		$rounds = $this->createMock(SigningRoundService::class);
		$rounds->expects(self::once())->method('collectAllSent')->willReturn(2);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq: signed copies stored', ['stored' => 2]);

		$job = $this->runJob(rounds: $rounds, logger: $logger);

		self::assertSame(900, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testStoredCopiesAreLogged()

	/**
	 * Nothing stored logs nothing, and a failure is logged with its reason.
	 *
	 * @return void
	 */
	public function testNothingStoredAndAFailure(): void {
		$idle = $this->createMock(SigningRoundService::class);
		$idle->method('collectAllSent')->willReturn(0);
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects(self::never())->method('info');
		$this->runJob(rounds: $idle, logger: $quiet);

		$failing = $this->createMock(SigningRoundService::class);
		$failing->method('collectAllSent')->willThrowException(new RuntimeException('signing service down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Decidiq: collecting signed copies failed', ['reason' => 'signing service down']);
		$logger->expects(self::never())->method('info');
		$this->runJob(rounds: $failing, logger: $logger);
	}//end testNothingStoredAndAFailure()
}//end class
