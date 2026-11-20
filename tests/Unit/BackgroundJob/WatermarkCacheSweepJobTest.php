<?php

/**
 * WatermarkCacheSweepJob Unit Tests
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Decidiq\BackgroundJob\WatermarkCacheSweepJob;
use OCA\Decidiq\Service\WatermarkCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The sweep keeps the instance's today, not UTC's.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\BackgroundJob\WatermarkCacheSweepJob
 */
class WatermarkCacheSweepJobTest extends TestCase {

	/**
	 * At 23:30 UTC on 2 October it is already 3 October in Amsterdam.
	 *
	 * @return void
	 */
	public function testItSweepsEverythingButTheLocalToday(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-02 23:30:00', new DateTimeZone('UTC')));
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$cache = $this->createMock(WatermarkCache::class);
		$cache->expects($this->once())->method('sweep')->with('2026-10-03')->willReturn(2);

		$job = new WatermarkCacheSweepJob($time, $cache, $zone);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testItSweepsEverythingButTheLocalToday()
}//end class
