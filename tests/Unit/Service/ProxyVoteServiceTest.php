<?php
/**
 * Unit tests for ProxyVoteService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\ProxyVoteService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProxyVoteService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteServiceTest extends TestCase
{


    /**
     * Tracker for audit log calls.
     *
     * @var \ArrayObject<int, array<string, mixed>>
     */
    private \ArrayObject $auditCalls;


    /**
     * Build a service wired to in-memory rows.
     *
     * @param array<int, array<string, mixed>> &$rows  Existing rows
     * @param array<int, array<string, mixed>> &$saved Captured saves
     *
     * @return ProxyVoteService
     */
    private function makeService(array &$rows, array &$saved): ProxyVoteService
    {
        $rowsRef       = &$rows;
        $savedRef      = &$saved;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$rowsRef): array {
                $out = [];
                foreach ($rowsRef as $row) {
                    $matches = true;
                    foreach (($config['filters'] ?? []) as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches === true) {
                        $out[] = $row;
                    }
                }

                return $out;
            }
        );
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$rowsRef) {
                foreach ($rowsRef as $row) {
                    if (($row['id'] ?? null) === $id) {
                        $entity = $this->createMock(ObjectEntity::class);
                        $entity->method('jsonSerialize')->willReturn($row);
                        $entity->method('getObject')->willReturn($row);
                        return $entity;
                    }
                }

                return null;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$savedRef, &$rowsRef): ObjectEntity {
                $savedRef[] = $object;
                $existingId = ($uuid ?? ($object['id'] ?? null));
                if ($existingId !== null) {
                    foreach ($rowsRef as $i => $row) {
                        if (($row['id'] ?? null) === $existingId) {
                            $rowsRef[$i] = array_merge($row, $object, ['id' => $existingId]);
                            $row         = $rowsRef[$i];
                            $entity      = $this->createMock(ObjectEntity::class);
                            $entity->method('jsonSerialize')->willReturn($row);
                            $entity->method('getObject')->willReturn($row);
                            return $entity;
                        }
                    }
                }

                $row       = array_merge(['id' => 'proxy-'.count($rowsRef)], $object);
                $rowsRef[] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $this->auditCalls = new \ArrayObject();
        $tracker          = $this->auditCalls;
        $audit            = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturnCallback(
            function (string $actor, string $action, array $objectUids, array $payload=[]) use ($tracker): array {
                $tracker->append(
                    [
                        'actor'      => $actor,
                        'action'     => $action,
                        'objectUids' => $objectUids,
                        'payload'    => $payload,
                    ]
                );
                return ['success' => true, 'entry' => [], 'message' => 'ok'];
            }
        );

        return new ProxyVoteService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            auditLogService: $audit
        );

    }//end makeService()


    /**
     * register validates required fields.
     *
     * @return void
     */
    public function testRegisterRequiresFields(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->register('', 'g', 'h');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('required', $result['message']);

    }//end testRegisterRequiresFields()


    /**
     * register rejects grantor == holder.
     *
     * @return void
     */
    public function testRegisterRejectsSelfProxy(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->register('m-1', 'g-1', 'g-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('must differ', $result['message']);

    }//end testRegisterRejectsSelfProxy()


    /**
     * register stores a pending-approval row and audits proxy-created.
     *
     * @return void
     */
    public function testRegisterStoresPendingAndAuditsCreated(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->register('m-1', 'g-1', 'h-1', ['scope' => 'all-resolutions']);

        $this->assertTrue($result['success']);
        $this->assertSame('pending-approval', $saved[0]['proxyStatus']);
        $this->assertSame('m-1', $saved[0]['meetingKoppeling']);

        $calls = $this->auditCalls->getArrayCopy();
        $this->assertCount(1, $calls);
        $this->assertSame('proxy-created', $calls[0]['action']);

    }//end testRegisterStoresPendingAndAuditsCreated()


    /**
     * suspend transitions proxyStatus to 'suspended' and does NOT audit
     * proxy-revoked.
     *
     * @return void
     */
    public function testSuspendTransitionsWithoutRevokeAudit(): void
    {
        $rows = [
            [
                'id'               => 'p-1',
                'meetingKoppeling' => 'm-1',
                'grantorKoppeling' => 'g-1',
                'holderKoppeling'  => 'h-1',
                'proxyStatus'      => 'active',
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->suspend('p-1', 'alice');

        $this->assertTrue($result['success']);
        $this->assertSame('suspended', end($saved)['proxyStatus']);

        $calls = $this->auditCalls->getArrayCopy();
        $revokeCalls = array_filter($calls, static fn(array $c): bool => $c['action'] === 'proxy-revoked');
        $this->assertCount(0, $revokeCalls);

    }//end testSuspendTransitionsWithoutRevokeAudit()


    /**
     * revoke transitions proxyStatus to 'revoked' and audits.
     *
     * @return void
     */
    public function testRevokeTransitionsAndAudits(): void
    {
        $rows = [
            [
                'id'               => 'p-1',
                'meetingKoppeling' => 'm-1',
                'grantorKoppeling' => 'g-1',
                'holderKoppeling'  => 'h-1',
                'proxyStatus'      => 'active',
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->revoke('p-1', 'alice');

        $this->assertTrue($result['success']);
        $this->assertSame('revoked', end($saved)['proxyStatus']);

        $calls = $this->auditCalls->getArrayCopy();
        $revokeCalls = array_values(
            array_filter($calls, static fn(array $c): bool => $c['action'] === 'proxy-revoked')
        );
        $this->assertCount(1, $revokeCalls);
        $this->assertSame('alice', $revokeCalls[0]['actor']);

    }//end testRevokeTransitionsAndAudits()


    /**
     * transition rejects unknown statuses.
     *
     * @return void
     */
    public function testTransitionRejectsUnknownStatus(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->transition('p-1', 'bogus', 'alice');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown proxy status', $result['message']);

    }//end testTransitionRejectsUnknownStatus()


    /**
     * forMeeting returns rows for the meeting, optionally filtered by status.
     *
     * @return void
     */
    public function testForMeetingReturnsRowsAndFilters(): void
    {
        $rows = [
            ['id' => 'p-1', 'meetingKoppeling' => 'm-1', 'proxyStatus' => 'active'],
            ['id' => 'p-2', 'meetingKoppeling' => 'm-1', 'proxyStatus' => 'revoked'],
            ['id' => 'p-3', 'meetingKoppeling' => 'm-2', 'proxyStatus' => 'active'],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $all = $svc->forMeeting('m-1');
        $this->assertSame(2, $all['count']);

        $activeOnly = $svc->forMeeting('m-1', 'active');
        $this->assertSame(1, $activeOnly['count']);
        $this->assertSame('p-1', $activeOnly['proxies'][0]['id']);

    }//end testForMeetingReturnsRowsAndFilters()


}//end class
