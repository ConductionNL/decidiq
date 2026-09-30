<?php

/**
 * Tests: an Office paper is converted through filinq and the outcome recorded on its object.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\Filinq\Exception\ConversionFailedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob
 * @uses   \OCA\Decidiq\Support\FleetAppId
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */
class ConvertPaperToPdfJobTest extends TestCase {

	/**
	 * The agenda item Begroting 2027.
	 *
	 * @var string
	 */
	private const ITEM = '5b1f3c2a-8d4e-4f6a-9b7c-0e1d2f3a4b5c';

	/**
	 * Objects handed to saveObject().
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Files handed to the converter.
	 *
	 * @var \ArrayObject<int, File>
	 */
	private \ArrayObject $converted;

	/**
	 * Fresh recorders per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->converted = new \ArrayObject();
	}//end setUp()

	/**
	 * Log lines at info level.
	 *
	 * @var list<string>
	 */
	private array $info = [];

	/**
	 * The Word paper, in a folder that may hold a PDF sibling.
	 *
	 * @param int|null $pdfMTime The sibling PDF's mtime, or null for none.
	 *
	 * @return File
	 */
	private function paper(?int $pdfMTime=null): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn($pdfMTime !== null);
		if ($pdfMTime !== null) {
			$pdf = $this->createMock(File::class);
			$pdf->method('getMTime')->willReturn($pdfMTime);
			$parent->method('get')->with('Programmabegroting 2027.pdf')->willReturn($pdf);
		}

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Programmabegroting 2027.docx');
		$file->method('getMTime')->willReturn(1000);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end paper()

	/**
	 * The job with the given paper and converter behaviour.
	 *
	 * @param File                 $paper     The paper at file id 4711.
	 * @param \Throwable|bool|null $converter True converts, a throwable fails, null means no filinq.
	 * @param array<string, mixed> $item      The agenda item as stored.
	 *
	 * @return ConvertPaperToPdfJob
	 */
	private function job(File $paper, \Throwable|bool|null $converter, array $item=['id' => self::ITEM, 'title' => 'Begroting 2027']): ConvertPaperToPdfJob {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => ($id === 4711) ? $paper : null);

		$filinq = new class($this->converted, $converter) {
			/**
			 * @param \ArrayObject<int, File> $converted Files converted.
			 * @param \Throwable|bool|null    $outcome   What the conversion does.
			 */
			public function __construct(private \ArrayObject $converted, private \Throwable|bool|null $outcome) {
			}

			/**
			 * The shape of filinq's PdfConversionService::convertToPdfReporting().
			 *
			 * @param File $source The source.
			 *
			 * @return array{file: File, backend: string}
			 */
			public function convertToPdfReporting(File $source): array {
				$this->converted[] = $source;
				if ($this->outcome instanceof \Throwable) {
					throw $this->outcome;
				}

				return ['file' => new class {
					/**
					 * @return int
					 */
					public function getId(): int {
						return 4712;
					}
				}, 'backend' => 'office'];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($converter, $filinq): object {
				if ($converter !== null && $id === 'OCA\\Filinq\\Service\\PdfConversionService') {
					return $filinq;
				}

				throw new class('not registered') extends \RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($item);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntity {
				$this->saved[] = $object;
				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->info[] = (string)$message;
			}
		);

		return new ConvertPaperToPdfJob($this->createMock(ITimeFactory::class), $container, $root, $objectService, $logger);
	}//end job()

	/**
	 * filinq converts: the item records the paper, its PDF and the backend.
	 *
	 * @return void
	 */
	public function testAConvertedPaperIsRecordedWithItsPdf(): void {
		$this->job($this->paper(), true)->convert(4711, self::ITEM, 'agenda-item');

		$this->assertCount(1, $this->saved);
		$rendition = $this->saved[0]['paperRenditions'][0];
		$this->assertSame(4711, $rendition['sourceFileId']);
		$this->assertSame('Programmabegroting 2027.docx', $rendition['sourceName']);
		$this->assertSame(4712, $rendition['pdfFileId']);
		$this->assertSame('office', $rendition['backend']);
		$this->assertArrayHasKey('convertedAt', $rendition);
		$this->assertArrayNotHasKey('failedAt', $rendition);
		$this->assertSame('Begroting 2027', $this->saved[0]['title']);
	}//end testAConvertedPaperIsRecordedWithItsPdf()

	/**
	 * filinq has no backend for the file: the failure is recorded, no PDF.
	 *
	 * @return void
	 */
	public function testAFailedConversionIsRecordedAndTheOriginalStays(): void {
		$this->job($this->paper(), new ConversionFailedException('all backends failed'))->convert(4711, self::ITEM, 'agenda-item');

		$rendition = $this->saved[0]['paperRenditions'][0];
		$this->assertSame(ConvertPaperToPdfJob::NO_BACKEND, $rendition['failure']);
		$this->assertArrayHasKey('failedAt', $rendition);
		$this->assertArrayNotHasKey('pdfFileId', $rendition);
	}//end testAFailedConversionIsRecordedAndTheOriginalStays()

	/**
	 * Without filinq nothing is written and one info line says why.
	 *
	 * @return void
	 */
	public function testWithoutFilinqNothingIsWritten(): void {
		$this->job($this->paper(), null)->convert(4711, self::ITEM, 'agenda-item');

		$this->assertSame([], $this->saved);
		$this->assertCount(1, $this->info);
		$this->assertStringContainsString('filinq is not installed', $this->info[0]);
	}//end testWithoutFilinqNothingIsWritten()

	/**
	 * A PDF sibling at least as new as the paper means no second conversion.
	 *
	 * @return void
	 */
	public function testANewerPdfSiblingIsNotConvertedAgain(): void {
		$this->job($this->paper(1500), true)->convert(4711, self::ITEM, 'agenda-item');

		$this->assertCount(0, $this->converted);
		$this->assertSame([], $this->saved);
	}//end testANewerPdfSiblingIsNotConvertedAgain()

	/**
	 * A second conversion of the same paper replaces its entry, others stay.
	 *
	 * @return void
	 */
	public function testAReconversionReplacesTheEntryForThatPaper(): void {
		$item = [
			'id' => self::ITEM,
			'paperRenditions' => [
				['sourceFileId' => 4711, 'sourceName' => 'Programmabegroting 2027.docx', 'failedAt' => '2026-09-29T10:00:00+00:00', 'failure' => ConvertPaperToPdfJob::NO_BACKEND],
				['sourceFileId' => 5000, 'sourceName' => 'Bijlage investeringen.xlsx', 'failedAt' => '2026-09-29T10:00:00+00:00', 'failure' => ConvertPaperToPdfJob::NO_BACKEND],
			],
		];
		$this->job($this->paper(), true, $item)->convert(4711, self::ITEM, 'agenda-item');

		$renditions = $this->saved[0]['paperRenditions'];
		$this->assertCount(2, $renditions);
		$this->assertSame(5000, $renditions[0]['sourceFileId']);
		$this->assertSame(4712, $renditions[1]['pdfFileId']);
	}//end testAReconversionReplacesTheEntryForThatPaper()
}//end class
