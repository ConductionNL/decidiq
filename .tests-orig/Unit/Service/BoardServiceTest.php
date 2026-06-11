<?php

/**
 * Unit tests for BoardService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
 */
class BoardServiceTest extends TestCase
{


    /**
     * Build service backed by an in-memory boards map.
     *
     * @param array<string, array<string, mixed>> &$boards Map of boardId => board row
     *
     * @return BoardService
     */
    private function makeService(array &$boards): BoardService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$boards): array {
                return array_values($boards);
            }
        );
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$boards): ?ObjectEntity {
                if (isset($boards[(string) $id]) === false) {
                    return null;
                }

                $row    = $boards[(string) $id];
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$boards): ObjectEntity {
                $id = $uuid ?? ('board-'.(count($boards) + 1));
                $row = array_merge(['id' => $id], $object);
                $boards[$id] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new BoardService($container, $logger);

    }//end makeService()


    /**
     * create() requires a name.
     *
     * @return void
     */
    public function testCreateRequiresName(): void
    {
        $boards  = [];
        $service = $this->makeService($boards);

        $result = $service->create([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('name', $result['message']);

    }//end testCreateRequiresName()


    /**
     * create() rejects unknown board types.
     *
     * @return void
     */
    public function testCreateRejectsUnknownType(): void
    {
        $boards  = [];
        $service = $this->makeService($boards);

        $result = $service->create(['name' => 'Bad Board', 'type' => 'cabal']);

        $this->assertFalse($result['success']);

    }//end testCreateRejectsUnknownType()


    /**
     * create() persists a valid board.
     *
     * @return void
     */
    public function testCreatePersistsValidBoard(): void
    {
        $boards  = [];
        $service = $this->makeService($boards);

        $result = $service->create(
            [
                'name'            => 'RvC Acme',
                'type'            => 'raad-van-commissarissen',
                'governanceModel' => 'two-tier',
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $boards);

    }//end testCreatePersistsValidBoard()


    /**
     * update() merges into the existing row.
     *
     * @return void
     */
    public function testUpdateMergesExisting(): void
    {
        $boards  = [
            'b1' => ['id' => 'b1', 'name' => 'Initial', 'type' => 'raad-van-bestuur'],
        ];
        $service = $this->makeService($boards);

        $result = $service->update('b1', ['name' => 'Renamed']);

        $this->assertTrue($result['success']);
        $this->assertSame('Renamed', $boards['b1']['name']);
        $this->assertSame('raad-van-bestuur', $boards['b1']['type']);

    }//end testUpdateMergesExisting()


    /**
     * get() reports unknown board.
     *
     * @return void
     */
    public function testGetMissingBoard(): void
    {
        $boards  = [];
        $service = $this->makeService($boards);

        $result = $service->get('not-here');

        $this->assertFalse($result['success']);

    }//end testGetMissingBoard()


    /**
     * list() filters by type.
     *
     * @return void
     */
    public function testListFiltersByType(): void
    {
        $boards  = [
            'b1' => ['id' => 'b1', 'type' => 'raad-van-commissarissen'],
            'b2' => ['id' => 'b2', 'type' => 'audit-committee'],
        ];
        $service = $this->makeService($boards);

        $result = $service->list(['type' => 'audit-committee']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('b2', $result['boards'][0]['id']);

    }//end testListFiltersByType()


}//end class
