<?php

/**
 * Unit tests for ActionItemDelegationService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidesk\Service\ActionItemDelegationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests delegation/reclaim semantics mapped onto the action-item object.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 */
class ActionItemDelegationServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var ActionItemDelegationService
     */
    private ActionItemDelegationService $service;

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
     * Set up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $logger              = $this->createMock(LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new ActionItemDelegationService(
            container: $this->container,
            logger: $logger,
        );

    }//end setUp()

    /**
     * Build an ObjectEntity mock returning the given action-item array.
     *
     * @param array<string, mixed> $item Action-item data
     *
     * @return ObjectEntity&MockObject
     */
    private function makeEntity(array $item): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn($item);
        return $entity;

    }//end makeEntity()

    /**
     * Reassigning records the original owner as delegator and sets the new assignee.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function testReassignSetsDelegatorAndAssignee(): void
    {
        $item = ['uuid' => 'ai-1', 'title' => 'Plan', 'assignee' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));

        $captured = null;
        $this->objectService->expects(self::once())->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$captured) {
                    $captured = $object;
                    return $object;
                }
            );

        $result = $this->service->reassign('ai-1', 'bob', 'alice');

        self::assertSame('bob', $result['assignee']);
        self::assertSame('alice', $result['delegator']);
        self::assertSame('bob', $captured['assignee']);

    }//end testReassignSetsDelegatorAndAssignee()

    /**
     * A non-owner / non-delegator caller cannot reassign (OWASP A01).
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function testReassignByOutsiderIsRejected(): void
    {
        $item = ['uuid' => 'ai-1', 'assignee' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));
        $this->objectService->expects(self::never())->method('saveObject');

        $this->expectException(InvalidArgumentException::class);
        $this->service->reassign('ai-1', 'bob', 'carol');

    }//end testReassignByOutsiderIsRejected()

    /**
     * An invalid substituteUntil is rejected before any write.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function testReassignRejectsNonIsoSubstituteUntil(): void
    {
        $item = ['uuid' => 'ai-1', 'assignee' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));
        $this->objectService->expects(self::never())->method('saveObject');

        $this->expectException(InvalidArgumentException::class);
        $this->service->reassign('ai-1', 'bob', 'alice', 'next monday');

    }//end testReassignRejectsNonIsoSubstituteUntil()

    /**
     * Reclaim reverts the assignee to the delegator and stamps the time.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.3
     */
    public function testReclaimRevertsAssigneeToDelegator(): void
    {
        $item = ['uuid' => 'ai-1', 'assignee' => 'bob', 'delegator' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));
        $this->objectService->expects(self::once())->method('saveObject')->willReturnArgument(0);

        $result = $this->service->reclaim('ai-1', 'alice');

        self::assertSame('alice', $result['assignee']);
        self::assertArrayHasKey('reclaimedAt', $result);
        self::assertNull($result['substituteUntil']);

    }//end testReclaimRevertsAssigneeToDelegator()

    /**
     * Only the original delegator may reclaim.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.3
     */
    public function testReclaimByNonDelegatorIsRejected(): void
    {
        $item = ['uuid' => 'ai-1', 'assignee' => 'bob', 'delegator' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));
        $this->objectService->expects(self::never())->method('saveObject');

        $this->expectException(InvalidArgumentException::class);
        $this->service->reclaim('ai-1', 'bob');

    }//end testReclaimByNonDelegatorIsRejected()

    /**
     * Reclaiming a never-delegated item is rejected.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.3
     */
    public function testReclaimUndelegatedItemIsRejected(): void
    {
        $item = ['uuid' => 'ai-1', 'assignee' => 'alice', 'taskStatus' => 'open'];
        $this->objectService->method('find')->willReturn($this->makeEntity($item));
        $this->objectService->expects(self::never())->method('saveObject');

        $this->expectException(InvalidArgumentException::class);
        $this->service->reclaim('ai-1', 'alice');

    }//end testReclaimUndelegatedItemIsRejected()

    /**
     * A missing action item raises a RuntimeException.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function testReassignMissingItemThrows(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->reassign('missing', 'bob', 'alice');

    }//end testReassignMissingItemThrows()
}//end class
