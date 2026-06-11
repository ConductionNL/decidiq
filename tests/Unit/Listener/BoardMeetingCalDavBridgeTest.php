<?php
/**
 * Unit tests for BoardMeetingCalDavBridge.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\Listener\BoardMeetingCalDavBridge;
use OCA\Decidesk\Service\BoardCalDavSyncService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardMeetingCalDavBridge.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
class BoardMeetingCalDavBridgeTest extends TestCase
{


    /**
     * Build a test bridge over an in-memory sync recorder.
     *
     * @param array<int, array<string, mixed>> &$syncCalls Captured sync calls
     *
     * @return BoardMeetingCalDavBridge
     */
    private function makeBridge(array &$syncCalls): BoardMeetingCalDavBridge
    {
        $sync = $this->getMockBuilder(BoardCalDavSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $sync->method('syncMeeting')->willReturnCallback(
            static function (array $meeting, string $principalUid) use (&$syncCalls): array {
                $syncCalls[] = [
                    'meeting'   => $meeting,
                    'principal' => $principalUid,
                ];
                return [
                    'success'  => true,
                    'uid'      => 'uid-'.($meeting['id'] ?? 'unknown'),
                    'ics'      => 'BEGIN:VCALENDAR',
                    'calendar' => 'decidesk-board',
                    'message'  => 'ok',
                ];
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $logger = $this->createMock(LoggerInterface::class);

        return new BoardMeetingCalDavBridge(
            syncService: $sync,
            userSession: $userSession,
            logger: $logger
        );

    }//end makeBridge()


    /**
     * Build a minimal stub ObjectEntity with the given row + schema slug.
     *
     * @param array<string, mixed> $row    Serialized row
     * @param string               $schema Schema slug
     *
     * @return ObjectEntity
     */
    private function makeEntity(array $row, string $schema): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $row['_schemaSlug'] = $schema;
        $entity->method('jsonSerialize')->willReturn($row);
        $entity->method('getObject')->willReturn($row);
        return $entity;

    }//end makeEntity()


    /**
     * Non-OR events must be ignored without touching the sync service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testIgnoresNonObjectLifecycleEvents(): void
    {
        $calls  = [];
        $bridge = $this->makeBridge(syncCalls: $calls);

        $event = $this->createMock(Event::class);
        $bridge->handle(event: $event);

        self::assertSame([], $calls);

    }//end testIgnoresNonObjectLifecycleEvents()


    /**
     * Lifecycle events for other schemas must be ignored.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testIgnoresOtherSchemas(): void
    {
        $calls  = [];
        $bridge = $this->makeBridge(syncCalls: $calls);

        $entity = $this->makeEntity(
            row: ['id' => 'm-1', 'title' => 'Council'],
            schema: 'meeting'
        );
        $event  = new ObjectCreatedEvent(object: $entity);

        $bridge->handle(event: $event);

        self::assertSame([], $calls);

    }//end testIgnoresOtherSchemas()


    /**
     * BoardMeeting create events are forwarded to the sync service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testForwardsCreatedBoardMeetingsToSyncService(): void
    {
        $calls  = [];
        $bridge = $this->makeBridge(syncCalls: $calls);

        $entity = $this->makeEntity(
            row: [
                'id'             => 'meet-1',
                'title'          => 'RvC Q1',
                'meetingStart'   => '2026-07-01T10:00:00Z',
                'boardKoppeling' => 'board-rvc',
                'status'         => 'scheduled',
            ],
            schema: 'board-meeting'
        );
        $event  = new ObjectCreatedEvent(object: $entity);

        $bridge->handle(event: $event);

        self::assertCount(1, $calls);
        self::assertSame('admin', $calls[0]['principal']);
        self::assertSame('meet-1', $calls[0]['meeting']['id']);
        self::assertSame('RvC Q1', $calls[0]['meeting']['title']);

    }//end testForwardsCreatedBoardMeetingsToSyncService()


    /**
     * BoardMeeting update events are likewise forwarded.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testForwardsUpdatedBoardMeetingsToSyncService(): void
    {
        $calls  = [];
        $bridge = $this->makeBridge(syncCalls: $calls);

        $entity = $this->makeEntity(
            row: [
                'id'             => 'meet-2',
                'title'          => 'RvB monthly',
                'boardKoppeling' => 'board-rvb',
                'status'         => 'notice-sent',
            ],
            schema: 'board-meeting'
        );
        $event  = new ObjectUpdatedEvent(newObject: $entity);

        $bridge->handle(event: $event);

        self::assertCount(1, $calls);
        self::assertSame('meet-2', $calls[0]['meeting']['id']);
        self::assertSame('notice-sent', $calls[0]['meeting']['status']);

    }//end testForwardsUpdatedBoardMeetingsToSyncService()


    /**
     * Sync service failures must be swallowed (logged) so OR persistence
     * never blocks on a calendar hiccup.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testSyncFailureDoesNotPropagate(): void
    {
        $sync = $this->getMockBuilder(BoardCalDavSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $sync->method('syncMeeting')->willThrowException(new \RuntimeException('boom'));

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);

        $bridge = new BoardMeetingCalDavBridge(
            syncService: $sync,
            userSession: $userSession,
            logger: $logger
        );

        $entity = $this->makeEntity(
            row: ['id' => 'meet-3', 'title' => 'Audit', 'status' => 'scheduled'],
            schema: 'board-meeting'
        );
        $event  = new ObjectCreatedEvent(object: $entity);

        // Must not throw.
        $bridge->handle(event: $event);
        self::addToAssertionCount(1);

    }//end testSyncFailureDoesNotPropagate()


}//end class
