<?php

/**
 * Test Suite for PdfStructureInspector
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PdfStructureInspector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Builds small PDFs and reads their structure back.
 *
 * @covers \OCA\Decidiq\Service\PdfStructureInspector
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class PdfStructureInspectorTest extends TestCase {

	/**
	 * Build a PDF from object bodies, numbered from 1, with a trailer.
	 *
	 * @param list<string> $objects The object bodies.
	 * @param string       $trailer Extra trailer entries.
	 *
	 * @return string
	 */
	public static function pdf(array $objects, string $trailer=''): string {
		$out = "%PDF-1.7\n";
		foreach ($objects as $i => $body) {
			$out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}

		return $out . "trailer\n<< /Root 1 0 R " . $trailer . " >>\n%%EOF\n";
	}//end pdf()

	/**
	 * A stream object, FlateDecode-compressed when asked.
	 *
	 * @param string $content  The stream content.
	 * @param bool   $compress Whether to compress it.
	 *
	 * @return string
	 */
	public static function stream(string $content, bool $compress=false): string {
		if ($compress === true) {
			$data = (string)gzcompress($content);
			return '<< /Length ' . strlen($data) . " /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream";
		}

		return '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
	}//end stream()

	/**
	 * A tagged, titled, Dutch one-page document with text.
	 *
	 * @return string
	 */
	public static function accessiblePdf(): string {
		return self::pdf(
			[
				'<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /MarkInfo << /Marked true >> /Lang (nl-NL) >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				'<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
				self::stream(content: "BT /F1 12 Tf (Raadsvoorstel begroting 2026) Tj ET", compress: true),
				'<< /Type /StructTreeRoot /K 7 0 R >>',
				'<< /Title (Raadsvoorstel begroting) /Producer (Writer) >>',
				'<< /S /Figure /Alt (Kaart van het centrum) >>',
			],
			'/Info 6 0 R'
		);
	}//end accessiblePdf()

	/**
	 * A tagged document reads as tagged, with its language, title, pages and text.
	 *
	 * @return void
	 */
	public function testAnAccessibleDocumentReadsItsStructure(): void {
		$facts = (new PdfStructureInspector())->inspect(bytes: self::accessiblePdf());

		self::assertSame(1, $facts['pages']);
		self::assertTrue($facts['tagged']);
		self::assertTrue($facts['marked']);
		self::assertSame('nl-NL', $facts['language']);
		self::assertSame('Raadsvoorstel begroting', $facts['title']);
		self::assertFalse($facts['outlines']);
		self::assertSame(strlen('Raadsvoorstel begroting 2026'), $facts['textCharacters']);
		self::assertSame(1, $facts['figures']);
		self::assertSame(1, $facts['figuresWithAlt']);
	}//end testAnAccessibleDocumentReadsItsStructure()

	/**
	 * A scanned page has images and no text, and no structure tree.
	 *
	 * @return void
	 */
	public function testAScannedDocumentHasImagesAndNoText(): void {
		$bytes = self::pdf(
			[
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
				'<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
				'<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
				self::stream(content: "q 595 0 0 842 0 0 cm /Im1 Do Q"),
				'<< /Type /XObject /Subtype /Image /Width 10 /Height 10 /Length 0 >>',
			]
		);

		$facts = (new PdfStructureInspector())->inspect(bytes: $bytes);

		self::assertSame(2, $facts['pages']);
		self::assertFalse($facts['tagged']);
		self::assertNull($facts['language']);
		self::assertNull($facts['title']);
		self::assertSame(0, $facts['textCharacters']);
		self::assertSame(1, $facts['images']);
	}//end testAScannedDocumentHasImagesAndNoText()

	/**
	 * An outline title is not the document title, and an outline with items counts.
	 *
	 * @return void
	 */
	public function testAnOutlineTitleIsNotTheDocumentTitle(): void {
		$bytes = self::pdf(
			[
				'<< /Type /Catalog /Pages 2 0 R /Outlines 3 0 R /Lang <FEFF006E006C> >>',
				'<< /Type /Pages /Kids [] /Count 0 >>',
				'<< /Type /Outlines /First 4 0 R /Last 4 0 R >>',
				'<< /Title (Hoofdstuk 1) /Parent 3 0 R >>',
				'<< /Producer (Writer) >>',
			],
			'/Info 5 0 R'
		);

		$facts = (new PdfStructureInspector())->inspect(bytes: $bytes);

		self::assertNull($facts['title']);
		self::assertTrue($facts['outlines']);
		self::assertSame('nl', $facts['language']);
	}//end testAnOutlineTitleIsNotTheDocumentTitle()

	/**
	 * The XMP title stands in when the Info dictionary has none.
	 *
	 * @return void
	 */
	public function testTheXmpTitleIsRead(): void {
		$bytes = self::pdf(
			[
				'<< /Type /Catalog /Metadata 2 0 R >>',
				self::stream(content: '<x:xmpmeta><dc:title><rdf:Alt><rdf:li>Notulen</rdf:li></rdf:Alt></dc:title></x:xmpmeta>'),
			]
		);

		self::assertSame('Notulen', (new PdfStructureInspector())->inspect(bytes: $bytes)['title']);
	}//end testTheXmpTitleIsRead()

	/**
	 * A figure without /Alt is counted as one without alternative text.
	 *
	 * @return void
	 */
	public function testAFigureWithoutAltIsCounted(): void {
		$bytes = self::pdf(['<< /Type /Catalog >>', '<< /S /Figure /P 1 0 R >>', '<< /S /Figure /Alt (Foto) >>']);

		$facts = (new PdfStructureInspector())->inspect(bytes: $bytes);

		self::assertSame(2, $facts['figures']);
		self::assertSame(1, $facts['figuresWithAlt']);
	}//end testAFigureWithoutAltIsCounted()

	/**
	 * Content without a PDF header is refused.
	 *
	 * @return void
	 */
	public function testContentWithoutAPdfHeaderIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('PDF header');

		(new PdfStructureInspector())->inspect(bytes: 'PK zip content');
	}//end testContentWithoutAPdfHeaderIsRefused()

	/**
	 * A PDF header without a catalog is refused.
	 *
	 * @return void
	 */
	public function testAPdfWithoutACatalogIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('catalog');

		(new PdfStructureInspector())->inspect(bytes: "%PDF-1.4\ngarbage");
	}//end testAPdfWithoutACatalogIsRefused()
}//end class
