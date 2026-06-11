<?php
/**
 * Unit tests for MultilingualReconciliationService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MultilingualReconciliationService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationServiceTest extends TestCase
{


    /**
     * Build a service wired over a schema-keyed in-memory store.
     *
     * @param array<string, array<int, array<string, mixed>>> &$rowsBySchema Map schema => rows
     * @param array<int, array<string, mixed>>                &$saved        Captured saves
     *
     * @return MultilingualReconciliationService
     */
    private function makeService(array &$rowsBySchema, array &$saved): MultilingualReconciliationService
    {
        $rowsRef       = &$rowsBySchema;
        $savedRef      = &$saved;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$rowsRef): array {
                $schema = (string) ($config['schema'] ?? '');
                return ($rowsRef[$schema] ?? []);
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
                $existingId = ($uuid ?? ($object['id'] ?? null));
                if ($existingId !== null) {
                    $rowsRef[(string) $schema] ??= [];
                    foreach ($rowsRef[(string) $schema] as $i => $row) {
                        if (($row['id'] ?? null) === $existingId) {
                            $rowsRef[(string) $schema][$i] = array_merge($row, $object, ['id' => $existingId]);
                            $row     = $rowsRef[(string) $schema][$i];
                            $entity  = $this->createMock(ObjectEntity::class);
                            $entity->method('jsonSerialize')->willReturn($row);
                            $entity->method('getObject')->willReturn($row);
                            return $entity;
                        }
                    }
                }

                $row = array_merge(['id' => $schema.'-'.count($savedRef)], $object);
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

        return new MultilingualReconciliationService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class)
        );

    }//end makeService()


    /**
     * enqueue rejects identical source/target languages.
     *
     * @return void
     */
    public function testEnqueueRejectsIdenticalLanguages(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->enqueue('min-1', 'nl', 'nl');
        $this->assertFalse($result['success']);

    }//end testEnqueueRejectsIdenticalLanguages()


    /**
     * enqueue rejects unsupported languages.
     *
     * @return void
     */
    public function testEnqueueRejectsUnsupportedLanguages(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->enqueue('min-1', 'de', 'en');
        $this->assertFalse($result['success']);

    }//end testEnqueueRejectsUnsupportedLanguages()


    /**
     * enqueue persists an awaiting-translator entry.
     *
     * @return void
     */
    public function testEnqueuePersistsAwaitingTranslator(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->enqueue('min-1', 'nl', 'en');
        $this->assertTrue($result['success']);
        $this->assertSame('awaiting-translator', end($saved)['status']);

    }//end testEnqueuePersistsAwaitingTranslator()


    /**
     * extractStructure counts resolutions and sections from markdown.
     *
     * @return void
     */
    public function testExtractStructureCountsMarkdown(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $content = <<<MD
# Agenda

## Item 1

R-2026-001 Budget approval.

## Item 2

R-2026-002 Personnel matters.

R-2026-001 Restated.
MD;

        $structure = $svc->extractStructure($content);
        $this->assertSame(2, $structure['resolutionCount']);
        $this->assertSame(['Agenda', 'Item 1', 'Item 2'], $structure['sectionList']);

    }//end testExtractStructureCountsMarkdown()


    /**
     * extractStructure handles HTML headings.
     *
     * @return void
     */
    public function testExtractStructureCountsHtml(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $content = '<h1>Notulen</h1><p>R-2026-001 budget.</p><h2>Section</h2>';

        $structure = $svc->extractStructure($content);
        $this->assertSame(1, $structure['resolutionCount']);
        $this->assertSame(['Notulen', 'Section'], $structure['sectionList']);

    }//end testExtractStructureCountsHtml()


    /**
     * reconcile reports clean when counts and signers align.
     *
     * @return void
     */
    public function testReconcileReportsCleanWhenAligned(): void
    {
        $rows = [
            'board-minutes' => [
                [
                    'id'       => 'min-nl',
                    'content'  => "# Notulen\nR-2026-001 budget.",
                    'signedBy' => [
                        ['signerUuid' => 'm-1'],
                        ['signerUuid' => 'm-2'],
                    ],
                ],
                [
                    'id'       => 'min-en',
                    'content'  => "# Minutes\nR-2026-001 budget.",
                    'signedBy' => [
                        ['signerUuid' => 'm-2'],
                        ['signerUuid' => 'm-1'],
                    ],
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reconcile('min-nl', 'min-en');

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['severity']);
        $this->assertSame([], $result['discrepancies']);

    }//end testReconcileReportsCleanWhenAligned()


    /**
     * reconcile reports discrepancies when resolution counts diverge.
     *
     * @return void
     */
    public function testReconcileReportsResolutionCountMismatch(): void
    {
        $rows = [
            'board-minutes' => [
                [
                    'id'       => 'min-nl',
                    'content'  => "# Notulen\nR-2026-001 budget.\nR-2026-002 personeel.",
                    'signedBy' => [],
                ],
                [
                    'id'       => 'min-en',
                    'content'  => "# Minutes\nR-2026-001 budget.",
                    'signedBy' => [],
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reconcile('min-nl', 'min-en');

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['severity']);
        $this->assertNotEmpty($result['discrepancies']);
        $this->assertStringContainsString('resolution-count-mismatch', implode(' ', $result['discrepancies']));

    }//end testReconcileReportsResolutionCountMismatch()


    /**
     * reconcile reports signedBy mismatch when signer lists differ.
     *
     * @return void
     */
    public function testReconcileReportsSignedByMismatch(): void
    {
        $rows = [
            'board-minutes' => [
                [
                    'id'       => 'min-nl',
                    'content'  => "# Notulen\nR-2026-001 budget.",
                    'signedBy' => [['signerUuid' => 'm-1'], ['signerUuid' => 'm-2']],
                ],
                [
                    'id'       => 'min-en',
                    'content'  => "# Minutes\nR-2026-001 budget.",
                    'signedBy' => [['signerUuid' => 'm-1']],
                ],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reconcile('min-nl', 'min-en');

        $this->assertSame('warning', $result['severity']);
        $this->assertContains('signedBy-mismatch', $result['discrepancies']);

    }//end testReconcileReportsSignedByMismatch()


    /**
     * reconcile reports not found on missing minutes.
     *
     * @return void
     */
    public function testReconcileReportsNotFound(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reconcile('a', 'b');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);

    }//end testReconcileReportsNotFound()


    /**
     * reportDiscrepancy updates the queue entry status and stores the note.
     *
     * @return void
     */
    public function testReportDiscrepancyMarksEntry(): void
    {
        $rows = [
            MultilingualReconciliationService::SCHEMA => [
                ['id' => 'q-1', 'status' => 'translated', 'minutesKoppeling' => 'min-1'],
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reportDiscrepancy('q-1', 'resolution-count-mismatch');

        $this->assertTrue($result['success']);
        $this->assertSame('discrepancy', $result['entry']['status']);
        $this->assertSame('resolution-count-mismatch', $result['entry']['reconciliationNotes']);

    }//end testReportDiscrepancyMarksEntry()


    /**
     * reportDiscrepancy reports not found for unknown entries.
     *
     * @return void
     */
    public function testReportDiscrepancyReportsNotFound(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->reportDiscrepancy('missing', 'note');
        $this->assertFalse($result['success']);

    }//end testReportDiscrepancyReportsNotFound()


}//end class
