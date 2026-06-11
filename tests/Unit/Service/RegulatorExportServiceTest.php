<?php
/**
 * Unit tests for RegulatorExportService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\RegulatorExportService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RegulatorExportService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportServiceTest extends TestCase
{


    /**
     * Build a service wired to a schema-keyed in-memory store.
     *
     * @param array<string, array<int, array<string, mixed>>> &$rowsBySchema Map schema => rows
     * @param array<int, array<string, mixed>>                &$saved        Captured saves
     *
     * @return RegulatorExportService
     */
    private function makeService(array &$rowsBySchema, array &$saved): RegulatorExportService
    {
        $rowsRef       = &$rowsBySchema;
        $savedRef      = &$saved;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$rowsRef): array {
                $schema  = (string) ($config['schema'] ?? '');
                $filters = ($config['filters'] ?? []);
                $rows    = ($rowsRef[$schema] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $k => $v) {
                                if (($row[$k] ?? null) !== $v) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }
        );
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$rowsRef) {
                $schemaKey = (string) $schema;
                foreach (($rowsRef[$schemaKey] ?? []) as $row) {
                    if (($row['id'] ?? null) === $id) {
                        $entity = $this->createMock(ObjectEntity::class);
                        $entity->method('jsonSerialize')->willReturn($row);
                        $entity->method('getObject')->willReturn($row);
                        return $entity;
                    }
                }

                return null;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$savedRef, &$rowsRef): ObjectEntity {
                $savedRef[] = $object + ['_schema' => (string) $schema];
                $row        = array_merge(['id' => $schema.'-'.count($savedRef)], $object);

                $rowsRef[(string) $schema] ??= [];
                $rowsRef[(string) $schema][] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

        return new RegulatorExportService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            auditLogService: $audit
        );

    }//end makeService()


    /**
     * generate rejects unknown templates.
     *
     * @return void
     */
    public function testGenerateRejectsUnknownTemplate(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate('b-1', 'unknown-template', '2026-01-01T00:00:00Z', '2026-12-31T23:59:59Z');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown regulator template', $result['message']);

    }//end testGenerateRejectsUnknownTemplate()


    /**
     * generate rejects missing required dates.
     *
     * @return void
     */
    public function testGenerateRejectsMissingDates(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate('b-1', 'dnb-resolutions-quarterly', '', '');
        $this->assertFalse($result['success']);

    }//end testGenerateRejectsMissingDates()


    /**
     * generate rejects unsupported formats.
     *
     * @return void
     */
    public function testGenerateRejectsUnsupportedFormat(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate(
            'b-1',
            'dnb-resolutions-quarterly',
            '2026-01-01T00:00:00Z',
            '2026-12-31T23:59:59Z',
            'docx'
        );
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported format', $result['message']);

    }//end testGenerateRejectsUnsupportedFormat()


    /**
     * generate produces the DNB resolution CSV and persists an export row.
     *
     * @return void
     */
    public function testGenerateDnbResolutionsCsv(): void
    {
        $rows = [
            'board-meeting' => [
                ['id' => 'm-1', 'boardKoppeling' => 'b-1', 'meetingDate' => '2026-04-15T10:00:00Z'],
            ],
            'resolution'    => [
                [
                    'id'               => 'r-1',
                    'meetingKoppeling' => 'm-1',
                    'resolutionNumber' => 'R-2026-001',
                    'title'            => 'Budget Approval',
                    'type'             => 'financial',
                    'voteThreshold'    => 'simple-majority',
                    'status'           => 'adopted',
                    'adoptionDate'     => '2026-04-15',
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate(
            'b-1',
            'dnb-resolutions-quarterly',
            '2026-01-01T00:00:00Z',
            '2026-12-31T23:59:59Z',
            'csv'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('text/csv', $result['contentType']);
        $this->assertStringContainsString('R-2026-001', $result['body']);
        $this->assertStringContainsString('Budget Approval', $result['body']);

        $exportSaves = array_filter($saved, static fn(array $s): bool => ($s['_schema'] ?? '') === RegulatorExportService::SCHEMA);
        $this->assertCount(1, $exportSaves);

    }//end testGenerateDnbResolutionsCsv()


    /**
     * generate produces JSON when requested.
     *
     * @return void
     */
    public function testGenerateJsonFormat(): void
    {
        $rows = [
            'board-meeting' => [['id' => 'm-1', 'boardKoppeling' => 'b-1', 'meetingDate' => '2026-04-15T10:00:00Z']],
            'resolution'    => [
                ['id' => 'r-1', 'meetingKoppeling' => 'm-1', 'resolutionNumber' => 'R-2026-001', 'adoptionDate' => '2026-04-15'],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate('b-1', 'dnb-resolutions-quarterly', '2026-01-01T00:00:00Z', '2026-12-31T23:59:59Z', 'json');

        $this->assertTrue($result['success']);
        $this->assertSame('application/json', $result['contentType']);
        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('b-1', $decoded['header']['boardId']);

    }//end testGenerateJsonFormat()


    /**
     * generate produces the AFM conflict register.
     *
     * @return void
     */
    public function testGenerateAfmConflictRegister(): void
    {
        $rows = [
            'conflict-of-interest' => [
                [
                    'id'                   => 'c-1',
                    'boardMemberKoppeling' => 'bm-1',
                    'agendaItemKoppeling'  => 'ai-1',
                    'declarationType'      => 'financial-interest',
                    'severity'             => 'material',
                    'actionTaken'          => 'recused-from-vote',
                    'declarationTimestamp' => '2026-04-15T10:00:00Z',
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate(
            'b-1',
            'afm-conflict-register',
            '2026-01-01T00:00:00Z',
            '2026-12-31T23:59:59Z',
            'csv'
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('financial-interest', $result['body']);
        $this->assertStringContainsString('recused-from-vote', $result['body']);

    }//end testGenerateAfmConflictRegister()


    /**
     * generate produces the generic audit-trail extract.
     *
     * @return void
     */
    public function testGenerateGenericAuditTrail(): void
    {
        $rows = [
            'board-audit-log-entry' => [
                [
                    'id'           => 'a-1',
                    'timestamp'    => '2026-04-15T10:00:00Z',
                    'actorUuid'    => 'alice',
                    'action'       => 'vote',
                    'objectUids'   => ['res-1', 'v-1'],
                    'currentHash'  => 'aaaa',
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->generate(
            'b-1',
            'generic-audit-trail',
            '2026-01-01T00:00:00Z',
            '2026-12-31T23:59:59Z',
            'csv'
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('alice', $result['body']);
        $this->assertStringContainsString('vote', $result['body']);

    }//end testGenerateGenericAuditTrail()


    /**
     * download reproduces the export body from a persisted row.
     *
     * @return void
     */
    public function testDownloadReproducesBody(): void
    {
        $rows = [
            RegulatorExportService::SCHEMA => [
                [
                    'id'             => 'exp-1',
                    'boardKoppeling' => 'b-1',
                    'template'       => 'generic-audit-trail',
                    'startDate'      => '2026-01-01T00:00:00Z',
                    'endDate'        => '2026-12-31T23:59:59Z',
                    'format'         => 'csv',
                ],
            ],
            'board-audit-log-entry'        => [
                [
                    'id'           => 'a-1',
                    'timestamp'    => '2026-04-15T10:00:00Z',
                    'actorUuid'    => 'alice',
                    'action'       => 'vote',
                    'objectUids'   => ['res-1'],
                    'currentHash'  => 'aa',
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->download('exp-1');

        $this->assertTrue($result['success']);
        $this->assertSame('text/csv', $result['contentType']);
        $this->assertStringContainsString('alice', $result['body']);

    }//end testDownloadReproducesBody()


    /**
     * download reports 'not found' for a missing export.
     *
     * @return void
     */
    public function testDownloadReportsNotFound(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->download('does-not-exist');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);

    }//end testDownloadReportsNotFound()


}//end class
