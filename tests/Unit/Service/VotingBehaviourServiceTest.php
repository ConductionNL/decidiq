<?php

/**
 * Unit tests for VotingBehaviourService.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\VotingBehaviourService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for VotingBehaviourService.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var VotingBehaviourService
     */
    private VotingBehaviourService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService (anonymous object).
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped(
            'OpenRegister ObjectService is resolved via DI at call time; '
            .'named-parameter mock for findAll() requires real class stub — '
            .'track at https://codeberg.org/Conduction/decidesk/issues/90'
        );

        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'findAll'])
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new VotingBehaviourService(
            container: $this->container,
        );

    }//end setUp()

    /**
     * Build a mock ObjectEntity returning $data from jsonSerialize().
     *
     * @param array<string,mixed> $data
     *
     * @return object
     */
    private function makeEntity(array $data): object
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;
    }

    /**
     * getStats() returns zeroed stats when no closed rounds exist.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsReturnsZeroedStatsWhenNoClosedRounds(): void
    {
        // All rounds are open (closedAt is null).
        $openRound = $this->makeEntity(['id' => 'r1', 'closedAt' => null]);

        $this->objectService
            ->method('findAll')
            ->willReturn([$openRound]);

        $stats = $this->service->getStats(
            participantId: 'participant-uuid',
            governanceBodyId: 'gb-uuid',
        );

        self::assertSame('participant-uuid', $stats['participantId']);
        self::assertSame('gb-uuid', $stats['governanceBodyId']);
        self::assertSame(0, $stats['totalRounds']);
        self::assertSame(0, $stats['participated']);
        self::assertSame(0.0, $stats['participationRate']);
        self::assertSame(0, $stats['votesFor']);
        self::assertSame(0, $stats['votesAgainst']);
        self::assertSame(0, $stats['votesAbstain']);

    }//end testGetStatsReturnsZeroedStatsWhenNoClosedRounds()

    /**
     * getStats() counts for/against/abstain votes in closed rounds.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsCountsVoteValuesInClosedRounds(): void
    {
        $closedRound = $this->makeEntity(['id' => 'round-1', 'closedAt' => '2026-04-01T10:00:00Z']);
        $voteFor     = $this->makeEntity(['value' => 'for',     'isProxy' => false]);
        $voteAgainst = $this->makeEntity(['value' => 'against', 'isProxy' => false]);
        $voteAbstain = $this->makeEntity(['value' => 'abstain', 'isProxy' => true]);

        $this->objectService
            ->expects($this->exactly(2))
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                [$closedRound],
                [$voteFor, $voteAgainst, $voteAbstain],
            );

        $stats = $this->service->getStats(
            participantId: 'p1',
            governanceBodyId: 'gb1',
        );

        self::assertSame(1, $stats['totalRounds']);
        self::assertSame(1, $stats['participated']);
        self::assertSame(100.0, $stats['participationRate']);
        self::assertSame(1, $stats['votesFor']);
        self::assertSame(1, $stats['votesAgainst']);
        self::assertSame(1, $stats['votesAbstain']);
        self::assertSame(1, $stats['proxiesGiven']);

    }//end testGetStatsCountsVoteValuesInClosedRounds()

    /**
     * getStats() participation rate is 0.0 when zero rounds found.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsParticipationRateIsZeroWhenNoRounds(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $stats = $this->service->getStats(
            participantId: 'p1',
            governanceBodyId: 'gb1',
        );

        self::assertSame(0, $stats['totalRounds']);
        self::assertSame(0.0, $stats['participationRate']);

    }//end testGetStatsParticipationRateIsZeroWhenNoRounds()

}//end class
