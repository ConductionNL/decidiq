<?php

/**
 * Unit tests for the MigrateTasksToActionItems repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\MigrateTasksToActionItems;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the idempotent, resume-safe legacy-task migration.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
 */
class MigrateTasksToActionItemsTest extends TestCase
{

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock migration output.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Captured deleteObject() calls as "schema:uuid".
     *
     * @var array<int, string>
     */
    private array $archived = [];

    /**
     * Captured saveObject() payloads.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

    /**
     * Set up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->archived      = [];
        $this->saved         = [];
        $this->objectService = $this->createMock(ObjectService::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->output        = $this->createMock(IOutput::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) {
                $this->saved[] = $object;
                $object['uuid'] = $object['uuid'] ?? ('new-'.count($this->saved));
                return $object;
            }
        );

        $this->objectService->method('deleteObject')->willReturnCallback(
            function (string $uuid, string|int|null $register=null, string|int|null $schema=null) {
                $this->archived[] = $schema.':'.$uuid;
                return true;
            }
        );

    }//end setUp()

    /**
     * Build an ObjectEntity mock.
     *
     * @param array<string, mixed> $data Object data
     *
     * @return ObjectEntity&MockObject
     */
    private function entity(array $data): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn($data);
        return $entity;

    }//end entity()

    /**
     * Wire findAll() to return per-schema fixtures keyed by the schema filter.
     *
     * @param array<string, array<int, array<string, mixed>>> $bySchema Fixtures
     *
     * @return void
     */
    private function wireFindAll(array $bySchema): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($bySchema) {
                $schema = (string) ($config['filters']['schema'] ?? '');
                $rows   = ($bySchema[$schema] ?? []);
                return array_map(fn(array $r): ObjectEntity => $this->entity($r), $rows);
            }
        );

    }//end wireFindAll()

    /**
     * A legacy task is projected to an action item and archived.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    public function testTaskIsProjectedAndArchived(): void
    {
        $this->wireFindAll(
            [
                'action-item' => [],
                'task'        => [['uuid' => 'task-1', 'title' => 'Do it', 'assignee' => 'alice', 'taskStatus' => 'pending']],
                'delegation'  => [],
            ]
        );

        (new MigrateTasksToActionItems($this->container, $this->createMock(LoggerInterface::class)))
            ->run($this->output);

        self::assertCount(1, $this->saved);
        self::assertSame('Do it', $this->saved[0]['title']);
        self::assertSame('open', $this->saved[0]['taskStatus']);
        self::assertSame('task-1', $this->saved[0]['migratedFromTaskUuid']);
        self::assertContains('task:task-1', $this->archived);

    }//end testTaskIsProjectedAndArchived()

    /**
     * A re-run does not duplicate an already-migrated task's action item.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    public function testMigrationIsIdempotent(): void
    {
        $this->wireFindAll(
            [
                // The action item already carries the migration marker.
                'action-item' => [['uuid' => 'ai-1', 'migratedFromTaskUuid' => 'task-1']],
                'task'        => [['uuid' => 'task-1', 'title' => 'Do it', 'taskStatus' => 'pending']],
                'delegation'  => [],
            ]
        );

        (new MigrateTasksToActionItems($this->container, $this->createMock(LoggerInterface::class)))
            ->run($this->output);

        self::assertCount(0, $this->saved, 'No new action item should be created on re-run');
        self::assertNotContains('task:task-1', $this->archived, 'Already-migrated task is skipped');

    }//end testMigrationIsIdempotent()

    /**
     * An active delegation is archived (its semantics live on the action item).
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     */
    public function testDelegationIsArchived(): void
    {
        $this->wireFindAll(
            [
                'action-item' => [],
                'task'        => [],
                'delegation'  => [['uuid' => 'del-1', 'taskUid' => 'task-x', 'status' => 'revoked']],
            ]
        );

        (new MigrateTasksToActionItems($this->container, $this->createMock(LoggerInterface::class)))
            ->run($this->output);

        self::assertContains('delegation:del-1', $this->archived);

    }//end testDelegationIsArchived()
}//end class
