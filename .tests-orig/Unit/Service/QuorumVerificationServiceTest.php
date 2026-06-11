<?php

/**
 * Unit tests for QuorumVerificationService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\QuorumVerificationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for QuorumVerificationService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */
class QuorumVerificationServiceTest extends TestCase
{


    /**
     * Build a service backed by stubbed ObjectService that returns the given
     * meeting + member rows.
     *
     * @param array<string, mixed>             $meeting Meeting row keyed under its id
     * @param array<int, array<string, mixed>> $members BoardMember rows
     *
     * @return QuorumVerificationService
     */
    private function makeService(array $meeting, array $members): QuorumVerificationService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($meeting): ?ObjectEntity {
                if ($schema === 'board-meeting' && (string) $id === (string) ($meeting['id'] ?? '')) {
                    $entity = $this->createMock(ObjectEntity::class);
                    $entity->method('jsonSerialize')->willReturn($meeting);
                    return $entity;
                }
                return null;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use ($members): array {
                return $members;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new QuorumVerificationService($container, $logger);

    }//end makeService()


    /**
     * Simple-majority quorum: 5 members, 3 present (in-person/remote/proxy) → met.
     *
     * @return void
     */
    public function testSimpleMajorityMet(): void
    {
        $meeting = [
            'id'             => 'm1',
            'boardKoppeling' => 'b1',
            'quorumRule'     => 'simple-majority',
            'attendance'     => [
                ['boardMemberKoppeling' => 'p1', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p2', 'mode' => 'remote'],
                ['boardMemberKoppeling' => 'p3', 'mode' => 'proxy'],
                ['boardMemberKoppeling' => 'p4', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p5', 'mode' => 'absent'],
            ],
        ];

        $members = [
            ['id' => 'p1', 'boardKoppeling' => 'b1'],
            ['id' => 'p2', 'boardKoppeling' => 'b1'],
            ['id' => 'p3', 'boardKoppeling' => 'b1'],
            ['id' => 'p4', 'boardKoppeling' => 'b1'],
            ['id' => 'p5', 'boardKoppeling' => 'b1'],
        ];

        $service = $this->makeService($meeting, $members);
        $quorum  = $service->computeQuorum('m1');

        $this->assertSame(5, $quorum['total']);
        $this->assertSame(3, $quorum['present']);
        $this->assertSame(3, $quorum['threshold']);
        $this->assertTrue($quorum['met']);

    }//end testSimpleMajorityMet()


    /**
     * Two-thirds threshold computed correctly: 7 members → ceil(14/3) = 5.
     *
     * @return void
     */
    public function testTwoThirdsThresholdComputation(): void
    {
        $meeting = [
            'id'             => 'm2',
            'boardKoppeling' => 'b1',
            'quorumRule'     => 'qualified-majority-two-thirds',
            'attendance'     => [
                ['boardMemberKoppeling' => 'p1', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p2', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p3', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p4', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p5', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p6', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p7', 'mode' => 'absent'],
            ],
        ];

        $members = [];
        for ($i = 1; $i <= 7; $i++) {
            $members[] = ['id' => 'p'.$i, 'boardKoppeling' => 'b1'];
        }

        $service = $this->makeService($meeting, $members);
        $quorum  = $service->computeQuorum('m2');

        $this->assertSame(7, $quorum['total']);
        $this->assertSame(5, $quorum['threshold']);
        $this->assertSame(3, $quorum['present']);
        $this->assertFalse($quorum['met']);

    }//end testTwoThirdsThresholdComputation()


    /**
     * Explicit `quorumRequired` overrides the rule-driven computation.
     *
     * @return void
     */
    public function testExplicitQuorumRequiredOverridesRule(): void
    {
        $meeting = [
            'id'             => 'm3',
            'boardKoppeling' => 'b1',
            'quorumRule'     => 'simple-majority',
            'quorumRequired' => 2,
            'attendance'     => [
                ['boardMemberKoppeling' => 'p1', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p2', 'mode' => 'remote'],
                ['boardMemberKoppeling' => 'p3', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p4', 'mode' => 'absent'],
                ['boardMemberKoppeling' => 'p5', 'mode' => 'absent'],
            ],
        ];

        $members = [];
        for ($i = 1; $i <= 5; $i++) {
            $members[] = ['id' => 'p'.$i, 'boardKoppeling' => 'b1'];
        }

        $service = $this->makeService($meeting, $members);
        $quorum  = $service->computeQuorum('m3');

        $this->assertSame(2, $quorum['threshold']);
        $this->assertTrue($quorum['met']);

    }//end testExplicitQuorumRequiredOverridesRule()


    /**
     * Missing meeting returns empty report and zero quorum.
     *
     * @return void
     */
    public function testMissingMeetingProducesZeroQuorum(): void
    {
        $service = $this->makeService(['id' => 'other'], []);
        $quorum  = $service->computeQuorum('not-found');

        $this->assertSame(0, $quorum['total']);
        $this->assertFalse($quorum['met']);

    }//end testMissingMeetingProducesZeroQuorum()


    /**
     * verifyAttendance accepts in-person/remote/proxy with a real meeting and
     * rejects unknown participant types.
     *
     * @return void
     */
    public function testVerifyAttendanceParticipantType(): void
    {
        $meeting = ['id' => 'mp', 'boardKoppeling' => 'b1', 'attendance' => []];
        $service = $this->makeService($meeting, []);

        $this->assertTrue($service->verifyAttendance('mp', 'in-person'));
        $this->assertTrue($service->verifyAttendance('mp', 'absent'));
        $this->assertFalse($service->verifyAttendance('mp', 'astral'));

    }//end testVerifyAttendanceParticipantType()


    /**
     * Unanimous quorum requires all members present.
     *
     * @return void
     */
    public function testUnanimousQuorumRule(): void
    {
        $meeting = [
            'id'             => 'mu',
            'boardKoppeling' => 'b1',
            'quorumRule'     => 'unanimous',
            'attendance'     => [
                ['boardMemberKoppeling' => 'p1', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p2', 'mode' => 'in-person'],
                ['boardMemberKoppeling' => 'p3', 'mode' => 'in-person'],
            ],
        ];

        $members = [
            ['id' => 'p1', 'boardKoppeling' => 'b1'],
            ['id' => 'p2', 'boardKoppeling' => 'b1'],
            ['id' => 'p3', 'boardKoppeling' => 'b1'],
        ];

        $service = $this->makeService($meeting, $members);
        $quorum  = $service->computeQuorum('mu');

        $this->assertSame(3, $quorum['threshold']);
        $this->assertTrue($quorum['met']);

    }//end testUnanimousQuorumRule()


}//end class
