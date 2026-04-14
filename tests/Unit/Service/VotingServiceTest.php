<?php

/**
 * Unit tests for VotingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\VotingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */
class VotingServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var VotingService
     */
    private VotingService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock MotionService.
     *
     * @var MotionService&MockObject
     */
    private MotionService&MockObject $motionService;

    /**
     * Mock OriPublicationService.
     *
     * @var OriPublicationService&MockObject
     */
    private OriPublicationService&MockObject $oriService;

    /**
     * Mock ObjectService.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container     = $this->createMock(ContainerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->motionService = $this->createMock(MotionService::class);
        $this->oriService    = $this->createMock(OriPublicationService::class);

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'find', 'findAll', 'saveObject'])
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container
            ->method('get')
            ->willReturn($this->objectService);

        $this->service = new VotingService(
            container: $this->container,
            motionService: $this->motionService,
            oriPublicationService: $this->oriService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Build a mock ObjectEntity with getObject() and getUuid() methods.
     *
     * @param array<string,mixed> $data Object data
     * @param string              $uuid Object UUID
     *
     * @return object
     */
    private function mockObjectEntity(array $data, string $uuid='test-uuid'): object
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'getUuid'])
            ->getMock();
        $entity->method('getObject')->willReturn($data);
        $entity->method('getUuid')->willReturn($uuid);
        return $entity;

    }//end mockObjectEntity()

    /**
     * Test that checkQuorum returns true when active participants meet the quorum.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCheckQuorumMet(): void
    {
        $meetingEntity = $this->mockObjectEntity(['quorumRequired' => 3], 'meeting-uuid');

        $participants = [
            $this->mockObjectEntity(['displayName' => 'A', 'leftAt' => null]),
            $this->mockObjectEntity(['displayName' => 'B', 'leftAt' => null]),
            $this->mockObjectEntity(['displayName' => 'C', 'leftAt' => null]),
        ];

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($meetingEntity);

        $this->objectService->expects($this->once())
            ->method('findAll')
            ->willReturn($participants);

        self::assertTrue($this->service->checkQuorum('meeting-uuid'));

    }//end testCheckQuorumMet()

    /**
     * Test that checkQuorum returns false when not enough active participants.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCheckQuorumNotMet(): void
    {
        $meetingEntity = $this->mockObjectEntity(['quorumRequired' => 5], 'meeting-uuid');

        $participants = [
            $this->mockObjectEntity(['displayName' => 'A', 'leftAt' => null]),
            $this->mockObjectEntity(['displayName' => 'B', 'leftAt' => '2025-04-14T20:00:00+02:00']),
            $this->mockObjectEntity(['displayName' => 'C', 'leftAt' => null]),
        ];

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($meetingEntity);

        $this->objectService->expects($this->once())
            ->method('findAll')
            ->willReturn($participants);

        self::assertFalse($this->service->checkQuorum('meeting-uuid'));

    }//end testCheckQuorumNotMet()

    /**
     * Test that openVotingRound throws when quorum is not met.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testOpenVotingRoundBlocksOnQuorumFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quorum niet bereikt');

        $motionEntity = $this->mockObjectEntity([
            'lifecycle'  => 'debating',
            'relations'  => [['schema' => 'meeting', 'id' => 'meeting-uuid']],
        ], 'motion-uuid');

        $meetingEntity = $this->mockObjectEntity(['quorumRequired' => 10], 'meeting-uuid');

        $this->objectService->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($motionEntity, $meetingEntity);

        // Only 1 active participant — quorum not met.
        $this->objectService->method('findAll')
            ->willReturn([$this->mockObjectEntity(['displayName' => 'A', 'leftAt' => null])]);

        $this->service->openVotingRound('motion-uuid', 'for-against-abstain', false, null);

    }//end testOpenVotingRoundBlocksOnQuorumFailure()

    /**
     * Test that castVote overwrites an existing vote (duplicate update).
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCastVoteOverwritesDuplicate(): void
    {
        $roundEntity = $this->mockObjectEntity([
            'openedAt' => '2025-04-14T20:05:00+02:00',
            'closedAt' => null,
        ], 'round-uuid');

        $existingVoteEntity = $this->mockObjectEntity([
            'id'      => 'existing-vote-uuid',
            'value'   => 'against',
            'isProxy' => false,
            'relations' => [
                ['schema' => 'voting-round', 'id' => 'round-uuid'],
                ['schema' => 'participant',  'id' => 'participant-uuid'],
            ],
        ], 'existing-vote-uuid');

        $savedVoteEntity = $this->mockObjectEntity(['value' => 'for', 'isProxy' => false, 'castAt' => '2025-04-14T20:08:00+02:00'], 'existing-vote-uuid');

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($roundEntity);

        $this->objectService->expects($this->exactly(2))
            ->method('findAll')
            ->willReturnOnConsecutiveCalls([], [$existingVoteEntity]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(fn($obj) => ($obj['value'] ?? '') === 'for'),
                $this->anything(),
                $this->anything(),
                'existing-vote-uuid',
            )
            ->willReturn($savedVoteEntity);

        $result = $this->service->castVote('round-uuid', 'participant-uuid', 'for', false, null);
        self::assertSame('for', $result['value']);

    }//end testCastVoteOverwritesDuplicate()

    /**
     * Test that castVote enforces one-proxy-per-round rule.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCastVoteEnforcesOneProxyPerRound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has a proxy vote');

        $roundEntity = $this->mockObjectEntity([
            'openedAt' => '2025-04-14T20:05:00+02:00',
            'closedAt' => null,
        ], 'round-uuid');

        $existingProxyVote = $this->mockObjectEntity([
            'id'      => 'proxy-vote-uuid',
            'value'   => 'for',
            'isProxy' => true,
            'relations' => [
                ['schema' => 'voting-round', 'id' => 'round-uuid'],
                ['schema' => 'participant',  'id' => 'delegator-uuid'],
            ],
        ], 'proxy-vote-uuid');

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($roundEntity);

        // First findAll call is for proxy check — return an existing proxy.
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->willReturn([$existingProxyVote]);

        $this->service->castVote('round-uuid', 'delegate-uuid', 'for', true, 'delegator-uuid');

    }//end testCastVoteEnforcesOneProxyPerRound()

    /**
     * Test that tallyResults returns 'adopted' when votes for exceed votes against.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsAdopted(): void
    {
        $voteFor1 = $this->mockObjectEntity([
            'value'  => 'for',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);
        $voteFor2 = $this->mockObjectEntity([
            'value'  => 'for',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);
        $voteAgainst = $this->mockObjectEntity([
            'value'  => 'against',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);

        $roundEntity = $this->mockObjectEntity(['openedAt' => '2025-04-14T20:05:00+02:00'], 'round-uuid');

        $this->objectService->method('findAll')
            ->willReturn([$voteFor1, $voteFor2, $voteAgainst]);

        $this->objectService->method('find')->willReturn($roundEntity);
        $this->objectService->method('saveObject')->willReturn($roundEntity);

        $result = $this->service->tallyResults('round-uuid');

        self::assertSame('adopted', $result['result']);
        self::assertSame(2, $result['votesFor']);
        self::assertSame(1, $result['votesAgainst']);

    }//end testTallyResultsAdopted()

    /**
     * Test that tallyResults returns 'rejected' when votes against exceed votes for.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsRejected(): void
    {
        $voteFor = $this->mockObjectEntity([
            'value'  => 'for',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);
        $voteAgainst1 = $this->mockObjectEntity([
            'value'  => 'against',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);
        $voteAgainst2 = $this->mockObjectEntity([
            'value'  => 'against',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);

        $roundEntity = $this->mockObjectEntity(['openedAt' => '2025-04-14T20:05:00+02:00'], 'round-uuid');

        $this->objectService->method('findAll')
            ->willReturn([$voteFor, $voteAgainst1, $voteAgainst2]);
        $this->objectService->method('find')->willReturn($roundEntity);
        $this->objectService->method('saveObject')->willReturn($roundEntity);

        $result = $this->service->tallyResults('round-uuid');

        self::assertSame('rejected', $result['result']);

    }//end testTallyResultsRejected()

    /**
     * Test that tallyResults returns 'tied' when votes for equal votes against.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsTied(): void
    {
        $voteFor = $this->mockObjectEntity([
            'value'  => 'for',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);
        $voteAgainst = $this->mockObjectEntity([
            'value'  => 'against',
            'weight' => 1,
            'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
        ]);

        $roundEntity = $this->mockObjectEntity(['openedAt' => '2025-04-14T20:05:00+02:00'], 'round-uuid');

        $this->objectService->method('findAll')
            ->willReturn([$voteFor, $voteAgainst]);
        $this->objectService->method('find')->willReturn($roundEntity);
        $this->objectService->method('saveObject')->willReturn($roundEntity);

        $result = $this->service->tallyResults('round-uuid');

        self::assertSame('tied', $result['result']);

    }//end testTallyResultsTied()

    /**
     * Test that closeVotingRound triggers motion lifecycle transition.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCloseVotingRoundTransitionsLifecycle(): void
    {
        $roundEntity = $this->mockObjectEntity([
            'openedAt'  => '2025-04-14T20:05:00+02:00',
            'closedAt'  => null,
            'relations' => [['schema' => 'motion', 'id' => 'motion-uuid']],
        ], 'round-uuid');

        $this->objectService->method('find')->willReturn($roundEntity);
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('saveObject')->willReturn($roundEntity);

        $this->motionService->expects($this->once())
            ->method('transitionLifecycle')
            ->with('motion-uuid', 'motion', 'rejected', 'system');

        $this->oriService->method('publish')->willReturn(null);

        $this->service->closeVotingRound('round-uuid');

    }//end testCloseVotingRoundTransitionsLifecycle()

    /**
     * Test that grantProxy throws when the delegate has an observer role.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testGrantProxyRejectsObserverRole(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("role 'observer' cannot receive a proxy");

        $delegateEntity = $this->mockObjectEntity([
            'displayName' => 'Observer X',
            'role'        => 'observer',
        ], 'delegate-uuid');

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($delegateEntity);

        $this->service->grantProxy('round-uuid', 'granter-uuid', 'delegate-uuid');

    }//end testGrantProxyRejectsObserverRole()
}//end class
