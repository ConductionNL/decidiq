<?php

/**
 * Unit tests for BoardVoteService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard;
use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\BoardVoteService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardVoteService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
 */
class BoardVoteServiceTest extends TestCase
{


    /**
     * Build a service backed by an in-memory votes array.
     *
     * @param array<int, array<string, mixed>> &$votes      Captured votes
     * @param array<int, array<string, mixed>> &$audited    Captured audit calls
     * @param bool                              $castAllowed Guard outcome for canCastVote
     *
     * @return BoardVoteService
     */
    private function makeService(array &$votes, array &$audited, bool $castAllowed=true): BoardVoteService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$votes): array {
                return $votes;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$votes): ObjectEntity {
                $row    = array_merge(['id' => 'v-'.(count($votes) + 1)], $object);
                $votes[] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $guard = $this->createMock(ResolutionLifecycleGuard::class);
        $guard->method('canCastVote')->willReturn(
            [
                'allowed'  => $castAllowed,
                'reason'   => $castAllowed ? 'ok' : 'Board member is recused on this agenda item (recused-from-vote).',
                'conflict' => null,
            ]
        );

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturnCallback(
            static function (string $actor, string $action, array $uids, array $payload=[]) use (&$audited): array {
                $audited[] = compact('actor', 'action', 'uids', 'payload');
                return ['success' => true, 'entry' => [], 'message' => 'ok'];
            }
        );

        return new BoardVoteService($container, $logger, $guard, $audit);

    }//end makeService()


    /**
     * cast() rejects unknown vote enums.
     *
     * @return void
     */
    public function testCastRejectsUnknownVote(): void
    {
        $votes   = [];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'maybe');
        $this->assertFalse($result['success']);

    }//end testCastRejectsUnknownVote()


    /**
     * cast() rejects unknown method.
     *
     * @return void
     */
    public function testCastRejectsUnknownMethod(): void
    {
        $votes   = [];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'in-favor', ['voteMethod' => 'telepathy']);
        $this->assertFalse($result['success']);

    }//end testCastRejectsUnknownMethod()


    /**
     * cast() persists the vote and mirrors to the audit log.
     *
     * @return void
     */
    public function testCastPersistsAndAudits(): void
    {
        $votes   = [];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'in-favor', ['voteMethod' => 'electronic']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $votes);
        $this->assertCount(1, $audited);
        $this->assertSame('vote', $audited[0]['action']);
        $this->assertSame('m1', $audited[0]['actor']);

    }//end testCastPersistsAndAudits()


    /**
     * cast() consults the guard when an agendaItemKoppeling is provided.
     *
     * @return void
     */
    public function testCastConsultsConflictGuardWhenAgendaProvided(): void
    {
        $votes   = [];
        $audited = [];
        $service = $this->makeService($votes, $audited, castAllowed: false);

        $result = $service->cast('r1', 'm1', 'in-favor', ['agendaItemKoppeling' => 'a1']);

        $this->assertFalse($result['success']);
        $this->assertSame([], $votes);
        $this->assertSame([], $audited);

    }//end testCastConsultsConflictGuardWhenAgendaProvided()


    /**
     * Proxy votes without a proxyHolder are rejected.
     *
     * @return void
     */
    public function testCastProxyRequiresProxyHolder(): void
    {
        $votes   = [];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'in-favor', ['voteMethod' => 'proxy']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('proxyHolder', $result['message']);
        $this->assertSame([], $votes);

    }//end testCastProxyRequiresProxyHolder()


    /**
     * Proxy votes are rejected when no ACTIVE proxy record exists from the
     * grantor to the named holder (task-2.4 fail-closed gate).
     *
     * @return void
     */
    public function testCastProxyRejectsWithoutActiveProxyRecord(): void
    {
        // Store contains only a revoked proxy → gate must reject.
        $votes   = [
            [
                'id'               => 'p1',
                'grantorKoppeling' => 'm1',
                'holderKoppeling'  => 'm2',
                'status'           => 'revoked',
            ],
        ];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'in-favor', ['voteMethod' => 'proxy', 'proxyHolder' => 'm2']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('active proxy', $result['message']);
        $this->assertSame([], $audited);

    }//end testCastProxyRejectsWithoutActiveProxyRecord()


    /**
     * Proxy votes persist when an ACTIVE proxy from grantor to holder exists.
     *
     * @return void
     */
    public function testCastProxyPersistsWithActiveProxyRecord(): void
    {
        $votes   = [
            [
                'id'               => 'p1',
                'grantorKoppeling' => 'm1',
                'holderKoppeling'  => 'm2',
                'status'           => 'active',
            ],
        ];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->cast('r1', 'm1', 'in-favor', ['voteMethod' => 'proxy', 'proxyHolder' => 'm2']);

        $this->assertTrue($result['success']);
        $this->assertSame('m2', $result['vote']['proxyHolder']);
        $this->assertCount(1, $audited);

    }//end testCastProxyPersistsWithActiveProxyRecord()


    /**
     * tally() counts votes per enum.
     *
     * @return void
     */
    public function testTallyCountsVotesPerEnum(): void
    {
        $votes = [
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'against'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'abstain'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'absent'],
            ['resolutionKoppeling' => 'r2', 'vote' => 'in-favor'],
        ];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->tally('r1');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['tally']['in-favor']);
        $this->assertSame(1, $result['tally']['against']);
        $this->assertSame(1, $result['tally']['abstain']);
        $this->assertSame(1, $result['tally']['absent']);
        $this->assertSame(4, $result['cast']);
        $this->assertSame(5, $result['total']);

    }//end testTallyCountsVotesPerEnum()


    /**
     * audit() returns the raw vote rows filtered to one resolution.
     *
     * @return void
     */
    public function testAuditReturnsOnlyResolutionRows(): void
    {
        $votes = [
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r2', 'vote' => 'against'],
        ];
        $audited = [];
        $service = $this->makeService($votes, $audited);

        $result = $service->audit('r1');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['votes']);
        $this->assertSame('in-favor', $result['votes'][0]['vote']);

    }//end testAuditReturnsOnlyResolutionRows()


}//end class
