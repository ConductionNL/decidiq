<?php

/**
 * Unit tests for AgendaService.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AgendaService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaService.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */
class AgendaServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var AgendaService
     */
    private AgendaService $service;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock CalendarEventService.
     *
     * @var CalendarEventService&MockObject
     */
    private CalendarEventService&MockObject $calendarEventService;

    /**
     * Mock INotificationManager.
     *
     * @var INotificationManager&MockObject
     */
    private INotificationManager&MockObject $notificationManager;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ParticipantResolver.
     *
     * @var ParticipantResolver&MockObject
     */
    private ParticipantResolver&MockObject $participantResolver;


    /**
     * Set up mocks and service instance.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService         = $this->createMock(ObjectService::class);
        $this->calendarEventService  = $this->createMock(CalendarEventService::class);
        $this->notificationManager   = $this->createMock(INotificationManager::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->participantResolver   = $this->createMock(ParticipantResolver::class);

        $this->service = new AgendaService(
            objectService: $this->objectService,
            calendarEventService: $this->calendarEventService,
            notificationManager: $this->notificationManager,
            logger: $this->logger,
            participantResolver: $this->participantResolver,
        );

    }//end setUp()


    // -----------------------------------------------------------------------
    // publishAgenda tests
    // -----------------------------------------------------------------------

    /**
     * publishAgenda throws InvalidArgumentException when no items exist.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testPublishAgendaThrowsWhenNoItems(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturnCallback(function (array $config) {
                if (($config['filters']['schema'] ?? '') === 'agenda-item') {
                    return [];
                }

                return [];
            });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no agenda items/i');

        $this->service->publishAgenda('meeting-uuid-1');

    }//end testPublishAgendaThrowsWhenNoItems()


    /**
     * publishAgenda sends notifications only to active participants (leftAt === null).
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testPublishAgendaNotifiesOnlyActiveParticipants(): void
    {
        $meetingId = 'meeting-uuid-1';

        $participants = [
            ['displayName' => 'Alice', 'leftAt' => null, 'owner' => 'alice'],
            ['displayName' => 'Bob',   'leftAt' => '2025-01-01T00:00:00Z', 'owner' => 'bob'],
            ['displayName' => 'Carol', 'leftAt' => null, 'owner' => 'carol'],
        ];

        // Meeting entity stub for the #315 full-object read in publishAgenda.
        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('jsonSerialize')->willReturn(
            ['id' => $meetingId, 'title' => 'Test Meeting', 'lifecycle' => 'scheduled']
        );

        $this->objectService
            ->method('find')
            ->willReturn($meetingEntity);

        // Agenda-item query returns one item so the not-empty check passes.
        $this->objectService
            ->method('findAll')
            ->willReturnCallback(function (array $config) {
                if (($config['filters']['schema'] ?? '') === 'agenda-item') {
                    return [['id' => 'item-1', 'title' => 'Item 1']];
                }

                return [];
            });

        // Participants now come from ParticipantResolver (canonical path).
        $this->participantResolver
            ->method('resolveMeetingParticipants')
            ->with($meetingId)
            ->willReturn($participants);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager
            ->method('createNotification')
            ->willReturn($notification);

        // Only 2 active participants → notify() called exactly 2 times.
        $this->notificationManager
            ->expects($this->exactly(2))
            ->method('notify');

        $this->objectService
            ->expects($this->atLeastOnce())
            ->method('saveObject');

        $this->service->publishAgenda($meetingId);

    }//end testPublishAgendaNotifiesOnlyActiveParticipants()


    // -----------------------------------------------------------------------
    // advanceBobPhase tests
    // -----------------------------------------------------------------------

    /**
     * advanceBobPhase correctly cycles through the BOB phase sequence.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testAdvanceBobPhaseCyclesThroughPhases(): void
    {
        $this->markTestSkipped('See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $transitions = [
            ['from' => 'voorstel',        'to' => 'beeldvorming'],
            ['from' => 'beeldvorming',    'to' => 'oordeelsvorming'],
            ['from' => 'oordeelsvorming', 'to' => 'besluitvorming'],
            ['from' => 'besluitvorming',  'to' => 'afgerond'],
        ];

        foreach ($transitions as $t) {
            $itemId   = 'item-' . $t['from'];
            $itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => $t['from']];

            // Use a fresh mock per iteration to prevent expectation accumulation.
            $objectService = $this->createMock(ObjectService::class);
            $objectService->method('find')->willReturn($itemData);
            $objectService
                ->expects($this->once())
                ->method('saveObject')
                ->with(
                    $this->callback(fn($obj) => ($obj['status'] ?? null) === $t['to']),
                    $this->anything(),
                    $this->anything(),
                    $this->anything(),
                );

            $freshService = new AgendaService(
                objectService: $objectService,
                calendarEventService: $this->calendarEventService,
                notificationManager: $this->notificationManager,
                logger: $this->logger,
                participantResolver: $this->participantResolver,
            );

            $freshService->advanceBobPhase($itemId);
        }

    }//end testAdvanceBobPhaseCyclesThroughPhases()


    /**
     * advanceBobPhase throws NotFoundException when the item does not exist.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testAdvanceBobPhaseThrowsWhenItemNotFound(): void
    {
        $this->objectService
            ->method('find')
            ->willReturn(null);

        $this->expectException(\OCA\Decidesk\Exception\NotFoundException::class);

        $this->service->advanceBobPhase('missing-uuid');

    }//end testAdvanceBobPhaseThrowsWhenItemNotFound()


    /**
     * advanceBobPhase throws for informational items.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testAdvanceBobPhaseThrowsForInformationalItem(): void
    {
        $this->markTestSkipped('See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $itemId   = 'item-info';
        $itemData = ['id' => $itemId, 'itemType' => 'informational', 'status' => 'beeldvorming'];

        $this->objectService
            ->method('find')
            ->willReturn($itemData);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/informational/i');

        $this->service->advanceBobPhase($itemId);

    }//end testAdvanceBobPhaseThrowsForInformationalItem()


    /**
     * advanceBobPhase throws when item is already at final phase 'afgerond'.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testAdvanceBobPhaseThrowsAtFinalPhase(): void
    {
        $this->markTestSkipped('See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $itemId   = 'item-final';
        $itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => 'afgerond'];

        $this->objectService
            ->method('find')
            ->willReturn($itemData);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/final phase/i');

        $this->service->advanceBobPhase($itemId);

    }//end testAdvanceBobPhaseThrowsAtFinalPhase()


    // -----------------------------------------------------------------------
    // processHamerstukken tests
    // -----------------------------------------------------------------------

    /**
     * processHamerstukken bulk-updates only items tagged 'hamerstuk'.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testProcessHamerstukkenUpdatesTaggedItemsOnly(): void
    {
        $this->markTestSkipped('See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $meetingId = 'meeting-uuid-1';
        $items     = [
            ['id' => 'item-1', 'title' => 'Item 1', 'tags' => ['hamerstuk'], 'status' => 'besluitvorming'],
            ['id' => 'item-2', 'title' => 'Item 2', 'tags' => [],            'status' => 'beeldvorming'],
            ['id' => 'item-3', 'title' => 'Item 3', 'tags' => ['hamerstuk'], 'status' => 'oordeelsvorming'],
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn($items);

        // Capture saved objects to verify only hamerstukken are updated.
        $savedObjects = [];
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(function ($object) use (&$savedObjects) {
                $savedObjects[] = $object;
                return $object;
            });

        $this->service->processHamerstukken($meetingId);

        $this->assertCount(2, $savedObjects);
        foreach ($savedObjects as $saved) {
            $this->assertSame('afgerond', $saved['status']);
        }

    }//end testProcessHamerstukkenUpdatesTaggedItemsOnly()


    // -----------------------------------------------------------------------
    // reorderItems tests
    // -----------------------------------------------------------------------

    /**
     * reorderItems assigns sequential orderNumber values 1..n.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
     */
    public function testReorderItemsAssignsSequentialNumbers(): void
    {
        $this->markTestSkipped('See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

        $meetingId  = 'meeting-uuid-1';
        $orderedIds = ['item-c', 'item-a', 'item-b'];

        // Return the meeting's items so all IDs pass the ownership check.
        $this->objectService
            ->method('findAll')
            ->willReturn([
                ['id' => 'item-a'],
                ['id' => 'item-b'],
                ['id' => 'item-c'],
            ]);

        $savedObjects = [];
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(function ($object) use (&$savedObjects) {
                $savedObjects[] = $object;
                return $object;
            });

        $this->service->reorderItems($meetingId, $orderedIds);

        $this->assertCount(3, $savedObjects);
        $this->assertSame(1, $savedObjects[0]['orderNumber']);
        $this->assertSame(2, $savedObjects[1]['orderNumber']);
        $this->assertSame(3, $savedObjects[2]['orderNumber']);

        $this->assertSame('item-c', $savedObjects[0]['id']);
        $this->assertSame('item-a', $savedObjects[1]['id']);
        $this->assertSame('item-b', $savedObjects[2]['id']);

    }//end testReorderItemsAssignsSequentialNumbers()


}//end class
