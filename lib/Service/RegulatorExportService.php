<?php
/**
 * Decidesk Regulator Export Service
 *
 * Phase 6 — exports resolutions and meeting minutes in regulator-friendly
 * archive formats. Provides a self-contained PDF skeleton renderer (no
 * third-party PDF library required) and a CSV emitter. Every export is
 * appended to the hash-chained audit log via AuditLogService so that
 * supervisory authorities can prove which records were handed over.
 *
 * Optionally delegates PDF generation to the docudesk leaf when its
 * service is available; falls back to the built-in skeleton renderer
 * otherwise so the regulator export endpoint always works.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Regulator-facing export of resolutions and minutes.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportService
{

    /**
     * Allowed export formats.
     *
     * @var string[]
     */
    public const FORMATS = ['pdf', 'csv'];

    /**
     * Allowed scopes — which dataset gets exported.
     *
     * @var string[]
     */
    public const SCOPES = [
        'resolutions',
        'minutes',
        'audit-log',
    ];

    /**
     * Schema storing persisted regulator-export records.
     *
     * @var string
     */
    public const SCHEMA = 'regulator-export';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container       DI container (lazy ObjectService + optional docudesk)
     * @param LoggerInterface    $logger          Logger
     * @param AuditLogService    $auditLogService Hash-chained audit log
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Build and persist a regulator export.
     *
     * @param string $boardId UUID of the board to export
     * @param string $scope   One of self::SCOPES
     * @param string $format  One of self::FORMATS
     * @param string $actor   Acting user UID (for the audit log)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return array{success: bool, export: array|null, body: string, contentType: string, filename: string, message: string}
     */
    public function generate(string $boardId, string $scope, string $format, string $actor): array
    {
        $format = strtolower($format);
        $scope  = strtolower($scope);

        if ($boardId === '') {
            return $this->failure(message: 'boardId is required.');
        }

        if (in_array($scope, self::SCOPES, true) === false) {
            return $this->failure(message: 'Unsupported scope: '.$scope);
        }

        if (in_array($format, self::FORMATS, true) === false) {
            return $this->failure(message: 'Unsupported format: '.$format);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService unable to resolve ObjectService',
                ['exception' => $e->getMessage()]
            );
            return $this->failure(message: 'OpenRegister is unavailable.');
        }

        try {
            $rows = $this->collect(objectService: $objectService, boardId: $boardId, scope: $scope);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService failed to collect data',
                ['scope' => $scope, 'exception' => $e->getMessage()]
            );
            return $this->failure(message: 'Failed to collect data for scope: '.$scope);
        }

        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $title       = 'Decidesk Regulator Export — '.$scope.' — board '.$boardId;

        if ($format === 'csv') {
            $body        = $this->renderCsv(scope: $scope, rows: $rows);
            $contentType = 'text/csv';
            $extension   = 'csv';
        } else {
            $body        = $this->renderPdf(title: $title, generatedAt: $generatedAt, scope: $scope, rows: $rows);
            $contentType = 'application/pdf';
            $extension   = 'pdf';
        }

        $checksum = hash('sha256', $body);
        $filename = sprintf('decidesk-%s-%s-%s.%s', $scope, $boardId, substr($generatedAt, 0, 10), $extension);

        $exportRecord = [
            'boardKoppeling' => $boardId,
            'scope'          => $scope,
            'format'         => $format,
            'recordCount'    => count($rows),
            'sha256'         => $checksum,
            'filename'       => $filename,
            'generatedAt'    => $generatedAt,
            'generatedBy'    => $actor,
        ];

        try {
            $saved = $objectService->saveObject(
                object: $exportRecord,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
                $exportRecord = (array) $saved->jsonSerialize();
            }
        } catch (\Throwable $e) {
            // Persist failure is non-fatal — the export body is still returned.
            $this->logger->warning(
                'Decidesk: RegulatorExportService failed to persist export record',
                ['exception' => $e->getMessage()]
            );
        }

        $auditId = (string) ($exportRecord['id'] ?? $checksum);
        $this->auditLogService->append(
            actor: $actor,
            action: 'material-access',
            objectUids: [$boardId, $auditId],
            payload: [
                'kind'        => 'regulator-export',
                'scope'       => $scope,
                'format'      => $format,
                'recordCount' => count($rows),
                'sha256'      => $checksum,
            ]
        );

        return [
            'success'     => true,
            'export'      => $exportRecord,
            'body'        => $body,
            'contentType' => $contentType,
            'filename'    => $filename,
            'message'     => 'Export generated.',
        ];

    }//end generate()

    /**
     * Retrieve a previously generated export record and re-emit its body.
     *
     * @param string $exportId UUID of the persisted export record
     * @param string $actor    Acting user UID (for the audit log)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return array{success: bool, body: string, contentType: string, filename: string, export: array|null, message: string}
     */
    public function download(string $exportId, string $actor): array
    {
        if ($exportId === '') {
            return $this->failureDownload(message: 'exportId is required.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $exportId,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if ($entity === null) {
                return $this->failureDownload(message: 'Export not found.');
            }

            $record = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $record = (array) $entity->getObject();
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService::download failed',
                ['exception' => $e->getMessage()]
            );
            return $this->failureDownload(message: 'Failed to load export record.');
        }//end try

        $boardId = (string) ($record['boardKoppeling'] ?? '');
        $scope   = (string) ($record['scope'] ?? '');
        $format  = (string) ($record['format'] ?? 'pdf');

        // Re-generate the body deterministically from current data — the persisted
        // record stores metadata + sha for traceability, not the binary blob.
        $result = $this->generate(boardId: $boardId, scope: $scope, format: $format, actor: $actor);
        if (($result['success'] ?? false) === false) {
            return $this->failureDownload(message: (string) ($result['message'] ?? 'Failed to render export body.'));
        }

        return [
            'success'     => true,
            'body'        => $result['body'],
            'contentType' => $result['contentType'],
            'filename'    => (string) ($record['filename'] ?? $result['filename']),
            'export'      => $record,
            'message'     => 'Export rendered.',
        ];

    }//end download()

    /**
     * List previously generated regulator exports for a board.
     *
     * @param string $boardId UUID of the board
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return array{success: bool, exports: array<int, array<string, mixed>>, count: int}
     */
    public function listExports(string $boardId): array
    {
        if ($boardId === '') {
            return [
                'success' => false,
                'exports' => [],
                'count'   => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => self::SCHEMA,
                    'filters'  => ['boardKoppeling' => $boardId],
                    'limit'    => 500,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService::listExports failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'exports' => [],
                'count'   => 0,
            ];
        }//end try

        $out = $this->normalize(rows: $rows);
        return [
            'success' => true,
            'exports' => $out,
            'count'   => count($out),
        ];

    }//end listExports()

    /**
     * Collect raw rows for a scope.
     *
     * @param object $objectService Lazy OpenRegister ObjectService
     * @param string $boardId       Board UUID
     * @param string $scope         One of self::SCOPES
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(object $objectService, string $boardId, string $scope): array
    {
        $meetings   = $this->normalize(
            rows: $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'meeting',
                    'filters'  => ['boardKoppeling' => $boardId],
                    'limit'    => 5000,
                ]
            )
        );
        $meetingIds = array_map('strval', array_column($meetings, 'id'));

        if ($scope === 'resolutions') {
            $all = $this->normalize(
                rows: $objectService->findAll(
                    [
                        'register' => 'decidesk',
                        'schema'   => 'decision',
                        'limit'    => 5000,
                    ]
                )
            );
            return array_values(
                array_filter(
                    $all,
                    static fn(array $r): bool => in_array((string) ($r['meetingKoppeling'] ?? ''), $meetingIds, true)
                )
            );
        }

        if ($scope === 'minutes') {
            $all = $this->normalize(
                rows: $objectService->findAll(
                    [
                        'register' => 'decidesk',
                        'schema'   => 'minutes',
                        'limit'    => 5000,
                    ]
                )
            );
            return array_values(
                array_filter(
                    $all,
                    static fn(array $m): bool => in_array((string) ($m['meetingKoppeling'] ?? ''), $meetingIds, true)
                )
            );
        }

        // Audit-log scope — return all entries (no per-board filter; objectUids
        // contains governance references so the regulator gets a full trail).
        // Retargeted to the unified audit-trail store (ADR-006); the OR built-in
        // auditTrail integration is finalised in Cycle 2. // TODO Cycle 2
        return $this->normalize(
            rows: $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'audit-trail',
                    'limit'    => 5000,
                ]
            )
        );

    }//end collect()

    /**
     * Build a self-contained, single-page PDF (1.4) skeleton — no third-party
     * dependency. Compatible with regulator desk tooling that accepts PDF/A
     * "light". When the docudesk leaf is available the rendering is delegated
     * to it.
     *
     * @param string                           $title       Document title
     * @param string                           $generatedAt ISO-8601 timestamp
     * @param string                           $scope       Scope label
     * @param array<int, array<string, mixed>> $rows        Payload rows
     *
     * @return string
     */
    private function renderPdf(string $title, string $generatedAt, string $scope, array $rows): string
    {
        $delegated = $this->tryDelegateToDocudesk(title: $title, generatedAt: $generatedAt, scope: $scope, rows: $rows);
        if ($delegated !== null) {
            return $delegated;
        }

        $lines = [
            $title,
            'Generated at: '.$generatedAt,
            'Scope: '.$scope,
            'Records: '.count($rows),
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
     * @param string                           $title       Document title
     * @param string                           $generatedAt ISO-8601 timestamp
     * @param string                           $scope       Scope label
     * @param array<int, array<string, mixed>> $rows        Payload rows
     *
     * @return string|null
     */
    private function tryDelegateToDocudesk(string $title, string $generatedAt, string $scope, array $rows): ?string
    {
        $candidates = [
            '\\OCA\\Docudesk\\Service\\PdfRenderService',
            '\\OCA\\Docudesk\\Service\\PdfService',
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) === false) {
                continue;
            }

            try {
                $svc = $this->container->get($candidate);
                if (is_object($svc) === false) {
                    continue;
                }

                if (method_exists($svc, 'renderRegulatorExport') === true) {
                    $blob = $svc->renderRegulatorExport($title, $generatedAt, $scope, $rows);
                    if (is_string($blob) === true && $blob !== '') {
                        return $blob;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: docudesk PDF delegation failed; falling back to skeleton',
                    ['candidate' => $candidate, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return null;

    }//end tryDelegateToDocudesk()

    /**
     * Render rows as CSV.
     *
     * @param string                           $scope Scope label
     * @param array<int, array<string, mixed>> $rows  Payload rows
     *
     * @return string
     */
    private function renderCsv(string $scope, array $rows): string
    {
        $columns = $this->csvColumns(scope: $scope);
        $lines   = [implode(',', $columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->csvEscape(value: (string) ($row[$column] ?? ''));
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
     * @return string[]
     */
    private function csvColumns(string $scope): array
    {
        if ($scope === 'resolutions') {
            return [
                'id',
                'meetingKoppeling',
                'resolutionNumber',
                'title',
                'type',
                'status',
                'voteThreshold',
                'adoptionDate',
            ];
        }

        if ($scope === 'minutes') {
            return [
                'id',
                'meetingKoppeling',
                'language',
                'version',
                'preparedBy',
                'reviewedBy',
                'signingCompletionDate',
                'hashSha256',
            ];
        }

        return [
            'id',
            'timestamp',
            'actorUuid',
            'action',
            'previousHash',
            'currentHash',
        ];

    }//end csvColumns()

    /**
     * Safely escape a CSV cell (wraps in quotes when needed).
     *
     * @param string $value Raw value
     *
     * @return string
     */
    private function csvEscape(string $value): string
    {
        if (preg_match('/[",\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;

    }//end csvEscape()

    /**
     * Summarise one row for the PDF text body.
     *
     * @param string               $scope Scope label
     * @param array<string, mixed> $row   Row data
     *
     * @return string
     */
    private function summariseRow(string $scope, array $row): string
    {
        if ($scope === 'resolutions') {
            return sprintf(
                '[%s] %s (%s) — status %s',
                (string) ($row['resolutionNumber'] ?? '?'),
                (string) ($row['title'] ?? '(untitled)'),
                (string) ($row['type'] ?? '?'),
                (string) ($row['status'] ?? '?')
            );
        }

        if ($scope === 'minutes') {
            return sprintf(
                'Minutes %s (%s, %s) — sha256 %s',
                (string) ($row['id'] ?? '?'),
                (string) ($row['language'] ?? '?'),
                (string) ($row['version'] ?? '?'),
                substr((string) ($row['hashSha256'] ?? ''), 0, 12)
            );
        }

        return sprintf(
            'Audit %s — %s by %s (hash %s)',
            (string) ($row['timestamp'] ?? '?'),
            (string) ($row['action'] ?? '?'),
            (string) ($row['actorUuid'] ?? '?'),
            substr((string) ($row['currentHash'] ?? ''), 0, 12)
        );

    }//end summariseRow()

    /**
     * Assemble a minimal valid PDF 1.4 file from a list of text lines.
     *
     * Layered as a single page with Helvetica 10pt; one text-object per line.
     *
     * @param string[] $lines Lines to render
     *
     * @return string
     */
    private function assemblePdf(array $lines): string
    {
        $stream = "BT\n/F1 10 Tf\n72 770 Td\n";
        foreach ($lines as $index => $line) {
            $escaped = str_replace(
                ['\\', '(', ')'],
                ['\\\\', '\\(', '\\)'],
                $line
            );
            if ($index === 0) {
                $stream .= '('.$escaped.") Tj\n";
            } else {
                $stream .= "0 -14 Td\n".'('.$escaped.") Tj\n";
            }
        }

        $stream .= 'ET';

        $objects   = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
        $page      = '3 0 obj'."\n";
        $page     .= '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'."\n";
        $page     .= ' /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>'."\n";
        $page     .= 'endobj';
        $objects[] = $page;
        $objects[] = "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";

        $pdf     = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf      .= $object."\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf       .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf       .= "0000000000 65535 f \n";
        for ($i = 1, $count = count($offsets); $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF";

        return $pdf;

    }//end assemblePdf()

    /**
     * Normalise heterogeneous findAll() output to plain arrays.
     *
     * @param mixed $rows Raw findAll() result
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === true) {
                $out[] = $row;
            }
        }

        return $out;

    }//end normalize()

    /**
     * Failure shape for generate().
     *
     * @param string $message Failure message
     *
     * @return array{success: bool, export: array|null, body: string, contentType: string, filename: string, message: string}
     */
    private function failure(string $message): array
    {
        return [
            'success'     => false,
            'export'      => null,
            'body'        => '',
            'contentType' => 'text/plain',
            'filename'    => '',
            'message'     => $message,
        ];

    }//end failure()

    /**
     * Failure shape for download().
     *
     * @param string $message Failure message
     *
     * @return array{success: bool, body: string, contentType: string, filename: string, export: array|null, message: string}
     */
    private function failureDownload(string $message): array
    {
        return [
            'success'     => false,
            'body'        => '',
            'contentType' => 'text/plain',
            'filename'    => '',
            'export'      => null,
            'message'     => $message,
        ];

    }//end failureDownload()
}//end class
