<?php

/**
 * Test Suite for DocumentAccessibilityScanService
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

use DateTime;
use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\Decidiq\Service\PdfStructureInspector;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The scan's statuses, findings and stored reports.
 *
 * @covers \OCA\Decidiq\Service\DocumentAccessibilityScanService
 * @uses   \OCA\Decidiq\Service\PdfStructureInspector
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class DocumentAccessibilityScanServiceTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;
	private IRootFolder&MockObject $rootFolder;
	private DocumentAccessibilityScanService $service;

	/**
	 * Set up the service with a fixed clock and pass-through translations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->rootFolder    = $this->createMock(IRootFolder::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-02T09:15:00+00:00'));

		$this->service = new DocumentAccessibilityScanService(
			inspector: new PdfStructureInspector(),
			objectService: $this->objectService,
			rootFolder: $this->rootFolder,
			l10n: $l10n,
			time: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The codes of a result's findings.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return list<string>
	 */
	private static function codes(array $result): array {
		return array_column($result['findings'], 'code');
	}//end codes()

	/**
	 * A tagged PDF with language, title and text passes, and lists its checks.
	 *
	 * @return void
	 */
	public function testAWellFormedTaggedPdfPasses(): void {
		$result = $this->service->evaluate(fileName: 'voorstel.pdf', mimeType: 'application/pdf', size: 900, content: static fn (): string => PdfStructureInspectorTest::accessiblePdf());

		self::assertSame('pass', $result['status']);
		self::assertSame([], $result['findings']);
		self::assertSame(DocumentAccessibilityScanService::PDF_CHECKS, $result['checks']);
		self::assertSame(1, $result['pageCount']);
	}//end testAWellFormedTaggedPdfPasses()

	/**
	 * An untagged scan fails with not-tagged and no-text-layer, with evidence and guidance.
	 *
	 * @return void
	 */
	public function testAnUntaggedScannedPdfFails(): void {
		$pdf = PdfStructureInspectorTest::pdf(
			[
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				'<< /Type /Page /Parent 2 0 R >>',
				'<< /Type /XObject /Subtype /Image /Length 0 >>',
			]
		);

		$result = $this->service->evaluate(fileName: 'scan.pdf', mimeType: 'application/pdf', size: 100, content: static fn (): string => $pdf);

		self::assertSame('fail', $result['status']);
		self::assertSame(['not-tagged', 'no-text-layer', 'no-language', 'no-title'], self::codes($result));
		self::assertSame('0 extractable characters across 1 pages, 1 images', $result['findings'][1]['evidence']);
		self::assertStringContainsString('OCR', $result['findings'][1]['guidance']);
	}//end testAnUntaggedScannedPdfFails()

	/**
	 * A long tagged document without an outline only warns.
	 *
	 * @return void
	 */
	public function testALongDocumentWithoutBookmarksWarns(): void {
		$pages = str_repeat("<< /Type /Page /Parent 2 0 R >>\n", 21);
		$pdf   = PdfStructureInspectorTest::pdf(
			[
				'<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R /Lang (nl) >>',
				'<< /Type /Pages /Count 21 >>',
				'<< /Title (Begroting) >>',
				'<< /Type /StructTreeRoot >>',
				$pages,
			],
			'/Info 3 0 R'
		);

		$result = $this->service->evaluate(fileName: 'begroting.pdf', mimeType: null, size: 100, content: static fn (): string => $pdf);

		self::assertSame('warnings', $result['status']);
		self::assertSame(['no-bookmarks'], self::codes($result));
		self::assertSame('21 pages, no outline', $result['findings'][0]['evidence']);
	}//end testALongDocumentWithoutBookmarksWarns()

	/**
	 * A file the parser cannot read is not-scanned, never pass, and nothing escapes.
	 *
	 * @return void
	 */
	public function testAMalformedPdfIsNotScanned(): void {
		$result = $this->service->evaluate(fileName: 'kapot.pdf', mimeType: 'application/pdf', size: 10, content: static fn (): string => 'not a pdf');

		self::assertSame('not-scanned', $result['status']);
		self::assertSame(['parse-failure'], self::codes($result));
	}//end testAMalformedPdfIsNotScanned()

	/**
	 * A file above the cap is not read at all.
	 *
	 * @return void
	 */
	public function testAFileAboveTheCapIsNotRead(): void {
		$read   = false;
		$result = $this->service->evaluate(
			fileName: 'groot.pdf',
			mimeType: 'application/pdf',
			size: (DocumentAccessibilityScanService::SIZE_CAP + 1),
			content: static function () use (&$read): string {
				$read = true;
				return '';
			}
		);

		self::assertFalse($read);
		self::assertSame('not-scanned', $result['status']);
		self::assertSame(['too-large'], self::codes($result));
	}//end testAFileAboveTheCapIsNotRead()

	/**
	 * A non-PDF gets a size and type note only.
	 *
	 * @return void
	 */
	public function testANonPdfGetsATypeNoteOnly(): void {
		$mime   = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$result = $this->service->evaluate(fileName: 'notitie.docx', mimeType: $mime, size: 2048, content: static fn (): string => '');

		self::assertSame('not-scanned', $result['status']);
		self::assertSame('unsupported-format', $result['findings'][0]['code']);
		self::assertSame('info', $result['findings'][0]['severity']);
		self::assertSame($mime . ', 2048 bytes; content checks apply to PDF only', $result['findings'][0]['evidence']);
	}//end testANonPdfGetsATypeNoteOnly()

	/**
	 * Errors fail, warnings warn, info alone passes.
	 *
	 * @return void
	 */
	public function testStatusOfFindings(): void {
		self::assertSame('fail', DocumentAccessibilityScanService::statusOf([['severity' => 'warning'], ['severity' => 'error']]));
		self::assertSame('warnings', DocumentAccessibilityScanService::statusOf([['severity' => 'info'], ['severity' => 'warning']]));
		self::assertSame('pass', DocumentAccessibilityScanService::statusOf([]));
	}//end testStatusOfFindings()

	/**
	 * A rescan is written over the file's earlier report, whole.
	 *
	 * @return void
	 */
	public function testARescanSupersedesTheEarlierReport(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('voorstel.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(900);
		$file->method('getContent')->willReturn(PdfStructureInspectorTest::accessiblePdf());
		$this->rootFolder->method('getFirstNodeById')->with(42)->willReturn($file);

		$earlier = $this->createMock(ObjectEntityInterface::class);
		$earlier->method('getObject')->willReturn(['fileId' => 42, 'status' => 'fail', 'scannedAt' => '2026-06-01T00:00:00+00:00']);
		$earlier->method('getUuid')->willReturn('report-1');
		$this->objectService->method('findAll')->willReturn([$earlier]);

		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('getObject')->willReturn(['fileId' => 42, 'status' => 'pass']);
		$saved->method('getUuid')->willReturn('report-1');
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(static function (array $report): bool {
					return $report['status'] === 'pass' && $report['findings'] === [] && $report['fileId'] === 42
						&& $report['sourceObject'] === 'item-1' && $report['sourceSchema'] === 'agenda-item'
						&& $report['governanceBody'] === 'body-1' && $report['scannerVersion'] === '1.0.0'
						&& $report['scannedAt'] === '2026-06-02T09:15:00+00:00' && $report['heuristic'] === true;
				}),
				self::anything(),
				'decidiq',
				'accessibility-scan-report',
				'report-1'
			)
			->willReturn($saved);

		$report = $this->service->scanFile(fileId: 42, sourceObject: 'item-1', sourceSchema: 'agenda-item', governanceBody: 'body-1');

		self::assertSame(['fileId' => 42, 'status' => 'pass', 'id' => 'report-1'], $report);
	}//end testARescanSupersedesTheEarlierReport()

	/**
	 * A missing file is stored as not-scanned, as a new report.
	 *
	 * @return void
	 */
	public function testAMissingFileIsStoredAsNotScanned(): void {
		$this->rootFolder->method('getFirstNodeById')->willReturn(null);
		$this->objectService->method('findAll')->willReturn([]);
		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('getObject')->willReturn(['status' => 'not-scanned']);
		$saved->method('getUuid')->willReturn('report-2');
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(static fn (array $report): bool => $report['status'] === 'not-scanned' && $report['findings'][0]['code'] === 'parse-failure'),
				self::anything(),
				'decidiq',
				'accessibility-scan-report',
				null
			)
			->willReturn($saved);

		self::assertSame('report-2', $this->service->scanFile(fileId: 7, sourceObject: 'm-1', sourceSchema: 'meeting')['id']);
	}//end testAMissingFileIsStoredAsNotScanned()

	/**
	 * The latest report of a file is the one scanned last.
	 *
	 * @return void
	 */
	public function testTheLatestReportIsTheNewest(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['fileId' => 5, 'status' => 'fail', 'scannedAt' => '2026-06-01T00:00:00+00:00'],
				['fileId' => 5, 'status' => 'pass', 'scannedAt' => '2026-06-03T00:00:00+00:00'],
				['fileId' => 6, 'status' => 'warnings', 'scannedAt' => '2026-06-09T00:00:00+00:00'],
			]
		);

		self::assertSame('pass', $this->service->latestReport(fileId: 5)['status']);
	}//end testTheLatestReportIsTheNewest()
}//end class
