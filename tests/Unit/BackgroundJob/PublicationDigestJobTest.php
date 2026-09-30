<?php

/**
 * Tests for PublicationDigestJob: the job runs the digest with the clock's time and never throws.
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
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\PublicationDigestJob;
use OCA\Decidiq\Service\PublicationDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\BackgroundJob\PublicationDigestJob
 */
final class PublicationDigestJobTest extends TestCase {

	/**
	 * The job hands the digest the clock's time.
	 *
	 * @return void
	 */
	public function testTheJobRunsTheDigestAtTheClocksTime(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1791954300);

		$digest = $this->createMock(PublicationDigestService::class);
		$digest->expects($this->once())->method('run')->with(1791954300)->willReturn(2);

		$job = new PublicationDigestJob($time, $digest, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testTheJobRunsTheDigestAtTheClocksTime()

	/**
	 * A failing run is logged, not thrown into cron.
	 *
	 * @return void
	 */
	public function testAFailingRunIsLogged(): void {
		$digest = $this->createMock(PublicationDigestService::class);
		$digest->method('run')->willThrowException(new RuntimeException('register down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new PublicationDigestJob($this->createMock(ITimeFactory::class), $digest, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testAFailingRunIsLogged()

	/**
	 * The job is declared in info.xml, so Nextcloud schedules it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertStringContainsString('<job>OCA\Decidiq\BackgroundJob\PublicationDigestJob</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
