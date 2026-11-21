<?php

/**
 * WatermarkCache Unit Tests
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

use OCA\Decidiq\Service\WatermarkCache;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One copy per paper version, reader and day; earlier days are swept.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\Service\WatermarkCache
 */
class WatermarkCacheTest extends TestCase {

	/**
	 * The name carries the paper, its version, a hash of the reader and the day.
	 *
	 * @return void
	 */
	public function testTheNameKeysOnPaperVersionReaderAndDay(): void {
		$cache = new WatermarkCache($this->createMock(IAppData::class), $this->createMock(LoggerInterface::class));

		$name = $cache->name(42, '"abc-1"', 'aisha', '2026-10-03');

		self::assertSame('42-abc1-' . hash('sha256', 'aisha') . '-2026-10-03.pdf', $name);
		self::assertStringNotContainsString('aisha', $name);
		self::assertNotSame($name, $cache->name(42, '"abc-1"', 'jan', '2026-10-03'));
		self::assertNotSame($name, $cache->name(42, '"abc-2"', 'aisha', '2026-10-03'));
	}//end testTheNameKeysOnPaperVersionReaderAndDay()

	/**
	 * A kept copy is read back; a missing one is null.
	 *
	 * @return void
	 */
	public function testAKeptCopyIsReadBack(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('%PDF stamped');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(
			static function (string $name) use ($file): ISimpleFile {
				if ($name === 'hit.pdf') {
					return $file;
				}

				throw new NotFoundException();
			}
		);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('watermark')->willReturn($folder);

		$cache = new WatermarkCache($appData, $this->createMock(LoggerInterface::class));

		self::assertSame('%PDF stamped', $cache->get('hit.pdf'));
		self::assertNull($cache->get('miss.pdf'));
	}//end testAKeptCopyIsReadBack()

	/**
	 * Without the folder nothing is cached yet, and put() makes it.
	 *
	 * @return void
	 */
	public function testPutMakesTheFolderWhenItIsMissing(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('newFile')->with('a.pdf', '%PDF');
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		$appData->expects($this->once())->method('newFolder')->with('watermark')->willReturn($folder);

		$cache = new WatermarkCache($appData, $this->createMock(LoggerInterface::class));

		self::assertNull($cache->get('a.pdf'));
		$cache->put('a.pdf', '%PDF');
	}//end testPutMakesTheFolderWhenItIsMissing()

	/**
	 * The sweep removes yesterday's copies and keeps today's.
	 *
	 * @return void
	 */
	public function testTheSweepRemovesEarlierDays(): void {
		$today = $this->createMock(ISimpleFile::class);
		$today->method('getName')->willReturn('42-e-h-2026-10-03.pdf');
		$today->expects($this->never())->method('delete');
		$yesterday = $this->createMock(ISimpleFile::class);
		$yesterday->method('getName')->willReturn('42-e-h-2026-10-02.pdf');
		$yesterday->expects($this->once())->method('delete');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$today, $yesterday]);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		$cache = new WatermarkCache($appData, $this->createMock(LoggerInterface::class));

		self::assertSame(1, $cache->sweep('2026-10-03'));
	}//end testTheSweepRemovesEarlierDays()

	/**
	 * With no folder there is nothing to sweep.
	 *
	 * @return void
	 */
	public function testTheSweepWithoutAFolderRemovesNothing(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());

		$cache = new WatermarkCache($appData, $this->createMock(LoggerInterface::class));

		self::assertSame(0, $cache->sweep('2026-10-03'));
	}//end testTheSweepWithoutAFolderRemovesNothing()
}//end class
