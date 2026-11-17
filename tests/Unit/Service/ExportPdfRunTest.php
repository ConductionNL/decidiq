<?php

/**
 * Tests for ExportPdfRun.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ExportBundleService;
use OCA\Decidiq\Service\ExportPdfRun;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The inputs, pages, options and ready notice of one merged-PDF export.
 *
 * @covers \OCA\Decidiq\Service\ExportPdfRun
 */
class ExportPdfRunTest extends TestCase {

	/**
	 * The work folder.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $work;

	/**
	 * Build the work folder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->work = $this->createMock(Folder::class);
	}//end setUp()

	/**
	 * A file mock with an id, name and size.
	 *
	 * @param int    $id   The file id
	 * @param string $name The file name
	 * @param int    $size The size in bytes
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, string $name='page.pdf', int $size=0): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		return $file;
	}//end file()

	/**
	 * Pages are stored in the work folder under numbered names and join the
	 * inputs in order, interleaved with attachments.
	 *
	 * @return void
	 */
	public function testPagesAndAttachmentsKeepListOrder(): void {
		$prefix = md5('motions.pdf');
		$this->work->method('getNonExistingName')->willReturnCallback(fn (string $name) => 'free-'.$name);
		$this->work->expects(self::exactly(2))
			->method('newFile')
			->willReturnCallback(function (string $name, string $bytes) use ($prefix) {
				return match ($name) {
					'free-'.$prefix.'-001.pdf' => $this->file(id: 11),
					'free-'.$prefix.'-002.pdf' => $this->file(id: 12),
				};
			});

		$run = new ExportPdfRun(work: $this->work, export: ['name' => 'motions.pdf', 'uid' => 'alice']);
		$run->addPage(bytes: 'PDF-ONE', title: 'Motion A');
		$run->addAttachment(file: $this->file(id: 99, name: 'annex.pdf', size: 2048));
		$run->addPage(bytes: 'PDF-2', title: 'Motion B');

		self::assertSame(
			[
				['fileId' => 11, 'label' => 'Motion A', 'size' => 7],
				['fileId' => 99, 'label' => 'annex.pdf', 'size' => 2048],
				['fileId' => 12, 'label' => 'Motion B', 'size' => 5],
			],
			$run->inputs()
		);
		self::assertSame([11, 12], $run->pages(), 'Attachments are not work pages.');
	}//end testPagesAndAttachmentsKeepListOrder()

	/**
	 * The merge options and metadata name the export file.
	 *
	 * @return void
	 */
	public function testOptionsAndMetaNameTheExport(): void {
		$run = new ExportPdfRun(work: $this->work, export: ['name' => 'motions.pdf', 'uid' => 'alice']);

		self::assertSame(
			['bookmarks' => true, 'targetFolder' => ExportBundleService::FOLDER, 'name' => 'motions.pdf'],
			$run->options()
		);
		self::assertSame(['app' => 'decidiq', 'export' => 'motions.pdf'], $run->meta());
		self::assertSame([], $run->inputs());
		self::assertSame([], $run->pages());
	}//end testOptionsAndMetaNameTheExport()

	/**
	 * The ready notice carries the queued job's uuid, falling back to its id.
	 *
	 * @return void
	 */
	public function testTheNoticeReadsTheQueuedJob(): void {
		$this->work->method('getNonExistingName')->willReturnArgument(0);
		$this->work->method('newFile')->willReturn($this->file(id: 7));
		$run = new ExportPdfRun(work: $this->work, export: ['name' => 'motions.pdf', 'uid' => 'bob']);
		$run->addPage(bytes: 'x', title: 'T');

		self::assertSame(
			['mergeJob' => 'u-1', 'uid' => 'bob', 'name' => 'motions.pdf', 'pages' => [7], 'attempt' => 0],
			$run->notice(job: ['uuid' => 'u-1', 'id' => 5])
		);
		self::assertSame('5', $run->notice(job: ['id' => 5])['mergeJob']);
		self::assertSame('', $run->notice(job: [])['mergeJob']);
	}//end testTheNoticeReadsTheQueuedJob()
}//end class
