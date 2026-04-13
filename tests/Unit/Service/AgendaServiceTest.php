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
     * IUserSession is not injected so assertChairOrSecretary is skipped,
     * keeping existing tests focused on business logic without mocking
     * the full participant/role hierarchy.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

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

        // UserSession is null — assertChairOrSecretary is a no-op in all tests below.
        $this->service = new AgendaService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test publishAgenda throws when the agenda has no items (spec §1.1).
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishAgendaThrowsWhenNoItems(): void
    {
        $this->objectService->method('getObjects')
            ->willReturn([]);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Een agenda moet minimaal één agendapunt bevatten');

        $this->service->publishAgenda(meetingId: 'meeting-1');

    }//end testPublishAgendaThrowsWhenNoItems()

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
                'role'        => 'chair',
                'leftAt'      => null,
            ],
            [
                'owner'       => 'user-2',
                'displayName' => 'Bob',
                'role'        => 'member',
                'leftAt'      => null,
            ],
            [
                'owner'       => 'user-3',
                'displayName' => 'Charlie',
                'role'        => 'member',
                'leftAt'      => '2025-01-01',
            ],
        ];

        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($agendaItems, $participants) {
                    // Argument order after fix: getObjects($register, $schema, $filters).
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item'  => $agendaItems,
                        'participant'  => $participants,
                        default        => [],
                    };
                }
            );

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->notificationService->expects($this->exactly(count: 2))
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
            // No 'meeting' field — auth check is skipped automatically.
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

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Informatieve agendapunten hebben geen BOB-fasering');

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

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Agendapunt is al in de laatste fase');

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

        // GetObject is called for the meeting during the auth check.
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'meeting-1', 'relations' => []]);

        // GetObjects is called for participants (returns [] — empty governance body)
        // and for agenda-items (returns hamerstukken).
        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($hamerstukken) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item' => $hamerstukken,
                        default       => [],
                    };
                }
            );

        $this->objectService->expects($this->exactly(count: 2))
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

        // GetObject is called for the meeting during the auth check.
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'meeting-1', 'relations' => []]);

        // GetObjects: participants (empty governance body) and agenda-items.
        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($items) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item' => $items,
                        default       => [],
                    };
                }
            );

        // Reversed order — item-c first.
        $orderedIds = ['item-c', 'item-a', 'item-b'];

        $savedItems = [];
        $this->objectService->expects($this->exactly(count: 3))
            ->method('saveObject')
            ->willReturnCallback(
                static function () use (&$savedItems) {
                    $args         = func_get_args();
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
        $mock = $this->getMockBuilder(className: \stdClass::class)
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
        $mock = $this->getMockBuilder(className: \stdClass::class)
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
        $mock = $this->getMockBuilder(className: \stdClass::class)
            ->addMethods(['updateEvent'])
            ->getMock();

        return $mock;

    }//end createMockCalendarEventService()
}//end class
