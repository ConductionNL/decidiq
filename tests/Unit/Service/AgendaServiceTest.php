<?php

/**
 * Unit tests for AgendaService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AgendaService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaService.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
 */
class AgendaServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var AgendaService
     */
    private AgendaService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock object service.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Mock notification service.
     *
     * @var object&MockObject
     */
    private object $notificationService;

    /**
     * Mock calendar event service.
     *
     * @var object&MockObject
     */
    private object $calendarEventService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container  = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger     = $this->createMock(originalClassName: LoggerInterface::class);

        $this->objectService        = $this->createMockObjectService();
        $this->notificationService  = $this->createMockNotificationService();
        $this->calendarEventService = $this->createMockCalendarEventService();

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) {
                    return match ($id) {
                        'OCA\OpenRegister\Service\ObjectService'        => $this->objectService,
                        'OCA\OpenRegister\Service\NotificationService'  => $this->notificationService,
                        'OCA\OpenRegister\Service\CalendarEventService' => $this->calendarEventService,
                        default => throw new \RuntimeException('Unknown service: '.$id),
                    };
                }
            );

        $this->service = new AgendaService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test publishAgenda returns zero notifications when no items exist.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishAgendaWithNoItemsReturnsZeroNotifications(): void
    {
        $this->objectService->method('getObjects')
            ->willReturn([]);

        $result = $this->service->publishAgenda(meetingId: 'meeting-1');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 0, actual: $result['notifications']);

    }//end testPublishAgendaWithNoItemsReturnsZeroNotifications()

    /**
     * Test publishAgenda sends notifications to active participants only.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishAgendaSendsNotificationsToActiveParticipants(): void
    {
        $agendaItems = [
            [
                'id'    => 'item-1',
                'title' => 'Opening',
            ],
        ];

        $meeting = [
            'id'            => 'meeting-1',
            'title'         => 'Raadsvergadering',
            'scheduledDate' => '2025-04-14',
            'relations'     => [
                [
                    'schema' => 'governance-body',
                    'id'     => 'gb-1',
                ],
            ],
        ];

        $participants = [
            [
                'owner'       => 'user-1',
                'displayName' => 'Alice',
                'leftAt'      => null,
            ],
            [
                'owner'       => 'user-2',
                'displayName' => 'Bob',
                'leftAt'      => null,
            ],
            [
                'owner'       => 'user-3',
                'displayName' => 'Charlie',
                'leftAt'      => '2025-01-01',
            ],
        ];

        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($agendaItems, $participants) {
                    $schema = func_get_arg(0);
                    return match ($schema) {
                        'agenda-item'  => $agendaItems,
                        'participant'  => $participants,
                        default        => [],
                    };
                }
            );

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->notificationService->expects($this->exactly(2))
            ->method('sendNotification');

        $result = $this->service->publishAgenda(meetingId: 'meeting-1');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 2, actual: $result['notifications']);

    }//end testPublishAgendaSendsNotificationsToActiveParticipants()

    /**
     * Test advanceBobPhase cycles through phases correctly.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testAdvanceBobPhaseCyclesCorrectly(): void
    {
        $item = [
            'id'       => 'item-1',
            'itemType' => 'discussion',
            'status'   => 'beeldvorming',
        ];

        $this->objectService->method('getObject')
            ->willReturn($item);

        $this->objectService->expects($this->once())
            ->method('saveObject');

        $result = $this->service->advanceBobPhase(agendaItemId: 'item-1');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'beeldvorming', actual: $result['previousPhase']);
        self::assertSame(expected: 'oordeelsvorming', actual: $result['currentPhase']);

    }//end testAdvanceBobPhaseCyclesCorrectly()

    /**
     * Test advanceBobPhase throws for informational items.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testAdvanceBobPhaseThrowsForInformationalItems(): void
    {
        $item = [
            'id'       => 'item-1',
            'itemType' => 'informational',
            'status'   => 'beeldvorming',
        ];

        $this->objectService->method('getObject')
            ->willReturn($item);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Informatieve agendapunten hebben geen BOB-fasering');

        $this->service->advanceBobPhase(agendaItemId: 'item-1');

    }//end testAdvanceBobPhaseThrowsForInformationalItems()

    /**
     * Test advanceBobPhase throws when already at final phase.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testAdvanceBobPhaseThrowsAtFinalPhase(): void
    {
        $item = [
            'id'       => 'item-1',
            'itemType' => 'decision',
            'status'   => 'afgerond',
        ];

        $this->objectService->method('getObject')
            ->willReturn($item);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agendapunt is al in de laatste fase');

        $this->service->advanceBobPhase(agendaItemId: 'item-1');

    }//end testAdvanceBobPhaseThrowsAtFinalPhase()

    /**
     * Test processHamerstukken updates all tagged items.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testProcessHamerstukkenUpdatesAllTaggedItems(): void
    {
        $hamerstukken = [
            [
                'id'     => 'item-1',
                'title'  => 'Notulen',
                'tags'   => ['hamerstuk'],
                'status' => 'beeldvorming',
            ],
            [
                'id'     => 'item-2',
                'title'  => 'Jaarverslag',
                'tags'   => ['hamerstuk'],
                'status' => 'beeldvorming',
            ],
        ];

        $this->objectService->method('getObjects')
            ->willReturn($hamerstukken);

        $this->objectService->expects($this->exactly(2))
            ->method('saveObject');

        $result = $this->service->processHamerstukken(meetingId: 'meeting-1');

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 2, actual: $result['count']);

    }//end testProcessHamerstukkenUpdatesAllTaggedItems()

    /**
     * Test reorderItems assigns sequential numbers.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testReorderItemsAssignsSequentialNumbers(): void
    {
        $items = [
            [
                'id'          => 'item-a',
                'orderNumber' => 1,
            ],
            [
                'id'          => 'item-b',
                'orderNumber' => 2,
            ],
            [
                'id'          => 'item-c',
                'orderNumber' => 3,
            ],
        ];

        $this->objectService->method('getObjects')
            ->willReturn($items);

        // Reversed order.
        $orderedIds = ['item-c', 'item-a', 'item-b'];

        $savedItems = [];
        $this->objectService->expects($this->exactly(3))
            ->method('saveObject')
            ->willReturnCallback(
                static function () use (&$savedItems) {
                    $args = func_get_args();
                    $savedItems[] = $args;
                }
            );

        $result = $this->service->reorderItems(
            meetingId: 'meeting-1',
            orderedIds: $orderedIds
        );

        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 3, actual: $result['count']);

    }//end testReorderItemsAssignsSequentialNumbers()

    /**
     * Create a mock ObjectService.
     *
     * @return object&MockObject
     */
    private function createMockObjectService(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(
                [
                    'getObjects',
                    'getObject',
                    'saveObject',
                ]
            )
            ->getMock();

        return $mock;

    }//end createMockObjectService()

    /**
     * Create a mock NotificationService.
     *
     * @return object&MockObject
     */
    private function createMockNotificationService(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['sendNotification'])
            ->getMock();

        return $mock;

    }//end createMockNotificationService()

    /**
     * Create a mock CalendarEventService.
     *
     * @return object&MockObject
     */
    private function createMockCalendarEventService(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['updateEvent'])
            ->getMock();

        return $mock;

    }//end createMockCalendarEventService()
}//end class
