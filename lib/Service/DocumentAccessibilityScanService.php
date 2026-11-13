<?php

/**
 * Decidiq Document Accessibility Scan Service
 *
 * Scans an uploaded document for the structural signs of an accessible PDF
 * and stores the outcome as an accessibility-scan-report object. A heuristic
 * scan, never a certified PDF/UA or EN 301 549 audit.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a document into a scan report: status, findings with evidence and
 * remediation guidance, and the checks evaluated. A file that is too large,
 * not a PDF or not readable is `not-scanned`, never `pass`.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class DocumentAccessibilityScanService {

	/**
	 * The scanner version recorded on every report.
	 *
	 * @var string
	 */
	public const SCANNER_VERSION = '1.0.0';

	/**
	 * Files above this size are not read.
	 *
	 * @var int
	 */
	public const SIZE_CAP = 25 * 1024 * 1024;

	/**
	 * Documents longer than this many pages need bookmarks.
	 *
	 * @var int
	 */
	public const LONG_DOCUMENT_PAGES = 20;

	/**
	 * Fewer extracted characters per page than this, with images present, reads as a scan.
	 *
	 * @var int
	 */
	public const MIN_TEXT_PER_PAGE = 10;

	/**
	 * The report schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'accessibility-scan-report';

	/**
	 * The checks a PDF scan evaluates.
	 *
	 * @var list<string>
	 */
	public const PDF_CHECKS = ['tagged', 'language', 'title', 'bookmarks', 'text-layer', 'alt-text'];

	/**
	 * How to fix each finding at the source, in English; translated on use.
	 *
	 * @var array<string, string>
	 */
	private const GUIDANCE = [
		'not-tagged' => 'The PDF has no structure tags, so screen readers cannot follow headings, lists and tables. '
			. 'Export it again from the source document with "tagged PDF" or "accessibility" switched on.',
		'no-language' => 'The PDF does not state its language. '
			. 'Set the document language in the source document, or in the PDF properties, before exporting.',
		'no-title' => 'The PDF has no document title. Fill in the title in the document properties of the source document and export again.',
		'no-bookmarks' => 'This long document has no bookmarks. Use headings in the source document and export with bookmarks switched on.',
		'no-text-layer' => 'This looks like a scanned document without a text layer. '
			. 'Export it again from the source document, or run text recognition (OCR) before uploading.',
		'missing-alt-text' => 'Some images have no alternative text. '
			. 'Add a description to each meaningful image in the source document and export again.',
		'too-large' => 'The file is too large to scan. Split it into smaller documents or reduce the image resolution.',
		'parse-failure' => 'The file could not be read as a PDF. Export it again from the source document.',
		'unsupported-format' => 'Only PDF documents are checked. Publish a tagged PDF version of this document where possible.',
	];

	/**
	 * Constructor.
	 *
	 * @param PdfStructureInspector  $inspector     Reads a PDF's structure.
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param IRootFolder            $rootFolder    The root folder, to read a file by id.
	 * @param IL10N                  $l10n          Translations for the remediation guidance.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function __construct(
		private readonly PdfStructureInspector $inspector,
		private readonly ObjectServiceInterface $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Scan one attached file and store the report, superseding an earlier one for the file.
	 *
	 * @param int         $fileId         The Nextcloud file id.
	 * @param string      $sourceObject   The meeting or agenda item the file belongs to.
	 * @param string      $sourceSchema   `meeting` or `agenda-item`.
	 * @param string|null $governanceBody The body of the source's meeting, when known.
	 *
	 * @return array<string, mixed> The stored report.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged
	 */
	public function scanFile(int $fileId, string $sourceObject, string $sourceSchema, ?string $governanceBody=null): array {
		$node   = $this->fileNode(fileId: $fileId);
		$result = $this->unreadable(fileName: '', mimeType: null, size: null, evidence: 'The file could not be found');
		if ($node !== null) {
			$result = $this->evaluate(
				fileName: (string)$node->getName(),
				mimeType: (string)$node->getMimetype(),
				size: (int)$node->getSize(),
				content: static fn (): string => (string)$node->getContent()
			);
		}

		$report = array_merge(
			$result,
			[
				'fileId' => $fileId,
				'sourceObject' => $sourceObject,
				'sourceSchema' => $sourceSchema,
				'governanceBody' => $governanceBody,
				'scannerVersion' => self::SCANNER_VERSION,
				'scannedAt' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
				'heuristic' => true,
			]
		);

		return $this->store(report: $report);
	}//end scanFile()

	/**
	 * Evaluate a document: the status, the findings and the checks evaluated.
	 *
	 * @param string      $fileName The file name.
	 * @param string|null $mimeType The MIME type, when known.
	 * @param int         $size     The size in bytes.
	 * @param callable    $content  Returns the file's bytes; only called when the file is read.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function evaluate(string $fileName, ?string $mimeType, int $size, callable $content): array {
		if ($this->isPdf(fileName: $fileName, mimeType: $mimeType) === false) {
			$note = sprintf('%s, %d bytes; content checks apply to PDF only', ($mimeType ?? 'unknown type'), $size);
			return $this->result(
				fileName: $fileName,
				mimeType: $mimeType,
				size: $size,
				status: 'not-scanned',
				findings: [$this->finding(code: 'unsupported-format', severity: 'info', evidence: $note)]
			);
		}

		if ($size > self::SIZE_CAP) {
			$evidence = sprintf('%d bytes, above the %d byte limit', $size, self::SIZE_CAP);
			return $this->result(
				fileName: $fileName,
				mimeType: $mimeType,
				size: $size,
				status: 'not-scanned',
				findings: [$this->finding(code: 'too-large', severity: 'info', evidence: $evidence)]
			);
		}

		try {
			$facts = $this->inspector->inspect(bytes: (string)$content());
		} catch (Throwable $e) {
			$context = ['file' => $fileName, 'error' => $e->getMessage()];
			$this->logger->info('Decidiq: a document could not be read for the accessibility scan', $context);
			return $this->unreadable(fileName: $fileName, mimeType: $mimeType, size: $size, evidence: $e->getMessage());
		}

		$findings = $this->pdfFindings(facts: $facts);
		return $this->result(
			fileName: $fileName,
			mimeType: $mimeType,
			size: $size,
			status: self::statusOf(findings: $findings),
			findings: $findings,
			checks: self::PDF_CHECKS,
			pageCount: $facts['pages']
		);
	}//end evaluate()

	/**
	 * The current report for a file, or null when it was never scanned.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged
	 */
	public function latestReport(int $fileId): ?array {
		$latest = null;
		foreach ($this->reportsOf(fileId: $fileId) as $report) {
			if ($latest === null || (string)($report['scannedAt'] ?? '') > (string)($latest['scannedAt'] ?? '')) {
				$latest = $report;
			}
		}

		return $latest;
	}//end latestReport()

	/**
	 * The status a set of findings comes to: an error fails, a warning warns.
	 *
	 * @param list<array<string, string>> $findings The findings.
	 *
	 * @return string `pass`, `warnings` or `fail`.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public static function statusOf(array $findings): string {
		$severities = array_column($findings, 'severity');
		if (in_array('error', $severities, true) === true) {
			return 'fail';
		}

		if (in_array('warning', $severities, true) === true) {
			return 'warnings';
		}

		return 'pass';
	}//end statusOf()

	/**
	 * How to fix a finding at the source.
	 *
	 * @param string $code The finding code.
	 *
	 * @return string The guidance, in the user's language.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-004-remediation-guidance-per-finding
	 */
	public function guidance(string $code): string {
		if (isset(self::GUIDANCE[$code]) === false) {
			return '';
		}

		return $this->l10n->t(self::GUIDANCE[$code]);
	}//end guidance()

	/**
	 * The findings for a PDF's structural facts.
	 *
	 * @param array<string, mixed> $facts The structural facts PdfStructureInspector::inspect() reads.
	 *
	 * @return list<array<string, string>>
	 */
	private function pdfFindings(array $facts): array {
		$pages    = $facts['pages'];
		$findings = [];
		if ($facts['tagged'] === false) {
			$findings[] = $this->finding(code: 'not-tagged', severity: 'error', evidence: 'No StructTreeRoot in the document catalog');
		}

		if ($pages > 0 && $facts['images'] > 0 && $facts['textCharacters'] < ($pages * self::MIN_TEXT_PER_PAGE)) {
			$evidence   = sprintf('%d extractable characters across %d pages, %d images', $facts['textCharacters'], $pages, $facts['images']);
			$findings[] = $this->finding(code: 'no-text-layer', severity: 'error', evidence: $evidence);
		}

		if ($facts['language'] === null) {
			$findings[] = $this->finding(code: 'no-language', severity: 'warning', evidence: 'No /Lang in the document catalog');
		}

		if ($facts['title'] === null) {
			$findings[] = $this->finding(code: 'no-title', severity: 'warning', evidence: 'No title in the document information or metadata');
		}

		if ($pages > self::LONG_DOCUMENT_PAGES && $facts['outlines'] === false) {
			$findings[] = $this->finding(code: 'no-bookmarks', severity: 'warning', evidence: sprintf('%d pages, no outline', $pages));
		}

		$withoutAlt = ($facts['figures'] - $facts['figuresWithAlt']);
		if ($withoutAlt > 0) {
			$evidence   = sprintf('%d of %d figures without /Alt', $withoutAlt, $facts['figures']);
			$findings[] = $this->finding(code: 'missing-alt-text', severity: 'warning', evidence: $evidence);
		}

		return $findings;
	}//end pdfFindings()

	/**
	 * The result for a file that could not be read.
	 *
	 * @param string      $fileName The file name.
	 * @param string|null $mimeType The MIME type.
	 * @param int|null    $size     The size in bytes.
	 * @param string      $evidence Why it could not be read.
	 *
	 * @return array<string, mixed>
	 */
	private function unreadable(string $fileName, ?string $mimeType, ?int $size, string $evidence): array {
		return $this->result(
			fileName: $fileName,
			mimeType: $mimeType,
			size: $size,
			status: 'not-scanned',
			findings: [$this->finding(code: 'parse-failure', severity: 'info', evidence: $evidence)]
		);
	}//end unreadable()

	/**
	 * Assemble a result.
	 *
	 * @param string                      $fileName  The file name.
	 * @param string|null                 $mimeType  The MIME type.
	 * @param int|null                    $size      The size in bytes.
	 * @param string                      $status    The status.
	 * @param list<array<string, string>> $findings  The findings.
	 * @param list<string>                $checks    The checks evaluated.
	 * @param int|null                    $pageCount The page count, when read.
	 *
	 * @return array<string, mixed>
	 */
	private function result(
		string $fileName,
		?string $mimeType,
		?int $size,
		string $status,
		array $findings,
		array $checks=[],
		?int $pageCount=null
	): array {
		return [
			'fileName' => $fileName,
			'mimeType' => $mimeType,
			'fileSize' => $size,
			'status' => $status,
			'findings' => $findings,
			'checks' => $checks,
			'pageCount' => $pageCount,
		];
	}//end result()

	/**
	 * One finding with its guidance.
	 *
	 * @param string $code     The finding code.
	 * @param string $severity `error`, `warning` or `info`.
	 * @param string $evidence What was observed.
	 *
	 * @return array<string, string>
	 */
	private function finding(string $code, string $severity, string $evidence): array {
		return ['code' => $code, 'severity' => $severity, 'evidence' => $evidence, 'guidance' => $this->guidance(code: $code)];
	}//end finding()

	/**
	 * Whether a file is a PDF, by MIME type or else by extension.
	 *
	 * @param string      $fileName The file name.
	 * @param string|null $mimeType The MIME type.
	 *
	 * @return bool
	 */
	private function isPdf(string $fileName, ?string $mimeType): bool {
		if ($mimeType === 'application/pdf') {
			return true;
		}

		return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
	}//end isPdf()

	/**
	 * The file with an id, or null.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return File|null
	 */
	private function fileNode(int $fileId): ?File {
		try {
			$node = $this->rootFolder->getFirstNodeById($fileId);
		} catch (Throwable) {
			return null;
		}

		if ($node instanceof File === false) {
			return null;
		}

		return $node;
	}//end fileNode()

	/**
	 * The stored reports of a file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function reportsOf(int $fileId): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA, 'fileId' => $fileId]],
			_rbac: false,
			_multitenancy: false
		);

		$reports = [];
		foreach ((array)($rows['results'] ?? $rows) as $row) {
			$data = $this->reportData(row: $row);
			if ($data !== null && (int)($data['fileId'] ?? 0) === $fileId) {
				$reports[] = $data;
			}
		}

		return $reports;
	}//end reportsOf()

	/**
	 * Store a report over the file's earlier one, or as a new one.
	 *
	 * The whole report is written, so nothing of the earlier scan survives in it.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<string, mixed> The stored report.
	 */
	private function store(array $report): array {
		$earlier = $this->latestReport(fileId: (int)$report['fileId']);
		$uuid    = null;
		if ($earlier !== null) {
			$uuid = (string)($earlier['id'] ?? ($earlier['@self']['id'] ?? ''));
			if ($uuid === '') {
				$uuid = null;
			}
		}

		$saved = $this->objectService->saveObject(
			object: $report,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return ($this->reportData(row: $saved) ?? $report);
	}//end store()

	/**
	 * A stored report as data with its id, or null when the row is not one.
	 *
	 * @param mixed $row An object entity, or already its data.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reportData(mixed $row): ?array {
		if ($row instanceof ObjectEntityInterface) {
			$data = $row->getObject();
			$uuid = $row->getUuid();
			if ($uuid !== null && $uuid !== '') {
				$data['id'] = $uuid;
			}

			return $data;
		}

		if (is_array($row) === true) {
			return $row;
		}

		return null;
	}//end reportData()
}//end class
