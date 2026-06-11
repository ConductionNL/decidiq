<?php
/**
 * Unit tests for BoardCalDavSyncService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
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

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardCalDavSyncService;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardCalDavSyncService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
class BoardCalDavSyncServiceTest extends TestCase
{


    /**
     * Build a service with no calendar manager configured (covers the
     * "ICS-blob-only" fallback path).
     *
     * @return BoardCalDavSyncService
     */
    private function makeServiceNoCalendar(): BoardCalDavSyncService
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no calendar'));
        $logger = $this->createMock(LoggerInterface::class);
        return new BoardCalDavSyncService(container: $container, logger: $logger);

    }//end makeServiceNoCalendar()


    /**
     * Build a service with a stubbed ICalendarManager that returns a single
     * writable calendar.
     *
     * @param array<int, array{string, string}> &$writes Captured (name, ics)
     *
     * @return BoardCalDavSyncService
     */
    private function makeServiceWithCalendar(array &$writes): BoardCalDavSyncService
    {
        $captured = &$writes;
        $calendar = $this->createMock(ICreateFromString::class);
        $calendar->method('getUri')->willReturn('decidesk-board');
        $calendar->method('createFromString')->willReturnCallback(
            static function (string $name, string $data) use (&$captured): void {
                $captured[] = [$name, $data];
            }
        );

        $manager = $this->createMock(ICalendarManager::class);
        $manager->method('getCalendarsForPrincipal')->willReturn([$calendar]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($manager) {
                if ($id === ICalendarManager::class) {
                    return $manager;
                }

                throw new \RuntimeException('unbound: '.$id);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        return new BoardCalDavSyncService(container: $container, logger: $logger);

    }//end makeServiceWithCalendar()


    /**
     * buildIcs emits a well-formed VEVENT with the documented X-DECIDESK-*
     * properties from the BoardMeeting row.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     */
    public function testBuildIcsEmitsCanonicalVeventWithXProperties(): void
    {
        $service = $this->makeServiceNoCalendar();
        $ics     = $service->buildIcs(
            meeting: [
                'id'                 => 'meet-1',
                'title'              => 'RvC Q1',
                'meetingStart'       => '2026-07-01T10:00:00Z',
                'meetingEnd'         => '2026-07-01T12:00:00Z',
                'location'           => 'Boardroom A',
                'description'        => 'Quarterly review',
                'boardKoppeling'     => 'board-rvc-acme',
                'status'             => 'scheduled',
                'quorumRequired'     => 5,
                'noticeDeadlineDays' => 14,
                'meetingType'        => 'regular',
                'format'             => 'hybrid',
                'language'           => 'nl',
            ]
        );

        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('UID:meet-1@decidesk.local', $ics);
        self::assertStringContainsString('SUMMARY:RvC Q1', $ics);
        self::assertStringContainsString('LOCATION:Boardroom A', $ics);
        self::assertStringContainsString('DTSTART:20260701T100000Z', $ics);
        self::assertStringContainsString('DTEND:20260701T120000Z', $ics);
        self::assertStringContainsString('X-DECIDESK-BOARD-UID:board-rvc-acme', $ics);
        self::assertStringContainsString('X-DECIDESK-LIFECYCLE:scheduled', $ics);
        self::assertStringContainsString('X-DECIDESK-QUORUM-REQUIRED:5', $ics);
        self::assertStringContainsString('X-DECIDESK-NOTICE-DEADLINE-DAYS:14', $ics);
        self::assertStringContainsString('X-DECIDESK-MEETING-TYPE:regular', $ics);
        self::assertStringContainsString('X-DECIDESK-FORMAT:hybrid', $ics);
        self::assertStringContainsString('X-DECIDESK-LANGUAGE:nl', $ics);
        self::assertStringContainsString('END:VEVENT', $ics);
        self::assertStringContainsString('END:VCALENDAR', $ics);

    }//end testBuildIcsEmitsCanonicalVeventWithXProperties()


    /**
     * readMeetingData round-trips the X-DECIDESK-* properties emitted by
     * buildIcs, mapping them back to the canonical OR field names from
     * supportedXProperties().
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     */
    public function testReadMeetingDataRoundTripsXProperties(): void
    {
        $service = $this->makeServiceNoCalendar();
        $ics     = $service->buildIcs(
            meeting: [
                'id'             => 'meet-2',
                'title'          => 'Strategy day',
                'meetingStart'   => '2026-09-15T09:30:00Z',
                'meetingEnd'     => '2026-09-15T17:00:00Z',
                'boardKoppeling' => 'board-rvc-acme',
                'status'         => 'notice-sent',
                'meetingType'    => 'strategy-day',
                'format'         => 'in-person',
                'language'       => 'en',
            ]
        );

        $parsed = $service->readMeetingData(ics: $ics);

        self::assertSame('meet-2@decidesk.local', $parsed['uid']);
        self::assertSame('Strategy day', $parsed['title']);
        self::assertSame('20260915T093000Z', $parsed['meetingStart']);
        self::assertSame('20260915T170000Z', $parsed['meetingEnd']);
        self::assertSame('board-rvc-acme', $parsed['boardKoppeling']);
        self::assertSame('notice-sent', $parsed['status']);
        self::assertSame('strategy-day', $parsed['meetingType']);
        self::assertSame('in-person', $parsed['format']);
        self::assertSame('en', $parsed['language']);

    }//end testReadMeetingDataRoundTripsXProperties()


    /**
     * syncMeeting returns the ICS blob even when no calendar manager is
     * registered — this is the "CalDAV unavailable" path documented in
     * Phase 7.1.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testSyncMeetingFallsBackToIcsOnlyWhenNoCalendar(): void
    {
        $service = $this->makeServiceNoCalendar();
        $result  = $service->syncMeeting(
            meeting: [
                'id'             => 'meet-3',
                'title'          => 'Audit committee',
                'meetingStart'   => '2026-10-01T14:00:00Z',
                'meetingEnd'     => '2026-10-01T16:00:00Z',
                'boardKoppeling' => 'board-audit-noord',
                'status'         => 'scheduled',
            ],
            principalUid: 'admin'
        );

        self::assertTrue($result['success']);
        self::assertSame('meet-3@decidesk.local', $result['uid']);
        self::assertNull($result['calendar']);
        self::assertStringContainsString('BEGIN:VEVENT', $result['ics']);

    }//end testSyncMeetingFallsBackToIcsOnlyWhenNoCalendar()


    /**
     * syncMeeting writes the ICS blob through the first writable
     * principal calendar when one is available.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function testSyncMeetingWritesToFirstWritableCalendar(): void
    {
        $writes  = [];
        $service = $this->makeServiceWithCalendar(writes: $writes);
        $result  = $service->syncMeeting(
            meeting: [
                'id'             => 'meet-4',
                'title'          => 'RvB extra-ordinary',
                'meetingStart'   => '2026-04-12T13:00:00Z',
                'meetingEnd'     => '2026-04-12T14:30:00Z',
                'boardKoppeling' => 'board-rvb-acme',
                'status'         => 'scheduled',
                'meetingType'    => 'extraordinary',
            ],
            principalUid: 'chair'
        );

        self::assertTrue($result['success']);
        self::assertSame('decidesk-board', $result['calendar']);
        self::assertCount(1, $writes);
        self::assertSame('meet-4@decidesk.local.ics', $writes[0][0]);
        self::assertStringContainsString('UID:meet-4@decidesk.local', $writes[0][1]);
        self::assertStringContainsString('X-DECIDESK-LIFECYCLE:scheduled', $writes[0][1]);
        self::assertStringContainsString('X-DECIDESK-MEETING-TYPE:extraordinary', $writes[0][1]);

    }//end testSyncMeetingWritesToFirstWritableCalendar()


    /**
     * supportedXProperties returns the canonical X-DECIDESK-* catalog
     * mapping documented in the change design.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     */
    public function testSupportedXPropertiesReturnsCanonicalCatalog(): void
    {
        $catalog = BoardCalDavSyncService::supportedXProperties();

        self::assertSame('boardKoppeling', $catalog['X-DECIDESK-BOARD-UID']);
        self::assertSame('status', $catalog['X-DECIDESK-LIFECYCLE']);
        self::assertSame('quorumRequired', $catalog['X-DECIDESK-QUORUM-REQUIRED']);
        self::assertSame('noticeDeadlineDays', $catalog['X-DECIDESK-NOTICE-DEADLINE-DAYS']);
        self::assertSame('meetingType', $catalog['X-DECIDESK-MEETING-TYPE']);
        self::assertSame('format', $catalog['X-DECIDESK-FORMAT']);
        self::assertSame('language', $catalog['X-DECIDESK-LANGUAGE']);
        self::assertCount(7, $catalog);

    }//end testSupportedXPropertiesReturnsCanonicalCatalog()


}//end class
