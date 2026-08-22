<?php

/**
 * Decidiq Regulator Export Renderer
 *
 * Owns the *presentation* half of the regulator export: turning a collected
 * rowset into a PDF or CSV body. Extracted from RegulatorExportService so
 * that the service keeps a single responsibility (collect + persist + audit)
 * and the rendering rules (column ordering, CSV escaping, the self-contained
 * PDF 1.4 skeleton, optional docudesk delegation) live in one place.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a regulator export body in the requested format.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportRenderer {

	/**
	 * CSV column ordering per scope; `audit-log` doubles as the fallback.
	 *
	 * @var array<string, string[]>
	 */
	private const CSV_COLUMNS = [
		'resolutions' => [
			'id',
			'meetingIntegration',
			'resolutionNumber',
			'title',
			'type',
			'status',
			'voteThreshold',
			'adoptionDate',
		],
		'minutes' => [
			'id',
			'meetingIntegration',
			'language',
			'version',
			'preparedBy',
			'reviewedBy',
			'signingCompletionDate',
			'hashSha256',
		],
		'audit-log' => [
			'id',
			'timestamp',
			'actorUuid',
			'action',
			'previousHash',
			'currentHash',
		],
	];

	/**
	 * Docudesk service candidates consulted for PDF delegation, in order.
	 *
	 * @var string[]
	 */
	private const DOCUDESK_CANDIDATES = [
		'\\OCA\\Docudesk\\Service\\PdfRenderService',
		'\\OCA\\Docudesk\\Service\\PdfService',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (optional docudesk delegation)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render the export body for the requested format.
	 *
	 * @param string $format One of RegulatorExportService::FORMATS
	 * @param string $title Document title
	 * @param string $generatedAt ISO-8601 timestamp
	 * @param string $scope Scope label
	 * @param array<int, array<string, mixed>> $rows Payload rows
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string Rendered export body
	 */
	public function render(string $format, string $title, string $generatedAt, string $scope, array $rows): string {
		if ($format === 'csv') {
			return $this->renderCsv(scope: $scope, rows: $rows);
		}

		return $this->renderPdf(title: $title, generatedAt: $generatedAt, scope: $scope, rows: $rows);
	}//end render()

	/**
	 * Build a self-contained, single-page PDF (1.4) skeleton — no third-party
	 * dependency. Compatible with regulator desk tooling that accepts PDF/A
	 * "light". When the docudesk leaf is available the rendering is delegated
	 * to it.
	 *
	 * @param string $title Document title
	 * @param string $generatedAt ISO-8601 timestamp
	 * @param string $scope Scope label
	 * @param array<int, array<string, mixed>> $rows Payload rows
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function renderPdf(string $title, string $generatedAt, string $scope, array $rows): string {
		$delegated = $this->tryDelegateToDocudesk(title: $title, generatedAt: $generatedAt, scope: $scope, rows: $rows);
		if ($delegated !== null) {
			return $delegated;
		}

		$lines = [
			$title,
			'Generated at: ' . $generatedAt,
			'Scope: ' . $scope,
			'Records: ' . count($rows),
			'',
		];

		foreach ($rows as $index => $row) {
			$lines[] = sprintf('%02d. %s', ($index + 1), $this->summariseRow(scope: $scope, row: $row));
		}

		return $this->assemblePdf(lines: $lines);
	}//end renderPdf()

	/**
	 * Try to delegate PDF generation to docudesk's PdfRenderService if
	 * available; return null when the leaf is not installed.
	 *
	 * @param string $title Document title
	 * @param string $generatedAt ISO-8601 timestamp
	 * @param string $scope Scope label
	 * @param array<int, array<string, mixed>> $rows Payload rows
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string|null
	 */
	private function tryDelegateToDocudesk(string $title, string $generatedAt, string $scope, array $rows): ?string {
		foreach (self::DOCUDESK_CANDIDATES as $candidate) {
			$blob = $this->askDocudesk(candidate: $candidate, args: [$title, $generatedAt, $scope, $rows]);
			if ($blob !== null) {
				return $blob;
			}
		}

		return null;
	}//end tryDelegateToDocudesk()

	/**
	 * Ask one docudesk candidate service to render the export.
	 *
	 * @param string $candidate Fully-qualified candidate class name
	 * @param array<mixed> $args Positional arguments for renderRegulatorExport()
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string|null Rendered blob, or null when this candidate cannot render
	 */
	private function askDocudesk(string $candidate, array $args): ?string {
		if (class_exists($candidate) === false) {
			return null;
		}

		try {
			$svc = $this->container->get($candidate);
			if (is_object($svc) === false || method_exists($svc, 'renderRegulatorExport') === false) {
				return null;
			}

			$blob = $svc->renderRegulatorExport(...$args);
			if (is_string($blob) === true && $blob !== '') {
				return $blob;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: docudesk PDF delegation failed; falling back to skeleton',
				['candidate' => $candidate, 'exception' => $e->getMessage()]
			);
		}//end try

		return null;
	}//end askDocudesk()

	/**
	 * Render rows as CSV.
	 *
	 * @param string $scope Scope label
	 * @param array<int, array<string, mixed>> $rows Payload rows
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function renderCsv(string $scope, array $rows): string {
		$columns = $this->csvColumns(scope: $scope);
		$lines = [implode(',', $columns)];
		foreach ($rows as $row) {
			$cells = [];
			foreach ($columns as $column) {
				$cells[] = $this->csvEscape(value: (string)($row[$column] ?? ''));
			}

			$lines[] = implode(',', $cells);
		}

		return implode("\n", $lines);
	}//end renderCsv()

	/**
	 * Return the CSV column ordering for a given scope.
	 *
	 * @param string $scope Scope label
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string[]
	 */
	private function csvColumns(string $scope): array {
		return (self::CSV_COLUMNS[$scope] ?? self::CSV_COLUMNS['audit-log']);
	}//end csvColumns()

	/**
	 * Safely escape a CSV cell (wraps in quotes when needed).
	 *
	 * @param string $value Raw value
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function csvEscape(string $value): string {
		if (preg_match('/[",\n]/', $value) === 1) {
			return '"' . str_replace('"', '""', $value) . '"';
		}

		return $value;
	}//end csvEscape()

	/**
	 * Summarise one row for the PDF text body.
	 *
	 * @param string $scope Scope label
	 * @param array<string, mixed> $row Row data
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function summariseRow(string $scope, array $row): string {
		if ($scope === 'resolutions') {
			return sprintf(
				'[%s] %s (%s) — status %s',
				(string)($row['resolutionNumber'] ?? '?'),
				(string)($row['title'] ?? '(untitled)'),
				(string)($row['type'] ?? '?'),
				(string)($row['status'] ?? '?')
			);
		}

		if ($scope === 'minutes') {
			return sprintf(
				'Minutes %s (%s, %s) — sha256 %s',
				(string)($row['id'] ?? '?'),
				(string)($row['language'] ?? '?'),
				(string)($row['version'] ?? '?'),
				substr((string)($row['hashSha256'] ?? ''), 0, 12)
			);
		}

		return sprintf(
			'Audit %s — %s by %s (hash %s)',
			(string)($row['timestamp'] ?? '?'),
			(string)($row['action'] ?? '?'),
			(string)($row['actorUuid'] ?? '?'),
			substr((string)($row['currentHash'] ?? ''), 0, 12)
		);

	}//end summariseRow()

	/**
	 * Assemble a minimal valid PDF 1.4 file from a list of text lines.
	 *
	 * Layered as a single page with Helvetica 10pt; one text-object per line.
	 *
	 * @param string[] $lines Lines to render
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function assemblePdf(array $lines): string {
		$stream = $this->buildTextStream(lines: $lines);

		$objects = [];
		$objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
		$objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
		$page = '3 0 obj' . "\n";
		$page .= '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]' . "\n";
		$page .= ' /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>' . "\n";
		$page .= 'endobj';
		$objects[] = $page;
		$objects[] = "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj";
		$objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";

		$pdf = "%PDF-1.4\n";
		$offsets = [0];
		foreach ($objects as $object) {
			$offsets[] = strlen($pdf);
			$pdf .= $object . "\n";
		}

		$xrefOffset = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		foreach (array_slice($offsets, 1) as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

		return $pdf;
	}//end assemblePdf()

	/**
	 * Build the PDF content-stream text object for a list of lines.
	 *
	 * @param string[] $lines Lines to render
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return string
	 */
	private function buildTextStream(array $lines): string {
		$stream = "BT\n/F1 10 Tf\n72 770 Td\n";
		$offset = '';
		foreach ($lines as $line) {
			$escaped = str_replace(
				['\\', '(', ')'],
				['\\\\', '\\(', '\\)'],
				$line
			);
			$stream .= $offset . '(' . $escaped . ") Tj\n";
			$offset = "0 -14 Td\n";
		}

		return $stream . 'ET';
	}//end buildTextStream()
}//end class
