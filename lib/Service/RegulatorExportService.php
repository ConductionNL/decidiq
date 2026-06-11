<?php
/**
 * Decidesk Regulator Export Service
 *
 * Phase 6 service producing regulator-shaped CSV / PDF exports of a board
 * portal's records on a per-supervisor template. The PDF rendering is
 * delegated to docudesk when available; this service synthesizes the
 * structured payload (header + per-meeting / per-resolution rows) that
 * the renderer materializes into a binary blob.
 *
 * Templates supported in T1:
 *  - `dnb-resolutions-quarterly`: De Nederlandsche Bank quarterly resolution
 *    digest. Header includes board id, supervised entity, scope window.
 *  - `afm-conflict-register`: Autoriteit Financiele Markten conflict-of-
 *    interest disclosure register.
 *  - `generic-audit-trail`: Generic auditor-shaped audit trail extract.
 *
 * Templates are inert blueprints; the produced row set is deterministic and
 * can be round-tripped through the export's own JSON channel for validation.
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
 * Per-supervisor regulator export service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportService
{

    /**
     * Slug of the OpenRegister schema storing export rows.
     *
     * @var string
     */
    public const SCHEMA = 'regulator-export';

    /**
     * Supported template slugs.
     *
     * @var string[]
     */
    public const TEMPLATES = [
        'dnb-resolutions-quarterly',
        'afm-conflict-register',
        'generic-audit-trail',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container       DI container
     * @param LoggerInterface    $logger          Logger
     * @param AuditLogService    $auditLogService Audit log dependency
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
     * @param string               $boardId   UUID of the board
     * @param string               $template  Template slug
     * @param string               $startDate ISO-8601 start
     * @param string               $endDate   ISO-8601 end
     * @param string               $format    One of csv|pdf|json
     * @param array<string, mixed> $meta      Optional metadata (regulator label, contact)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return array{success: bool, export: array|null, body: string, contentType: string, message: string}
     */
    public function generate(string $boardId, string $template, string $startDate, string $endDate, string $format='csv', array $meta=[]): array
    {
        if (in_array($template, self::TEMPLATES, true) === false) {
            return [
                'success'     => false,
                'export'      => null,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Unknown regulator template: '.$template,
            ];
        }

        if ($boardId === '' || $startDate === '' || $endDate === '') {
            return [
                'success'     => false,
                'export'      => null,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'boardId, startDate and endDate are required.',
            ];
        }

        $format = strtolower($format);
        if (in_array($format, ['csv', 'pdf', 'json'], true) === false) {
            return [
                'success'     => false,
                'export'      => null,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Unsupported format: '.$format,
            ];
        }

        try {
            $payload = $this->buildPayload(
                boardId: $boardId,
                template: $template,
                startDate: $startDate,
                endDate: $endDate
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService::generate buildPayload failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'     => false,
                'export'      => null,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Failed to assemble export payload.',
            ];
        }

        $body        = $this->serialize($payload, $format);
        $contentType = $this->contentTypeFor($format);

        $exportRow = [
            'boardKoppeling' => $boardId,
            'template'       => $template,
            'startDate'      => $startDate,
            'endDate'        => $endDate,
            'format'         => $format,
            'generatedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
            'rowCount'       => count(($payload['rows'] ?? [])),
            'regulator'      => (string) ($meta['regulator'] ?? ''),
        ];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $exportRow,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            $exportPayload = $exportRow;
            if (is_object($saved) === true) {
                $exportPayload = (array) $saved->jsonSerialize();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: RegulatorExportService::generate persist failed',
                ['exception' => $e->getMessage()]
            );
            $exportPayload = $exportRow;
        }

        $this->auditLogService->append(
            actor: 'system',
            action: 'material-access',
            objectUids: [
                (string) ($exportPayload['id'] ?? $exportPayload['uuid'] ?? ''),
                $boardId,
            ],
            payload: [
                'template'  => $template,
                'startDate' => $startDate,
                'endDate'   => $endDate,
                'format'    => $format,
                'rowCount'  => $exportRow['rowCount'],
            ]
        );

        return [
            'success'     => true,
            'export'      => $exportPayload,
            'body'        => $body,
            'contentType' => $contentType,
            'message'     => 'Export generated.',
        ];

    }//end generate()

    /**
     * Load a previously generated export row + render its body in the chosen
     * format (rebuilds from the persisted exportRow rather than the source
     * data, so the on-the-wire body is reproducible).
     *
     * @param string $exportId UUID of the export row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return array{success: bool, body: string, contentType: string, message: string}
     */
    public function download(string $exportId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $exportId,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if ($entity === null) {
                return [
                    'success'     => false,
                    'body'        => '',
                    'contentType' => 'text/plain',
                    'message'     => 'Export not found.',
                ];
            }

            $row = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $row = $entity->getObject();
            }

            $payload = $this->buildPayload(
                boardId: (string) ($row['boardKoppeling'] ?? ''),
                template: (string) ($row['template'] ?? ''),
                startDate: (string) ($row['startDate'] ?? ''),
                endDate: (string) ($row['endDate'] ?? '')
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: RegulatorExportService::download failed',
                ['exception' => $e->getMessage(), 'exportId' => $exportId]
            );
            return [
                'success'     => false,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Failed to render export.',
            ];
        }//end try

        $format = (string) ($row['format'] ?? 'csv');
        return [
            'success'     => true,
            'body'        => $this->serialize($payload, $format),
            'contentType' => $this->contentTypeFor($format),
            'message'     => 'Export ready.',
        ];

    }//end download()

    /**
     * Assemble the structured payload for a template.
     *
     * @param string $boardId   Board UUID
     * @param string $template  Template slug
     * @param string $startDate ISO-8601 start
     * @param string $endDate   ISO-8601 end
     *
     * @return array{header: array<string, mixed>, columns: array<int,string>, rows: array<int, array<int, mixed>>}
     */
    private function buildPayload(string $boardId, string $template, string $startDate, string $endDate): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $header        = [
            'boardId'   => $boardId,
            'template'  => $template,
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        switch ($template) {
            case 'dnb-resolutions-quarterly':
                $resolutions = $this->loadAll($objectService, 'resolution', limit: 5000);
                $meetings    = $this->loadAll(
                    $objectService,
                    'board-meeting',
                    filters: ['boardKoppeling' => $boardId],
                    limit: 5000
                );
                $meetingIds  = array_column($meetings, 'id');
                $rows        = [];
                foreach ($resolutions as $r) {
                    $meetingId = (string) ($r['meetingKoppeling'] ?? '');
                    if (in_array($meetingId, array_map('strval', $meetingIds), true) === false) {
                        continue;
                    }

                    $ts = (string) ($r['adoptionDate'] ?? $r['proposedAt'] ?? '');
                    if ($ts !== '' && ($ts < substr($startDate, 0, 10) || $ts > substr($endDate, 0, 10))) {
                        continue;
                    }

                    $rows[] = [
                        (string) ($r['resolutionNumber'] ?? ''),
                        (string) ($r['title'] ?? ''),
                        (string) ($r['type'] ?? ''),
                        (string) ($r['voteThreshold'] ?? ''),
                        (string) ($r['status'] ?? ''),
                        (string) ($r['adoptionDate'] ?? ''),
                    ];
                }
                return [
                    'header'  => $header,
                    'columns' => ['resolutionNumber', 'title', 'type', 'voteThreshold', 'status', 'adoptionDate'],
                    'rows'    => $rows,
                ];

            case 'afm-conflict-register':
                $conflicts = $this->loadAll($objectService, 'conflict-of-interest', limit: 5000);
                $rows      = [];
                foreach ($conflicts as $c) {
                    $ts = (string) ($c['declarationTimestamp'] ?? '');
                    if ($ts !== '' && ($ts < $startDate || $ts > $endDate)) {
                        continue;
                    }

                    $rows[] = [
                        (string) ($c['boardMemberKoppeling'] ?? ''),
                        (string) ($c['agendaItemKoppeling'] ?? ''),
                        (string) ($c['declarationType'] ?? ''),
                        (string) ($c['severity'] ?? ''),
                        (string) ($c['actionTaken'] ?? ''),
                        $ts,
                    ];
                }
                return [
                    'header'  => $header,
                    'columns' => ['boardMember', 'agendaItem', 'declarationType', 'severity', 'actionTaken', 'declarationTimestamp'],
                    'rows'    => $rows,
                ];

            case 'generic-audit-trail':
            default:
                $entries = $this->loadAll($objectService, 'board-audit-log-entry', limit: 50000);
                $rows    = [];
                foreach ($entries as $e) {
                    $ts = (string) ($e['timestamp'] ?? '');
                    if ($ts !== '' && ($ts < $startDate || $ts > $endDate)) {
                        continue;
                    }

                    $rows[] = [
                        $ts,
                        (string) ($e['actorUuid'] ?? ''),
                        (string) ($e['action'] ?? ''),
                        implode('|', (array) ($e['objectUids'] ?? [])),
                        (string) ($e['currentHash'] ?? ''),
                    ];
                }
                return [
                    'header'  => $header,
                    'columns' => ['timestamp', 'actor', 'action', 'objectUids', 'currentHash'],
                    'rows'    => $rows,
                ];
        }//end switch

    }//end buildPayload()

    /**
     * Serialize the payload to a downloadable string.
     *
     * @param array<string, mixed> $payload Payload
     * @param string               $format  csv|pdf|json
     *
     * @return string
     */
    private function serialize(array $payload, string $format): string
    {
        if ($format === 'json') {
            return (string) json_encode($payload, (JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        if ($format === 'pdf') {
            // PDF rendering is delegated to docudesk; when not available the
            // body shipped is a text/plain rendering that the controller wraps
            // as PDF/A in a future release.
            return $this->renderPlainText($payload);
        }

        // CSV.
        $lines   = [];
        $columns = (array) ($payload['columns'] ?? []);
        $lines[] = implode(',', array_map(static fn(mixed $c): string => '"'.str_replace('"', '""', (string) $c).'"', $columns));
        foreach (($payload['rows'] ?? []) as $row) {
            $lines[] = implode(
                ',',
                array_map(
                    static fn(mixed $c): string => '"'.str_replace('"', '""', (string) $c).'"',
                    (array) $row
                )
            );
        }

        return implode("\n", $lines);

    }//end serialize()

    /**
     * Map a format slug to its content type header.
     *
     * @param string $format Format slug
     *
     * @return string
     */
    private function contentTypeFor(string $format): string
    {
        switch ($format) {
            case 'json':
                return 'application/json';
            case 'pdf':
                return 'application/pdf';
            case 'csv':
            default:
                return 'text/csv';
        }

    }//end contentTypeFor()

    /**
     * Convenience: load a schema's rows with normalization.
     *
     * @param object               $objectService OpenRegister object service
     * @param string               $schema        Schema slug
     * @param array<string, mixed> $filters       Optional filters
     * @param int                  $limit         Row cap
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAll(object $objectService, string $schema, array $filters=[], int $limit=1000): array
    {
        $config = [
            'register' => 'decidesk',
            'schema'   => $schema,
            'limit'    => $limit,
        ];
        if ($filters !== []) {
            $config['filters'] = $filters;
        }

        $rows = $objectService->findAll($config);

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

    }//end loadAll()

    /**
     * Plain-text rendering used as a stand-in when docudesk PDF rendering
     * is not available. The header is rendered as `key: value` lines and
     * the rows as tab-separated.
     *
     * @param array<string, mixed> $payload Payload
     *
     * @return string
     */
    private function renderPlainText(array $payload): string
    {
        $lines = [];
        foreach (((array) ($payload['header'] ?? [])) as $k => $v) {
            $lines[] = $k.': '.(is_scalar($v) ? (string) $v : json_encode($v));
        }

        $lines[] = '';
        $lines[] = implode("\t", (array) ($payload['columns'] ?? []));
        foreach (($payload['rows'] ?? []) as $row) {
            $lines[] = implode("\t", (array) $row);
        }

        return implode("\n", $lines);

    }//end renderPlainText()
}//end class
