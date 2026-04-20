<?php

/**
 * Unit tests for OverdueActionItemsJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\BackgroundJob
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\BackgroundJob;

use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OverdueActionItemsJob.
 *
 * Tests use a custom subclass that exposes the protected run() method.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class OverdueActionItemsJobTest extends TestCase
{

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ITimeFactory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $timeFactory;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container   = $this->createMock(ContainerInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->timeFactory->method('getTime')->willReturn(time());

    }//end setUp()

    /**
     * Test that ActionItems with a past dueDate are set to overdue.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testActionItemsWithPastDueDateAreSetToOverdue(): void
    {
        $overdueItem = [
            'id'         => 'item-overdue-1',
            'title'      => 'Overdue task',
            'taskStatus' => 'open',
            'dueDate'    => '2020-01-01T00:00:00Z',
        ];

        $overdueItemEntity  = $this->createObjectEntityMock($overdueItem);
        $openItemEntities   = [$overdueItemEntity];
        $activeItemEntities = [];

        $savedData = null;

        $objectService = $this->createObjectServiceMock(
            openItems: $openItemEntities,
            inProgressItems: $activeItemEntities,
            saveCallback: function (array $object) use (&$savedData): object {
                $savedData = $object;
                return $this->createObjectEntityMock($object);
            }
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob($this->timeFactory, $this->container, $this->logger);
        $this->invokeRun($job);

        self::assertNotNull($savedData);
        self::assertSame('overdue', $savedData['taskStatus']);
        self::assertSame('item-overdue-1', $savedData['id']);

    }//end testActionItemsWithPastDueDateAreSetToOverdue()

    /**
     * Test that completed ActionItems are not modified.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testCompletedActionItemsAreNotModified(): void
    {
        // No items in open or in-progress buckets — completed items are not returned.
        $objectService = $this->createObjectServiceMock(
            openItems: [],
            inProgressItems: [],
            saveCallback: function (): object {
                $this->fail('saveObject should not be called for completed items.');
                return $this->createObjectEntityMock([]);
            }
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob($this->timeFactory, $this->container, $this->logger);
        $this->invokeRun($job);

        // No assertion needed — the fail() inside saveCallback would trigger if it were called.
        self::assertTrue(true);

    }//end testCompletedActionItemsAreNotModified()

    /**
     * Test that ActionItems with no dueDate are not modified.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testActionItemsWithNoDueDateAreNotModified(): void
    {
        $noDueDateItem = [
            'id'         => 'item-no-due',
            'title'      => 'Task without due date',
            'taskStatus' => 'open',
            // No dueDate field.
        ];

        $noDueDateEntity = $this->createObjectEntityMock($noDueDateItem);

        $objectService = $this->createObjectServiceMock(
            openItems: [$noDueDateEntity],
            inProgressItems: [],
            saveCallback: function (): object {
                $this->fail('saveObject should not be called for items without dueDate.');
                return $this->createObjectEntityMock([]);
            }
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob($this->timeFactory, $this->container, $this->logger);
        $this->invokeRun($job);

        // Assert success — saveObject was not called.
        self::assertTrue(true);

    }//end testActionItemsWithNoDueDateAreNotModified()

    /**
     * Test that ActionItems with a future dueDate are not modified.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testActionItemsWithFutureDueDateAreNotModified(): void
    {
        $futureItem = [
            'id'         => 'item-future',
            'title'      => 'Task due in future',
            'taskStatus' => 'in-progress',
            'dueDate'    => '2099-12-31T00:00:00Z',
        ];

        $futureItemEntity = $this->createObjectEntityMock($futureItem);

        $objectService = $this->createObjectServiceMock(
            openItems: [],
            inProgressItems: [$futureItemEntity],
            saveCallback: function (): object {
                $this->fail('saveObject should not be called for future dueDate items.');
                return $this->createObjectEntityMock([]);
            }
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob($this->timeFactory, $this->container, $this->logger);
        $this->invokeRun($job);

        self::assertTrue(true);

    }//end testActionItemsWithFutureDueDateAreNotModified()

    /**
     * Test that the job handles missing OpenRegister gracefully (no exception thrown).
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testJobHandlesMissingOpenRegisterGracefully(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('OpenRegister not available'));

        $this->logger->expects($this->once())
            ->method('warning');

        $job = new OverdueActionItemsJob($this->timeFactory, $this->container, $this->logger);

        // Should not throw.
        $this->invokeRun($job);

        self::assertTrue(true);

    }//end testJobHandlesMissingOpenRegisterGracefully()

    /**
     * Helper: create a mock ObjectEntity-like object.
     *
     * @param array<string,mixed> $data Object data
     *
     * @return object
     */
    private function createObjectEntityMock(array $data): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject'])
            ->getMock();
        $mock->method('getObject')->willReturn($data);
        return $mock;

    }//end createObjectEntityMock()

    /**
     * Helper: create a mock ObjectService.
     *
     * @param array<int,object> $openItems       Items with taskStatus='open'
     * @param array<int,object> $inProgressItems Items with taskStatus='in-progress'
     * @param callable          $saveCallback    Callback invoked when saveObject is called
     *
     * @return object
     */
    private function createObjectServiceMock(
        array $openItems,
        array $inProgressItems,
        callable $saveCallback
    ): object {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'setRegister', 'setSchema', 'saveObject'])
            ->getMock();

        $objectService->method('findAll')
            ->willReturnCallback(
                function (array $config) use ($openItems, $inProgressItems): array {
                    $status = $config['filters']['taskStatus'] ?? '';
                    return match ($status) {
                        'open'        => $openItems,
                        'in-progress' => $inProgressItems,
                        default       => [],
                    };
                }
            );

        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use ($saveCallback): object {
                return $saveCallback($object);
            }
        );

        return $objectService;

    }//end createObjectServiceMock()

    /**
     * Invoke the protected run() method via reflection.
     *
     * @param OverdueActionItemsJob $job The job instance
     *
     * @return void
     */
    private function invokeRun(OverdueActionItemsJob $job): void
    {
        $reflection = new \ReflectionMethod($job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($job, null);

    }//end invokeRun()

}//end class
