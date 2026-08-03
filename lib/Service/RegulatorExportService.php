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
     * MIME type emitted per export format.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPES = [
        'csv' => 'text/csv',
        'pdf' => 'application/pdf',
    ];

    /**
     * Renders the export body; owns all format-specific presentation rules.
     *
     * @var RegulatorExportRenderer
     */
    private readonly RegulatorExportRenderer $renderer;

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
        $this->renderer = new RegulatorExportRenderer(container: $container, logger: $logger);
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

        $rejection = $this->validateRequest(boardId: $boardId, scope: $scope, format: $format);
        if ($rejection !== null) {
            return $this->failure(message: $rejection);
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
        $body        = $this->renderer->render(
            format: $format,
            title: $title,
            generatedAt: $generatedAt,
            scope: $scope,
            rows: $rows
        );

        // The extension is always the format label ('csv' / 'pdf').
        $contentType = self::CONTENT_TYPES[$format];
        $checksum    = hash('sha256', $body);
        $filename    = sprintf('decidesk-%s-%s-%s.%s', $scope, $boardId, substr($generatedAt, 0, 10), $format);

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
     * Validate the generate() request triple.
     *
     * @param string $boardId Board UUID
     * @param string $scope   Requested scope (already lower-cased)
     * @param string $format  Requested format (already lower-cased)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return string|null Rejection message, or null when the request is valid
     */
    private function validateRequest(string $boardId, string $scope, string $format): ?string
    {
        if ($boardId === '') {
            return 'boardId is required.';
        }

        if (in_array($scope, self::SCOPES, true) === false) {
            return 'Unsupported scope: '.$scope;
        }

        if (in_array($format, self::FORMATS, true) === false) {
            return 'Unsupported format: '.$format;
        }

        return null;

    }//end validateRequest()

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
        if ($result['success'] === false) {
            return $this->failureDownload(message: $result['message']);
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
                    static fn(array $row): bool => in_array((string) ($row['meetingKoppeling'] ?? ''), $meetingIds, true)
                )
            );
        }

        // Audit-log scope — return all entries (no per-board filter; objectUids
        // contains governance references so the regulator gets a full trail).
        // Retargeted to the unified audit-trail store (ADR-006); the OR built-in
        // AuditTrail integration is finalised in Cycle 2. TODO Cycle 2.
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
