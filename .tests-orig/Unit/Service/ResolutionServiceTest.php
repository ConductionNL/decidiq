<?php

/**
 * Unit tests for ResolutionService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard;
use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\ResolutionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ResolutionService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
 */
class ResolutionServiceTest extends TestCase
{


    /**
     * Build a service backed by in-memory resolutions and votes.
     *
     * @param array<string, array<string, mixed>> &$resolutions Resolution rows keyed by id
     * @param array<int, array<string, mixed>>    &$votes       Vote rows
     * @param bool                                 $quorumMet   Quorum-guard outcome
     *
     * @return ResolutionService
     */
    private function makeService(array &$resolutions, array &$votes, bool $quorumMet=true): ResolutionService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$resolutions): ?ObjectEntity {
                if ($schema !== 'resolution' || isset($resolutions[(string) $id]) === false) {
                    return null;
                }

                $row    = $resolutions[(string) $id];
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$votes): array {
                return $votes;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$resolutions): ObjectEntity {
                $id = $uuid ?? ('r-'.(count($resolutions) + 1));
                $row = array_merge(['id' => $id], $object);
                if ($schema === 'resolution') {
                    $resolutions[$id] = $row;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $guard = $this->createMock(ResolutionLifecycleGuard::class);
        $guard->method('canOpenVote')->willReturn(
            [
                'allowed' => $quorumMet,
                'reason'  => $quorumMet ? 'ok' : 'Quorum not met (2/5, threshold 3).',
                'quorum'  => [],
            ]
        );

        $audit = $this->createMock(AuditLogService::class);

        return new ResolutionService($container, $logger, $guard, $audit);

    }//end makeService()


    /**
     * propose requires a title.
     *
     * @return void
     */
    public function testProposeRequiresTitle(): void
    {
        $resolutions = [];
        $votes       = [];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->propose('m1', []);
        $this->assertFalse($result['success']);

    }//end testProposeRequiresTitle()


    /**
     * propose persists with the meeting koppeling and default threshold.
     *
     * @return void
     */
    public function testProposePersistsWithDefaults(): void
    {
        $resolutions = [];
        $votes       = [];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->propose('m1', ['title' => 'Approve budget', 'type' => 'financial']);

        $this->assertTrue($result['success']);
        $this->assertSame('m1', $result['resolution']['meetingKoppeling']);
        $this->assertSame('simple-majority', $result['resolution']['voteThreshold']);
        $this->assertSame('proposed', $result['resolution']['status']);

    }//end testProposePersistsWithDefaults()


    /**
     * amend on a closed resolution is rejected.
     *
     * @return void
     */
    public function testAmendRejectsClosedResolution(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'adopted', 'title' => 'X'],
        ];
        $votes       = [];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->amend('r1', ['title' => 'Y']);

        $this->assertFalse($result['success']);

    }//end testAmendRejectsClosedResolution()


    /**
     * openVote rejects when quorum is short.
     *
     * @return void
     */
    public function testOpenVoteRejectsWhenQuorumShort(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'proposed', 'meetingKoppeling' => 'm1'],
        ];
        $votes       = [];
        $service     = $this->makeService($resolutions, $votes, quorumMet: false);

        $result = $service->openVote('r1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Quorum not met', $result['message']);

    }//end testOpenVoteRejectsWhenQuorumShort()


    /**
     * openVote transitions to under-discussion when quorum is met.
     *
     * @return void
     */
    public function testOpenVoteTransitionsUnderDiscussion(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'proposed', 'meetingKoppeling' => 'm1'],
        ];
        $votes       = [];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->openVote('r1');

        $this->assertTrue($result['success']);
        $this->assertSame('under-discussion', $resolutions['r1']['status']);

    }//end testOpenVoteTransitionsUnderDiscussion()


    /**
     * conclude tallies votes and marks the resolution adopted under
     * simple-majority when in-favor > cast/2.
     *
     * @return void
     */
    public function testConcludeAdoptsSimpleMajority(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'under-discussion', 'voteThreshold' => 'simple-majority'],
        ];
        $votes       = [
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'against'],
        ];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->conclude('r1');

        $this->assertTrue($result['success']);
        $this->assertSame('adopted', $resolutions['r1']['status']);
        $this->assertSame(2, $result['tally']['in-favor']);
        $this->assertSame(1, $result['tally']['against']);

    }//end testConcludeAdoptsSimpleMajority()


    /**
     * conclude rejects when two-thirds threshold not met.
     *
     * @return void
     */
    public function testConcludeRejectsBelowTwoThirds(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'under-discussion', 'voteThreshold' => 'qualified-majority-two-thirds'],
        ];
        $votes       = [
            // cast=4, threshold = ceil(4*2/3) = 3, in-favor=2 < 3 → rejected.
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'against'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'abstain'],
        ];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->conclude('r1');

        $this->assertTrue($result['success']);
        $this->assertSame('rejected', $resolutions['r1']['status']);

    }//end testConcludeRejectsBelowTwoThirds()


    /**
     * conclude rejects unanimous with any against / abstain.
     *
     * @return void
     */
    public function testConcludeUnanimousFailsWithAbstain(): void
    {
        $resolutions = [
            'r1' => ['id' => 'r1', 'status' => 'under-discussion', 'voteThreshold' => 'unanimous'],
        ];
        $votes       = [
            ['resolutionKoppeling' => 'r1', 'vote' => 'in-favor'],
            ['resolutionKoppeling' => 'r1', 'vote' => 'abstain'],
        ];
        $service     = $this->makeService($resolutions, $votes);

        $result = $service->conclude('r1');

        $this->assertTrue($result['success']);
        $this->assertSame('rejected', $resolutions['r1']['status']);

    }//end testConcludeUnanimousFailsWithAbstain()


}//end class
