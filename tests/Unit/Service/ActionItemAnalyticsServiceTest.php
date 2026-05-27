<?php

/**
 * Test Suite for ActionItemAnalyticsService
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ActionItemAnalyticsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ActionItemAnalyticsService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
 */
class ActionItemAnalyticsServiceTest extends TestCase
{
    private ActionItemAnalyticsService $service;
    private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->service   = new ActionItemAnalyticsService($this->container, $this->logger);
    }

    /**
     * Build a mock ObjectEntity returning $data from jsonSerialize().
     *
     * @param array<string,mixed> $data
     *
     * @return object
     */
    private function makeEntity(array $data): object
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;
    }

    /**
     * Build an ObjectService mock with setRegister/setSchema/findAll/saveObject.
     *
     * @param array<int,object> $findAllReturn
     *
     * @return \OCA\OpenRegister\Service\ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeObjectService(array $findAllReturn): object
    {
        $svc = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $svc->method('setRegister')->willReturnSelf();
        $svc->method('setSchema')->willReturnSelf();
        $svc->method('findAll')->willReturn($findAllReturn);
        return $svc;
    }

    /**
     * Test that getSummary returns correct overdue count.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     */
    public function testGetSummaryReturnsCorrectOverdueCount(): void
    {
        $items = [
            $this->makeEntity([
                'id'         => 'item-1',
                'taskStatus' => 'open',
                'dueDate'    => date('Y-m-d', strtotime('-5 days')),
                'createdAt'  => date('Y-m-d', strtotime('-10 days')),
            ]),
            $this->makeEntity([
                'id'         => 'item-2',
                'taskStatus' => 'open',
                'dueDate'    => date('Y-m-d', strtotime('+5 days')),
                'createdAt'  => date('Y-m-d', strtotime('-10 days')),
            ]),
        ];

        $mockObjectService = $this->makeObjectService($items);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->getSummary('2026-01-01', '2026-12-31');

        $this->assertIsArray($result);
        $this->assertEquals(2, $result['totalOpen']);
        $this->assertEquals(1, $result['totalOverdue']);
    }

    /**
     * Test that getCompletionRates returns 0% for meetings with no completed items.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     */
    public function testGetCompletionRatesReturnsZeroPercentForNoCompletedItems(): void
    {
        $meetingEntities = [
            $this->makeEntity([
                'id'        => 'meeting-1',
                'title'     => 'Council Meeting',
                'relations' => [
                    'ActionItem' => [
                        ['taskStatus' => 'open'],
                        ['taskStatus' => 'in-progress'],
                    ],
                ],
            ]),
        ];

        $mockObjectService = $this->makeObjectService($meetingEntities);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->getCompletionRates(6);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['completionRate']);
        $this->assertEquals(2, $result[0]['total']);
    }

    /**
     * Test that getMyItems groups overdue items correctly.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     */
    public function testGetMyItemsGroupsOverdueItemsCorrectly(): void
    {
        $items = [
            $this->makeEntity([
                'id'         => 'item-1',
                'title'      => 'Overdue Task',
                'assignee'   => 'John Doe',
                'taskStatus' => 'open',
                'dueDate'    => date('Y-m-d', strtotime('-3 days')),
            ]),
            $this->makeEntity([
                'id'         => 'item-2',
                'title'      => 'This Week Task',
                'assignee'   => 'John Doe',
                'taskStatus' => 'open',
                'dueDate'    => date('Y-m-d', strtotime('+3 days')),
            ]),
            $this->makeEntity([
                'id'         => 'item-3',
                'title'      => 'Later Task',
                'assignee'   => 'John Doe',
                'taskStatus' => 'open',
                'dueDate'    => date('Y-m-d', strtotime('+20 days')),
            ]),
        ];

        $mockObjectService = $this->makeObjectService($items);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->getMyItems('John Doe');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['overdue']);
        $this->assertCount(1, $result['thisWeek']);
        $this->assertCount(1, $result['later']);
    }

    /**
     * Test that avgDaysToClose calculates the correct average.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     */
    public function testGetSummaryCalculatesAverageDaysToCloseCorrectly(): void
    {
        $items = [
            $this->makeEntity([
                'id'          => 'item-1',
                'taskStatus'  => 'completed',
                'createdAt'   => date('Y-m-d', strtotime('-10 days')),
                'completedAt' => date('Y-m-d'),
            ]),
            $this->makeEntity([
                'id'          => 'item-2',
                'taskStatus'  => 'completed',
                'createdAt'   => date('Y-m-d', strtotime('-5 days')),
                'completedAt' => date('Y-m-d'),
            ]),
        ];

        $mockObjectService = $this->makeObjectService($items);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->getSummary('2026-01-01', '2026-12-31');

        $this->assertIsArray($result);
        // Average of 10 and 5 is 7.5
        $this->assertEquals(7.5, $result['avgDaysToClose']);
    }
}
