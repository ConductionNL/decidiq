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
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
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
     * Mock IL10N.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

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
     * Mock IUserSession for the chair user (userId = 'user-1').
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $chairUserSession;

    /**
     * Set up test fixtures.
     *
     * IUserSession is injected with a chair user (userId = 'user-1') so that
     * assertChairOrSecretary passes whenever participants include 'user-1'.
     * Tests that validate the 403 path create their own service instance.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
        $this->l10n      = $this->createMock(originalClassName: IL10N::class);
        $this->l10n->method('t')->willReturnCallback(static fn(string $text) => $text);

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

        // Inject a chair user so assertChairOrSecretary does not silently bypass.
        $mockUser               = $this->createMock(originalClassName: IUser::class);
        $mockUser->method('getUID')->willReturn('user-1');
        $this->chairUserSession = $this->createMock(originalClassName: IUserSession::class);
        $this->chairUserSession->method('getUser')->willReturn($mockUser);

        $this->service = new AgendaService(
            container: $this->container,
            logger: $this->logger,
            l10n: $this->l10n,
            userSession: $this->chairUserSession,
        );

    }//end setUp()

    /**
     * Test publishAgenda throws when the agenda has no items (spec §1.1).
     *
     * Auth check runs first (info-disclosure fix), so the meeting must have a
     * governance body with 'user-1' as chair before the item count is checked.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishAgendaThrowsWhenNoItems(): void
    {
        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                [
                    'schema' => 'governance-body',
                    'id'     => 'gb-1',
                ],
            ],
        ];

        $chairParticipant = [
            [
                'owner'  => 'user-1',
                'role'   => 'chair',
                'leftAt' => null,
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($meeting);

        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($chairParticipant) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'participant' => $chairParticipant,
                        default       => [],
                    };
                }
            );

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
     * Test advanceBobPhase throws RuntimeException when item is not found.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testAdvanceBobPhaseThrowsWhenItemNotFound(): void
    {
        $this->objectService->method('getObject')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Agenda item not found');

        $this->service->advanceBobPhase(agendaItemId: 'nonexistent-id');

    }//end testAdvanceBobPhaseThrowsWhenItemNotFound()

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
            'meeting'  => 'meeting-1',
        ];

        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                [
                    'schema' => 'governance-body',
                    'id'     => 'gb-1',
                ],
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                static function () use ($item, $meeting) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item' => $item,
                        default       => $meeting,
                    };
                }
            );

        $this->objectService->method('getObjects')
            ->willReturn(
                [
                    [
                        'owner'  => 'user-1',
                        'role'   => 'chair',
                        'leftAt' => null,
                    ],
                ]
            );

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
            'meeting'  => 'meeting-1',
        ];

        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                [
                    'schema' => 'governance-body',
                    'id'     => 'gb-1',
                ],
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                static function () use ($item, $meeting) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item' => $item,
                        default       => $meeting,
                    };
                }
            );

        $this->objectService->method('getObjects')
            ->willReturn(
                [
                    [
                        'owner'  => 'user-1',
                        'role'   => 'chair',
                        'leftAt' => null,
                    ],
                ]
            );

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
            'meeting'  => 'meeting-1',
        ];

        $meeting = [
            'id'        => 'meeting-1',
            'relations' => [
                [
                    'schema' => 'governance-body',
                    'id'     => 'gb-1',
                ],
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(
                static function () use ($item, $meeting) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'agenda-item' => $item,
                        default       => $meeting,
                    };
                }
            );

        $this->objectService->method('getObjects')
            ->willReturn(
                [
                    [
                        'owner'  => 'user-1',
                        'role'   => 'chair',
                        'leftAt' => null,
                    ],
                ]
            );

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
            ->willReturn(
                [
                    'id'        => 'meeting-1',
                    'relations' => [
                        [
                            'schema' => 'governance-body',
                            'id'     => 'gb-1',
                        ],
                    ],
                ]
            );

        // GetObjects: participants (with 'user-1' as chair) and agenda-items (hamerstukken).
        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($hamerstukken) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'participant' => [
                            [
                                'owner'  => 'user-1',
                                'role'   => 'chair',
                                'leftAt' => null,
                            ],
                        ],
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
            ->willReturn(
                [
                    'id'        => 'meeting-1',
                    'relations' => [
                        [
                            'schema' => 'governance-body',
                            'id'     => 'gb-1',
                        ],
                    ],
                ]
            );

        // GetObjects: participants (with 'user-1' as chair) and agenda-items.
        $this->objectService->method('getObjects')
            ->willReturnCallback(
                static function () use ($items) {
                    $schema = func_get_arg(1);
                    return match ($schema) {
                        'participant' => [
                            [
                                'owner'  => 'user-1',
                                'role'   => 'chair',
                                'leftAt' => null,
                            ],
                        ],
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
     * Test assertChairOrSecretary throws 403 for a non-chair user.
     *
     * Injects a mock IUserSession that returns userId 'non-chair-user' and
     * a participant list where that user has role 'member', verifying that
     * the real authorization logic raises RuntimeException(code=403).
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishAgendaThrowsWith403ForNonChairUser(): void
    {
        $mockUser = $this->createMock(originalClassName: IUser::class);
        $mockUser->method('getUID')->willReturn('non-chair-user');

        $memberSession = $this->createMock(originalClassName: IUserSession::class);
        $memberSession->method('getUser')->willReturn($mockUser);

        $container = $this->createMock(originalClassName: ContainerInterface::class);

        $objectService = $this->createMockObjectService();
        $container->method('get')
            ->willReturnCallback(
                function (string $id) use ($objectService) {
                    return match ($id) {
                        'OCA\OpenRegister\Service\ObjectService'        => $objectService,
                        'OCA\OpenRegister\Service\NotificationService'  => $this->notificationService,
                        'OCA\OpenRegister\Service\CalendarEventService' => $this->calendarEventService,
                        default => throw new \RuntimeException('Unknown service: '.$id),
                    };
                }
            );

        $objectService->method('getObject')
            ->willReturn(
                [
                    'id'        => 'meeting-1',
                    'relations' => [
                        [
                            'schema' => 'governance-body',
                            'id'     => 'gb-1',
                        ],
                    ],
                ]
            );

        // Participants list has 'non-chair-user' as a plain member — not chair or secretary.
        $objectService->method('getObjects')
            ->willReturn(
                [
                    [
                        'owner'  => 'non-chair-user',
                        'role'   => 'member',
                        'leftAt' => null,
                    ],
                ]
            );

        $service = new AgendaService(
            container: $container,
            logger: $this->logger,
            l10n: $this->l10n,
            userSession: $memberSession,
        );

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionCode(code: 403);

        $service->publishAgenda(meetingId: 'meeting-1');

    }//end testPublishAgendaThrowsWith403ForNonChairUser()

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
