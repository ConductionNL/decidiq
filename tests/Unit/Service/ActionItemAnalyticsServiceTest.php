<?php

/**
 * Unit tests for ActionItemAnalyticsService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ActionItemAnalyticsService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
 */
class ActionItemAnalyticsServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ActionItemAnalyticsService
     */
    private ActionItemAnalyticsService $service;

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
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new ActionItemAnalyticsService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test getSummary returns correct overdue count.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     *
     * @return void
     */
    public function testGetSummaryReturnsCorrectOverdueCount(): void
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'id' => '1',
                        'title' => 'Overdue task',
                        'taskStatus' => 'open',
                        'dueDate' => $yesterday,
                    ],
                    [
                        'id' => '2',
                        'title' => 'Future task',
                        'taskStatus' => 'open',
                        'dueDate' => $tomorrow,
                    ],
                    [
                        'id' => '3',
                        'title' => 'Completed task',
                        'taskStatus' => 'completed',
                        'dueDate' => $yesterday,
                        'completedAt' => date('c'),
                        'createdAt' => date('c', strtotime('-5 days')),
                    ],
                ],
            ]);

        $summary = $this->service->getSummary('', '');

        $this->assertEquals($summary['totalOpen'], 2);
        $this->assertEquals($summary['totalOverdue'], 1);
    }//end testGetSummaryReturnsCorrectOverdueCount()

    /**
     * Test getCompletionRates returns 0% for meetings with no completed items.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     *
     * @return void
     */
    public function testGetCompletionRatesReturnsZeroPercentForNoCompletedItems(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                // First call: get meetings
                [
                    'results' => [
                        [
                            'id' => 'meeting-1',
                            '@self' => ['slug' => 'meeting-1'],
                            'title' => 'Test Meeting',
                        ],
                    ],
                ],
                // Second call: get action items for meeting
                [
                    'results' => [
                        [
                            'id' => 'item-1',
                            'taskStatus' => 'open',
                        ],
                        [
                            'id' => 'item-2',
                            'taskStatus' => 'open',
                        ],
                    ],
                ]
            );

        $rates = $this->service->getCompletionRates(1);

        $this->assertIsArray($rates);
        $this->assertGreaterThan(0, count($rates));
        $this->assertEquals($rates[0]['completionRate'], 0.0);
        $this->assertEquals($rates[0]['total'], 2);
    }//end testGetCompletionRatesReturnsZeroPercentForNoCompletedItems()

    /**
     * Test getMyItems groups overdue items correctly.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     *
     * @return void
     */
    public function testGetMyItemsGroupsOverdueItemsCorrectly(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $inThreeDays = date('Y-m-d', strtotime('+3 days'));
        $inTenDays = date('Y-m-d', strtotime('+10 days'));

        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'id' => 'item-1',
                        'title' => 'Overdue',
                        'dueDate' => $yesterday,
                        'taskStatus' => 'open',
                    ],
                    [
                        'id' => 'item-2',
                        'title' => 'This week',
                        'dueDate' => $inThreeDays,
                        'taskStatus' => 'open',
                    ],
                    [
                        'id' => 'item-3',
                        'title' => 'Later',
                        'dueDate' => $inTenDays,
                        'taskStatus' => 'open',
                    ],
                ],
            ]);

        $items = $this->service->getMyItems('John Doe');

        $this->assertEquals(count($items['overdue']), 1);
        $this->assertEquals(count($items['thisWeek']), 1);
        $this->assertEquals(count($items['later']), 1);
    }//end testGetMyItemsGroupsOverdueItemsCorrectly()

    /**
     * Test avgDaysToClose calculation.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.5
     *
     * @return void
     */
    public function testAvgDaysToCloseCalculation(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'id' => '1',
                        'taskStatus' => 'completed',
                        'createdAt' => $now->modify('-10 days')->format('c'),
                        'completedAt' => $now->modify('+3 days')->format('c'),
                    ],
                    [
                        'id' => '2',
                        'taskStatus' => 'completed',
                        'createdAt' => $now->modify('-5 days')->format('c'),
                        'completedAt' => $now->modify('+2 days')->format('c'),
                    ],
                ],
            ]);

        $summary = $this->service->getSummary('', '');

        $this->assertGreaterThan($summary['avgDaysToClose'], 0);
        $this->assertIsFloat($summary['avgDaysToClose']);
    }//end testAvgDaysToCloseCalculation()
}//end class
