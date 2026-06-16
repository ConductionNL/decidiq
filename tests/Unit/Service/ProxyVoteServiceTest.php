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
     * @param array<int, array<string, mixed>> &$rows       Existing rows
     * @param array<int, array<string, mixed>> &$saved      Captured saves
     * @param int                              $maxProxies  Configured max_proxies_per_holder app config value
     * @param bool                             $findAllFail When true the ObjectService::findAll() call throws (fail-closed path)
     *
     * @return ProxyVoteService
     */
    private function makeService(array &$rows, array &$saved, int $maxProxies=2, bool $findAllFail=false): ProxyVoteService
    {
        $rowsRef       = &$rows;
        $savedRef      = &$saved;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$rowsRef, $findAllFail): array {
                if ($findAllFail === true) {
                    throw new \RuntimeException('OpenRegister unavailable');
                }

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

        $appConfig = $this->createMock(\OCP\IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static function (string $app, string $key, int $default=0) use ($maxProxies): int {
                if ($app === 'decidesk' && $key === ProxyVoteService::MAX_PROXIES_CONFIG_KEY) {
                    return $maxProxies;
                }

                return $default;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService, $appConfig): object {
                if ($id === \OCP\IAppConfig::class) {
                    return $appConfig;
                }

                return $objectService;
            }
        );

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


    /**
     * register rejects a holder who already holds the maximum number of ACTIVE
     * proxies in the meeting (per-member proxy limit, default 2).
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return void
     */
    public function testRegisterRejectsHolderAtProxyCap(): void
    {
        $rows = [
            ['id' => 'p-1', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-1', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
            ['id' => 'p-2', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-2', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->register('m-1', 'g-3', 'h-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum number of proxies reached', $result['message']);
        $this->assertCount(0, $saved, 'No proxy row may be written when the cap is reached');

    }//end testRegisterRejectsHolderAtProxyCap()


    /**
     * Non-active proxies (revoked/suspended/pending) and other meetings/holders
     * do not count toward the cap.
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return void
     */
    public function testRegisterCapCountsOnlyActiveProxiesInMeetingForHolder(): void
    {
        $rows = [
            ['id' => 'p-1', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-1', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
            ['id' => 'p-2', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-2', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'revoked'],
            ['id' => 'p-3', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-3', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'suspended'],
            ['id' => 'p-4', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-4', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'pending-approval'],
            ['id' => 'p-5', 'meetingKoppeling' => 'm-2', 'grantorKoppeling' => 'g-5', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
            ['id' => 'p-6', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-6', 'holderKoppeling' => 'h-2', 'proxyStatus' => 'active'],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved);

        $result = $svc->register('m-1', 'g-7', 'h-1');

        $this->assertTrue($result['success'], 'Only 1 ACTIVE proxy in m-1 for h-1 — under the cap of 2');
        $this->assertSame('pending-approval', $saved[0]['proxyStatus']);

    }//end testRegisterCapCountsOnlyActiveProxiesInMeetingForHolder()


    /**
     * The cap is configurable via app config decidesk/max_proxies_per_holder.
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return void
     */
    public function testRegisterCapIsConfigurable(): void
    {
        $rows = [
            ['id' => 'p-1', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-1', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
            ['id' => 'p-2', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-2', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
        ];
        $saved = [];

        // Raised cap (3): the third proxy is accepted.
        $svc    = $this->makeService($rows, $saved, 3);
        $result = $svc->register('m-1', 'g-3', 'h-1');
        $this->assertTrue($result['success']);

        // A cap below 1 falls back to the default of 2 (never disables the limit).
        $rows2  = [
            ['id' => 'p-1', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-1', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
            ['id' => 'p-2', 'meetingKoppeling' => 'm-1', 'grantorKoppeling' => 'g-2', 'holderKoppeling' => 'h-1', 'proxyStatus' => 'active'],
        ];
        $saved2 = [];
        $svc2   = $this->makeService($rows2, $saved2, 0);
        $result = $svc2->register('m-1', 'g-3', 'h-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum number of proxies reached', $result['message']);

    }//end testRegisterCapIsConfigurable()


    /**
     * Fail closed: when existing proxies cannot be counted, registration is rejected.
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return void
     */
    public function testRegisterFailsClosedWhenProxyCountUnavailable(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, 2, true);

        $result = $svc->register('m-1', 'g-1', 'h-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('registration refused', $result['message']);
        $this->assertCount(0, $saved, 'No proxy row may be written when the count is unavailable');

    }//end testRegisterFailsClosedWhenProxyCountUnavailable()


}//end class
