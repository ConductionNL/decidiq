<?php

/**
 * PaperStamper Unit Tests
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Service\PaperStamper;
use OCA\Decidiq\Service\PaperWatermarkPolicy;
use OCA\Decidiq\Service\WatermarkCache;
use OCA\Decidiq\Support\FilinqStamp;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reader's name, the local moment and the meeting's text, once a day.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\Service\PaperStamper
 *
 * @uses \OCA\Decidiq\Service\PaperWatermarkPolicy
 * @uses \OCA\Decidiq\Exception\PaperStampRefusedException
 */
class PaperStamperTest extends TestCase {

	private const MEETING = ['watermarkPapers' => 'on', 'watermarkText' => 'Vertrouwelijk'];

	private const PAPER = ['fileId' => 42, 'etag' => 'e1', 'content' => '%PDF'];

	private const READER = ['uid' => 'aisha', 'displayName' => 'Aisha Bakker'];

	/**
	 * Build the stamper at 18:15 UTC on 3 October, 20:15 in Amsterdam.
	 *
	 * @param FilinqStamp    $stamp The stamp double.
	 * @param WatermarkCache $cache The cache double.
	 *
	 * @return PaperStamper
	 */
	private function stamper(FilinqStamp $stamp, WatermarkCache $cache): PaperStamper {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-03 18:15:00', new DateTimeZone('UTC')));
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$policy = new PaperWatermarkPolicy($this->createMock(ObjectServiceInterface::class), $this->createMock(LoggerInterface::class));

		return new PaperStamper($policy, $stamp, $cache, $time, $zone);
	}//end stamper()

	/**
	 * A first opening stamps the reader's line and keeps the copy for the day.
	 *
	 * @return void
	 */
	public function testAFirstOpeningStampsAndKeepsTheCopy(): void {
		$stamp = $this->createMock(FilinqStamp::class);
		$stamp->expects($this->once())->method('stamp')
			->with('%PDF', 'Aisha Bakker, 2026-10-03 20:15, Vertrouwelijk')
			->willReturn('%PDF stamped');
		$cache = $this->createMock(WatermarkCache::class);
		$cache->method('name')->with(42, 'e1', 'aisha', '2026-10-03')->willReturn('copy.pdf');
		$cache->method('get')->with('copy.pdf')->willReturn(null);
		$cache->expects($this->once())->method('put')->with('copy.pdf', '%PDF stamped');

		self::assertSame('%PDF stamped', $this->stamper($stamp, $cache)->stamped(self::MEETING, self::PAPER, self::READER));
	}//end testAFirstOpeningStampsAndKeepsTheCopy()

	/**
	 * A second opening the same day is served from the cache, without a stamp.
	 *
	 * @return void
	 */
	public function testASecondOpeningIsServedFromTheCache(): void {
		$stamp = $this->createMock(FilinqStamp::class);
		$stamp->expects($this->never())->method('stamp');
		$cache = $this->createMock(WatermarkCache::class);
		$cache->method('name')->willReturn('copy.pdf');
		$cache->method('get')->willReturn('%PDF cached');
		$cache->expects($this->never())->method('put');

		self::assertSame('%PDF cached', $this->stamper($stamp, $cache)->stamped(self::MEETING, self::PAPER, self::READER));
	}//end testASecondOpeningIsServedFromTheCache()

	/**
	 * Without filinq the paper is refused with 503 and nothing is cached.
	 *
	 * @return void
	 */
	public function testWithoutAStampingServiceItRefusesWith503(): void {
		$stamp = $this->createMock(FilinqStamp::class);
		$stamp->method('stamp')->willReturn(null);
		$cache = $this->createMock(WatermarkCache::class);
		$cache->method('name')->willReturn('copy.pdf');
		$cache->method('get')->willReturn(null);
		$cache->expects($this->never())->method('put');

		try {
			$this->stamper($stamp, $cache)->stamped(self::MEETING, self::PAPER, self::READER);
			self::fail('Without a stamping service the paper must be refused.');
		} catch (PaperStampRefusedException $e) {
			self::assertSame(503, $e->getStatus());
			self::assertSame('This paper can only be shown with a watermark, and the watermark service is not installed', $e->getMessage());
		}
	}//end testWithoutAStampingServiceItRefusesWith503()

	/**
	 * The policy decides: off is never stamped, on always.
	 *
	 * @return void
	 */
	public function testThePolicyDecides(): void {
		$stamper = $this->stamper($this->createMock(FilinqStamp::class), $this->createMock(WatermarkCache::class));

		self::assertTrue($stamper->mustStamp(['watermarkPapers' => 'on', 'isPublic' => true]));
		self::assertFalse($stamper->mustStamp(['watermarkPapers' => 'off', 'isPublic' => false], 'item-1'));
		self::assertTrue($stamper->mustStamp([]));
	}//end testThePolicyDecides()
}//end class
