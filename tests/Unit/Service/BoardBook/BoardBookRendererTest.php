<?php

/**
 * Unit tests for BoardBookRenderer.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\BoardBook;

use OCA\Decidiq\Service\BoardBook\BoardBookComposer;
use OCA\Decidiq\Service\BoardBook\BoardBookPlan;
use OCA\Decidiq\Service\BoardBook\BoardBookRenderer;
use OCA\Decidiq\Support\FilinqPdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Sections, merged attachments, placeholders and the honest fallbacks.
 */
#[CoversClass(BoardBookRenderer::class)]
#[CoversClass(BoardBookPlan::class)]
#[UsesClass(BoardBookComposer::class)]
class BoardBookRendererTest extends TestCase {

	/**
	 * A file node with a name and content.
	 *
	 * @param string $name    The name.
	 * @param string $content The content.
	 *
	 * @return object
	 */
	private static function node(string $name, string $content): object {
		return new class($name, $content) {
			/**
			 * @param string $name    The name.
			 * @param string $content The content.
			 */
			public function __construct(private string $name, private string $content) {
			}

			/** @return string */
			public function getName(): string {
				return $this->name;
			}

			/** @return string */
			public function getContent(): string {
				return $this->content;
			}
		};
	}//end node()

	/**
	 * Three items: an open one with a PDF and a Word file, a confidential one with a PDF, an open one without files.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
	 */
	private static function agenda(): array {
		$items = [
			['id' => 'a', 'orderNumber' => 1, 'title' => 'Begroting'],
			['id' => 'b', 'orderNumber' => 2, 'title' => 'Jaarrekening', 'confidentiality' => 'confidential'],
			['id' => 'c', 'orderNumber' => 3, 'title' => 'Rondvraag'],
		];
		$attachments = [
			'rows' => [
				'a' => [['name' => 'begroting.pdf', 'mime' => 'application/pdf', 'size' => 20], ['name' => 'detail.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 5]],
				'b' => [['name' => 'geheim.pdf', 'mime' => 'application/pdf', 'size' => 20]],
				'c' => [],
			],
			'nodes' => [
				'a' => [self::node(name: 'begroting.pdf', content: '%PDF-1.7 begroting'), self::node(name: 'detail.xlsx', content: 'xls')],
				'b' => [self::node(name: 'geheim.pdf', content: '%PDF-1.7 geheim')],
				'c' => [],
			],
		];

		return [$items, $attachments];
	}//end agenda()

	/**
	 * With a merge engine: the attachment PDF sits after its section, a bookmark opens every
	 * section, the confidential item's PDF is never sent and the Word file is a placeholder.
	 *
	 * @return void
	 */
	public function testMergesAttachmentsInPlaceAndKeepsTheConfidentialOnesOut(): void {
		[$items, $attachments] = self::agenda();
		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturnCallback(static fn (string $html): string => 'HTML:' . $html);
		$pdf->expects($this->once())->method('merge')->willReturnCallback(
			function (array $pdfs, array $bookmarks): string {
				// Front matter, section 1, its PDF, the placeholder after it, section 2, section 3.
				$this->assertSame(expected: [1 => '1. Begroting', 4 => '2. Jaarrekening', 5 => '3. Rondvraag'], actual: $bookmarks);
				$this->assertSame(expected: '%PDF-1.7 begroting', actual: $pdfs[2]);
				$this->assertCount(expectedCount: 6, haystack: $pdfs);
				$joined = implode('|', $pdfs);
				$this->assertStringNotContainsString(needle: 'geheim', haystack: $joined);
				$this->assertStringContainsString(needle: 'detail.xlsx', haystack: $pdfs[3]);
				return 'MERGED';
			}
		);

		$result = (new BoardBookRenderer(pdf: $pdf, composer: new BoardBookComposer()))->render(meeting: ['title' => 'Raad'], bodyName: '', items: $items, attachments: $attachments);

		$this->assertSame(expected: 'MERGED', actual: $result['pdf']);
		$this->assertSame(expected: ['01 - Begroting/detail.xlsx (non-PDF attachment)'], actual: $result['skipped']);
	}//end testMergesAttachmentsInPlaceAndKeepsTheConfidentialOnesOut()

	/**
	 * Without a merge engine: one document, every open attachment a placeholder, and the report says why.
	 *
	 * @return void
	 */
	public function testWithoutMergingEveryAttachmentIsAPlaceholder(): void {
		[$items, $attachments] = self::agenda();
		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('merge')->willReturn(null);
		$documents = [];
		$pdf->method('fromHtml')->willReturnCallback(
			static function (string $html) use (&$documents): string {
				$documents[] = $html;
				return 'PDF';
			}
		);

		$result = (new BoardBookRenderer(pdf: $pdf, composer: new BoardBookComposer()))->render(meeting: ['title' => 'Raad'], bodyName: '', items: $items, attachments: $attachments);

		$this->assertSame(expected: 'PDF', actual: $result['pdf']);
		$this->assertSame(
			expected: ['01 - Begroting/begroting.pdf (' . BoardBookRenderer::NOT_MERGED . ')', '01 - Begroting/detail.xlsx (' . BoardBookRenderer::NOT_MERGED . ')'],
			actual: $result['skipped']
		);
		$last = end($documents);
		$this->assertStringContainsString(needle: BoardBookComposer::CLOSED_DOORS, haystack: $last);
		$this->assertStringNotContainsString(needle: 'geheim.pdf', haystack: $last);
	}//end testWithoutMergingEveryAttachmentIsAPlaceholder()

	/**
	 * An attachment named PDF that is not one is skipped with a placeholder, not merged.
	 *
	 * @return void
	 */
	public function testAnUnparsablePdfIsSkipped(): void {
		$items = [['id' => 'a', 'orderNumber' => 4, 'title' => 'Kadernota']];
		$attachments = ['rows' => ['a' => [['name' => 'kapot.pdf', 'mime' => 'application/pdf', 'size' => 3]]], 'nodes' => ['a' => [self::node(name: 'kapot.pdf', content: 'zip')]]];
		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->expects($this->never())->method('merge');
		$pdf->method('fromHtml')->willReturn('PDF');

		$result = (new BoardBookRenderer(pdf: $pdf, composer: new BoardBookComposer()))->render(meeting: [], bodyName: '', items: $items, attachments: $attachments);

		$this->assertSame(expected: ['04 - Kadernota/kapot.pdf (unparsable or encrypted PDF)'], actual: $result['skipped']);
	}//end testAnUnparsablePdfIsSkipped()

	/**
	 * When filinq cannot render at all there is no pack.
	 *
	 * @return void
	 */
	public function testNoFilinqMeansNoPdf(): void {
		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturn(null);

		$this->assertNull(actual: (new BoardBookRenderer(pdf: $pdf, composer: new BoardBookComposer()))->render(meeting: [], bodyName: '', items: [['id' => 'a', 'title' => 'x']], attachments: ['rows' => [], 'nodes' => []]));
	}//end testNoFilinqMeansNoPdf()
}//end class
