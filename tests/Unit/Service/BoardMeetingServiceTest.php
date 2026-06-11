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
     *
     * @return BoardMeetingService
     */
    private function makeService(array &$meetings, array &$audited): BoardMeetingService
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

        return new BoardMeetingService($container, $logger, $auditLog);

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


}//end class
