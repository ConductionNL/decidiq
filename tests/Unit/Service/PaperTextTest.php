<?php

/**
 * A paper is summarised only when it is attached to the agenda item, and its
 * text comes from OpenRegister's extraction in parts no longer than one task's
 * input (agenda-ai-paper-summaries task 2).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Service\PaperText;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Service\PaperText
 * @uses   \OCA\Decidiq\Exception\PaperSummaryRefusedException
 */
class PaperTextTest extends TestCase {

	private const ITEM = 'begroting-2026-bespreking';

	/**
	 * The file ids extractFile() was asked for.
	 *
	 * @var array<int, int>
	 */
	public array $extracted = [];

	/**
	 * A Nextcloud file node.
	 *
	 * @param int    $id   The file id
	 * @param string $name The file name
	 *
	 * @return File The node.
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);

		return $file;
	}//end file()

	/**
	 * Chunks of a paper, in order.
	 *
	 * @param array<int, string> $texts The chunk texts
	 *
	 * @return array<int, Chunk> The chunks.
	 */
	private function chunks(array $texts): array {
		$chunks = [];
		foreach ($texts as $index => $text) {
			$chunk = new Chunk();
			$chunk->setChunkIndex($index);
			$chunk->setTextContent($text);
			$chunks[] = $chunk;
		}

		return $chunks;
	}//end chunks()

	/**
	 * The reader over an item with these papers, whose text is these chunks.
	 *
	 * @param array<int, File>   $files  The files attached to the item
	 * @param array<int, string> $texts  The chunk texts of every paper
	 *
	 * @return PaperText The reader.
	 */
	private function reader(array $files, array $texts=[]): PaperText {
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			static fn (mixed $object): array => ($object === self::ITEM ? $files : [])
		);
		$extraction = $this->createMock(TextExtractionService::class);
		$extraction->method('extractFile')->willReturnCallback(
			function (int $fileId): void {
				$this->extracted[] = $fileId;
			}
		);
		$chunks = $this->chunks($texts);
		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('findBySource')->willReturnCallback(
			static fn (string $type, int $id): array => ($type === 'file' && $id === 900412 ? $chunks : [])
		);
		$services = [
			'OCA\OpenRegister\Service\FileService' => $fileService,
			'OCA\OpenRegister\Service\TextExtractionService' => $extraction,
			'OCA\OpenRegister\Db\ChunkMapper' => $chunkMapper,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new PaperText(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * An attached paper answers with its file name.
	 *
	 * @return void
	 */
	public function testAnAttachedPaperAnswersWithItsName(): void {
		$reader = $this->reader([$this->file(910001, 'Kadernota 2026.docx'), $this->file(900412, 'Raadsvoorstel kadernota 2026.pdf')]);

		$this->assertSame('Raadsvoorstel kadernota 2026.pdf', $reader->titleOf(agendaItemId: self::ITEM, fileId: 900412));
	}//end testAnAttachedPaperAnswersWithItsName()

	/**
	 * A file that is not attached to the item is refused with 422.
	 *
	 * @return void
	 */
	public function testAFileNotAttachedToTheItemIsRefused(): void {
		$reader = $this->reader([$this->file(910001, 'Kadernota 2026.docx')]);

		try {
			$reader->titleOf(agendaItemId: self::ITEM, fileId: 900412);
			$this->fail('A file not attached to the item must be refused.');
		} catch (PaperSummaryRefusedException $e) {
			$this->assertSame(422, $e->getStatus());
		}
	}//end testAFileNotAttachedToTheItemIsRefused()

	/**
	 * A short paper is one part, read from the chunks after extraction.
	 *
	 * @return void
	 */
	public function testAShortPaperIsOnePart(): void {
		$reader = $this->reader([], ['De kadernota gaat uit van een sluitende begroting.', 'Nieuw beleid is beperkt.']);

		$parts = $reader->parts(fileId: 900412);

		$this->assertSame([900412], $this->extracted);
		$this->assertCount(1, $parts);
		$this->assertStringContainsString('sluitende begroting', $parts[0]);
		$this->assertStringContainsString('Nieuw beleid', $parts[0]);
	}//end testAShortPaperIsOnePart()

	/**
	 * A paper longer than one task's input is cut on chunk boundaries into
	 * parts that each fit.
	 *
	 * @return void
	 */
	public function testALongPaperIsCutIntoPartsThatFit(): void {
		$chunk = trim(str_repeat('Begrotingstekst. ', (int)(PaperText::PART_LENGTH / 34)));
		$reader = $this->reader([], array_fill(0, 5, $chunk));

		$parts = $reader->parts(fileId: 900412);

		$this->assertGreaterThan(1, count($parts));
		foreach ($parts as $part) {
			$this->assertLessThanOrEqual(PaperText::PART_LENGTH, mb_strlen($part));
		}

		$this->assertSame(5 * mb_strlen($chunk), mb_strlen(str_replace("\n\n", '', implode('', $parts))));
	}//end testALongPaperIsCutIntoPartsThatFit()

	/**
	 * A paper without readable text is refused with 422.
	 *
	 * @return void
	 */
	public function testAPaperWithoutTextIsRefused(): void {
		$reader = $this->reader([], []);

		$this->expectException(PaperSummaryRefusedException::class);
		$this->expectExceptionMessage('No text could be read from this paper.');
		$reader->parts(fileId: 900412);
	}//end testAPaperWithoutTextIsRefused()

	/**
	 * When OpenRegister cannot list the item's files or read the paper, the
	 * request is refused with 422 rather than failing.
	 *
	 * @return void
	 */
	public function testUnreadableFilesOrTextAreRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not installed'));
		$reader = new PaperText(container: $container, logger: $this->createMock(LoggerInterface::class));

		foreach ([fn () => $reader->titleOf(agendaItemId: self::ITEM, fileId: 900412), fn () => $reader->parts(fileId: 900412)] as $call) {
			try {
				$call();
				$this->fail('An unreadable paper must be refused.');
			} catch (PaperSummaryRefusedException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}
	}//end testUnreadableFilesOrTextAreRefused()
}//end class
