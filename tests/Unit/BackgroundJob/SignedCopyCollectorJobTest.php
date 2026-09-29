<?php

/**
 * Unit tests for SignedCopyCollectorJob.
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
 * @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
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

/**
 * Tests for SignedCopyCollectorJob.
 *
 * @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SignedCopyCollectorJobTest extends TestCase {

	/**
	 * Each run collects every record that is out for signature, so the signed
	 * copy lands without anyone opening the record.
	 *
	 * @return void
	 */
	public function testEachRunCollectsTheRoundsThatAreOut(): void {
		$rounds = $this->createMock(SigningRoundService::class);
		$rounds->expects($this->once())->method('collectAllSent')->willReturn(2);

		$job = new SignedCopyCollectorJob($this->createMock(ITimeFactory::class), $rounds, $this->createMock(LoggerInterface::class));
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testEachRunCollectsTheRoundsThatAreOut()

	/**
	 * A failing collection is logged, not thrown into cron.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$rounds = $this->createMock(SigningRoundService::class);
		$rounds->method('collectAllSent')->willThrowException(new \RuntimeException('OR down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new SignedCopyCollectorJob($this->createMock(ITimeFactory::class), $rounds, $logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testAFailureIsLoggedNotThrown()

	/**
	 * The job is registered in appinfo/info.xml, or Nextcloud never schedules it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		// String parse, not simplexml_load_file(): see ConnectionReportJobTest.
		$infoXml = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		$this->assertNotFalse(condition: $infoXml);

		$jobs = array_map('strval', $infoXml->xpath('/info/background-jobs/job'));
		$this->assertContains(needle: SignedCopyCollectorJob::class, haystack: $jobs);
	}//end testTheJobIsRegisteredInInfoXml()
}//end class
