<?php

/**
 * Unit tests for BoardMeetingService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\BoardMeetingService;
use OCA\Decidesk\Service\BoardMemberService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardMeetingService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
 */
class BoardMeetingServiceTest extends TestCase
{


    /**
     * Build the service with an in-memory meetings map.
     *
     * @param array<string, array<string, mixed>> &$meetings Map of meetingId => meeting row
     * @param array<int, array<string, mixed>>    &$audited  Captured audit-log calls
     * @param array<int, array<string, mixed>>    $members   Board members returned by listForBoard
     *
     * @return BoardMeetingService
     */
    private function makeService(array &$meetings, array &$audited, array $members=[]): BoardMeetingService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$meetings): ?ObjectEntity {
                if (isset($meetings[(string) $id]) === false) {
                    return null;
                }

                $row = $meetings[(string) $id];
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$meetings): ObjectEntity {
                $id = $uuid ?? ('m-'.(count($meetings) + 1));
                $row = array_merge(['id' => $id], $object);
                $meetings[$id] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $auditLog = $this->createMock(AuditLogService::class);
        $auditLog->method('append')->willReturnCallback(
            static function (string $actor, string $action, array $uids, array $payload=[]) use (&$audited): array {
                $audited[] = compact('actor', 'action', 'uids', 'payload');
                return ['success' => true, 'entry' => [], 'message' => 'ok'];
            }
        );

        $boardMembers = $this->createMock(BoardMemberService::class);
        $boardMembers->method('listForBoard')->willReturn(
            [
                'success' => true,
                'members' => $members,
                'count'   => count($members),
            ]
        );

        return new BoardMeetingService($container, $logger, $auditLog, $boardMembers);

    }//end makeService()


    /**
     * Schedule requires meetingDate and validates enums.
     *
     * @return void
     */
    public function testScheduleRequiresMeetingDate(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $missing = $service->schedule('b1', []);
        $this->assertFalse($missing['success']);

        $bad = $service->schedule('b1', ['meetingDate' => '2026-09-01', 'meetingType' => 'feast']);
        $this->assertFalse($bad['success']);

        $ok = $service->schedule('b1', ['meetingDate' => '2026-09-01', 'meetingType' => 'regular', 'format' => 'hybrid']);
        $this->assertTrue($ok['success']);
        $this->assertSame('scheduled', $ok['meeting']['status']);
        $this->assertSame('b1', $ok['meeting']['boardKoppeling']);

    }//end testScheduleRequiresMeetingDate()


    /**
     * sendNotice transitions scheduled → notice-sent, stamps noticeSentDate,
     * and writes a notice-sent audit row.
     *
     * @return void
     */
    public function testSendNoticeTransitionsAndAudits(): void
    {
        $meetings = [
            'm1' => ['id' => 'm1', 'status' => 'scheduled'],
        ];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $result = $service->sendNotice('m1', 'alice');

        $this->assertTrue($result['success']);
        $this->assertSame('notice-sent', $meetings['m1']['status']);
        $this->assertArrayHasKey('noticeSentDate', $meetings['m1']);
        $this->assertCount(1, $audited);
        $this->assertSame('notice-sent', $audited[0]['action']);

    }//end testSendNoticeTransitionsAndAudits()


    /**
     * sendNotice from the wrong state is rejected and produces no audit.
     *
     * @return void
     */
    public function testSendNoticeRejectsBadState(): void
    {
        $meetings = [
            'm1' => ['id' => 'm1', 'status' => 'closed'],
        ];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $result = $service->sendNotice('m1', 'alice');

        $this->assertFalse($result['success']);
        $this->assertSame([], $audited);

    }//end testSendNoticeRejectsBadState()


    /**
     * Full lifecycle: scheduled → notice-sent → materials-distributed →
     * in-session → adjourned → closed → minutes-signed.
     *
     * @return void
     */
    public function testFullLifecyclePath(): void
    {
        $meetings = [
            'm1' => ['id' => 'm1', 'status' => 'scheduled'],
        ];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $this->assertTrue($service->runLifecycleTransition('m1', 'send-notice')['success']);
        $this->assertTrue($service->runLifecycleTransition('m1', 'distribute-materials')['success']);
        $this->assertTrue($service->runLifecycleTransition('m1', 'open')['success']);
        $this->assertTrue($service->runLifecycleTransition('m1', 'adjourn')['success']);
        $this->assertTrue($service->runLifecycleTransition('m1', 'close')['success']);
        $this->assertTrue($service->runLifecycleTransition('m1', 'sign-minutes')['success']);

        $this->assertSame('minutes-signed', $meetings['m1']['status']);

    }//end testFullLifecyclePath()


    /**
     * getAvailableActions enumerates valid actions from a state.
     *
     * @return void
     */
    public function testGetAvailableActions(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $this->assertSame(['send-notice', 'open'], $service->getAvailableActions('scheduled'));
        $this->assertSame(['close'], $service->getAvailableActions('adjourned'));

    }//end testGetAvailableActions()


    /**
     * sendNotice writes one delivery entry per board member and reports the
     * recipient count in the audit payload.
     *
     * @return void
     */
    public function testSendNoticeRecordsPerRecipientDeliveries(): void
    {
        $meetings = [
            'm1' => [
                'id'             => 'm1',
                'status'         => 'scheduled',
                'boardKoppeling' => 'b1',
                'meetingDate'    => '2099-06-01',
            ],
        ];
        $audited  = [];
        $members  = [
            ['id' => 'bm-1', 'displayName' => 'Janneke de Bruin', 'role' => 'chairman'],
            ['id' => 'bm-2', 'displayName' => 'Klaas Mulder', 'role' => 'member'],
        ];
        $service  = $this->makeService($meetings, $audited, $members);

        $result = $service->sendNotice('m1', 'alice');

        $this->assertTrue($result['success']);

        $deliveries = $meetings['m1']['noticeDeliveries'];
        $this->assertCount(2, $deliveries);
        $this->assertSame('bm-1', $deliveries[0]['recipient']);
        $this->assertSame('Janneke de Bruin', $deliveries[0]['displayName']);
        $this->assertSame('chairman', $deliveries[0]['role']);
        $this->assertSame('portal', $deliveries[0]['channel']);
        $this->assertSame('sent', $deliveries[0]['status']);
        $this->assertNotEmpty($deliveries[0]['sentAt']);
        $this->assertSame('bm-2', $deliveries[1]['recipient']);

        $this->assertSame($deliveries, $result['deliveries']);

        // Audit entry carries the recipient count.
        $this->assertCount(1, $audited);
        $this->assertSame('notice-sent', $audited[0]['action']);
        $this->assertSame(2, $audited[0]['payload']['recipients']);

        // Far-future meeting: no deadline warnings.
        $this->assertSame([], $result['warnings']);

    }//end testSendNoticeRecordsPerRecipientDeliveries()


    /**
     * getNoticeDeadlineInfo: comfortable lead time produces no warnings.
     *
     * @return void
     */
    public function testNoticeDeadlineComfortable(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $info = $service->getNoticeDeadlineInfo(
            ['meetingDate' => '2026-06-01', 'noticePeriodDays' => 15],
            new \DateTimeImmutable('2026-05-10 09:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertSame('2026-05-17', $info['deadline']);
        $this->assertSame(7, $info['daysUntilDeadline']);
        $this->assertSame([], $info['warnings']);

    }//end testNoticeDeadlineComfortable()


    /**
     * getNoticeDeadlineInfo: sending within 3 days of the deadline warns.
     *
     * @return void
     */
    public function testNoticeDeadlineWithinThreeDaysWarns(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $info = $service->getNoticeDeadlineInfo(
            ['meetingDate' => '2026-06-01', 'noticePeriodDays' => 15],
            new \DateTimeImmutable('2026-05-15 09:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertSame(2, $info['daysUntilDeadline']);
        $this->assertCount(1, $info['warnings']);
        $this->assertStringContainsString('within 3 day(s)', $info['warnings'][0]);

    }//end testNoticeDeadlineWithinThreeDaysWarns()


    /**
     * getNoticeDeadlineInfo: sending after the deadline produces the
     * after-deadline warning (not the within-3-days one).
     *
     * @return void
     */
    public function testNoticeDeadlinePassedWarns(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $info = $service->getNoticeDeadlineInfo(
            ['meetingDate' => '2026-06-01', 'noticePeriodDays' => 15],
            new \DateTimeImmutable('2026-05-20 09:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertSame(-3, $info['daysUntilDeadline']);
        $this->assertCount(1, $info['warnings']);
        $this->assertStringContainsString('already passed', $info['warnings'][0]);

    }//end testNoticeDeadlinePassedWarns()


    /**
     * getNoticeDeadlineInfo defaults the notice period to 15 days and
     * degrades gracefully without a meeting date.
     *
     * @return void
     */
    public function testNoticeDeadlineDefaultsAndMissingDate(): void
    {
        $meetings = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $audited);

        $defaulted = $service->getNoticeDeadlineInfo(
            ['meetingDate' => '2026-06-01'],
            new \DateTimeImmutable('2026-05-01 09:00:00', new \DateTimeZone('UTC'))
        );
        $this->assertSame('2026-05-17', $defaulted['deadline']);

        $missing = $service->getNoticeDeadlineInfo([], new \DateTimeImmutable('2026-05-01', new \DateTimeZone('UTC')));
        $this->assertNull($missing['deadline']);
        $this->assertNull($missing['daysUntilDeadline']);
        $this->assertSame([], $missing['warnings']);

    }//end testNoticeDeadlineDefaultsAndMissingDate()


}//end class
