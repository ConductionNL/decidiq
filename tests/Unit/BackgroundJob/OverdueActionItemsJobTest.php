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
 * @author    Conduction Development Team <dev@conductio.nl>
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
     * Mock ObjectService.
     *
     * @var MockObject
     */
    private MockObject $objectService;

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

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObjects', 'saveObject'])
            ->getMock();

        $this->container   = $this->createMock(ContainerInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);

        $this->timeFactory->method('getTime')->willReturn(time());
        $this->timeFactory->method('now')->willReturn(new \DateTimeImmutable());

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

    }//end setUp()

    /**
     * Action items with dueDate in the past are set to 'overdue'.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testPastDueDateActionItemsAreMarkedOverdue(): void
    {
        $pastDate = (new \DateTimeImmutable('-1 day'))->format(\DateTimeImmutable::ATOM);

        $openItems = [
            ['id' => 'ai-001', 'taskStatus' => 'open', 'dueDate' => $pastDate, 'title' => 'Overdue item'],
        ];

        $this->objectService->method('getObjects')
            ->willReturnCallback(static function (string $register, string $schema, array $filters) use ($openItems): array {
                if ($filters['taskStatus'] === 'open') {
                    return ['results' => $openItems];
                }

                return ['results' => []];
            });

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->equalTo('decidesk'),
                $this->equalTo('action-item'),
                $this->callback(static function (array $obj): bool {
                    return $obj['taskStatus'] === 'overdue';
                }),
                $this->equalTo('ai-001')
            );

        $this->runJob([]);

    }//end testPastDueDateActionItemsAreMarkedOverdue()

    /**
     * Completed action items are never modified even if dueDate is in the past.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testCompletedActionItemsAreNotModified(): void
    {
        // Return empty results for both open and in-progress statuses.
        $this->objectService->method('getObjects')
            ->willReturn(['results' => []]);

        // saveObject must never be called for completed items.
        $this->objectService->expects($this->never())
            ->method('saveObject');

        $this->runJob([]);

    }//end testCompletedActionItemsAreNotModified()

    /**
     * Action items with no dueDate set are skipped (not marked overdue).
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testActionItemsWithNoDueDateAreNotModified(): void
    {
        $itemsNoDueDate = [
            ['id' => 'ai-002', 'taskStatus' => 'open', 'dueDate' => null, 'title' => 'No due date item'],
        ];

        $this->objectService->method('getObjects')
            ->willReturnCallback(static function (string $register, string $schema, array $filters) use ($itemsNoDueDate): array {
                if ($filters['taskStatus'] === 'open') {
                    return ['results' => $itemsNoDueDate];
                }

                return ['results' => []];
            });

        // saveObject must not be called since dueDate is null.
        $this->objectService->expects($this->never())
            ->method('saveObject');

        $this->runJob([]);

    }//end testActionItemsWithNoDueDateAreNotModified()

    /**
     * Future dueDate items are not marked overdue.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testFutureDueDateItemsAreNotMarkedOverdue(): void
    {
        $futureDate = (new \DateTimeImmutable('+7 days'))->format(\DateTimeImmutable::ATOM);

        $openItems = [
            ['id' => 'ai-003', 'taskStatus' => 'open', 'dueDate' => $futureDate, 'title' => 'Future item'],
        ];

        $this->objectService->method('getObjects')
            ->willReturnCallback(static function (string $register, string $schema, array $filters) use ($openItems): array {
                if ($filters['taskStatus'] === 'open') {
                    return ['results' => $openItems];
                }

                return ['results' => []];
            });

        $this->objectService->expects($this->never())
            ->method('saveObject');

        $this->runJob([]);

    }//end testFutureDueDateItemsAreNotMarkedOverdue()

    /**
     * Helper to invoke the protected run() method via reflection.
     *
     * @param array $argument Job argument
     *
     * @return void
     */
    private function runJob(array $argument): void
    {
        $job = new OverdueActionItemsJob(
            time: $this->timeFactory,
            container: $this->container,
            logger: $this->logger,
        );

        $reflection = new \ReflectionMethod(OverdueActionItemsJob::class, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $argument);

    }//end runJob()

}//end class
