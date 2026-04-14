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
use OCA\OpenRegister\Service\ObjectService;
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
     * Mock OriPublicationService.
     *
     * @var OriPublicationService&MockObject
     */
    private OriPublicationService&MockObject $oriService;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock MotionService.
     *
     * @var MotionService&MockObject
     */
    private MotionService&MockObject $motionService;

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
        $this->oriService    = $this->createMock(OriPublicationService::class);
        $this->motionService = $this->createMock(MotionService::class);

        $this->objectService = $this->createMock(ObjectService::class);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container
            ->method('get')
            ->willReturn($this->objectService);

        $this->service = new VotingService(
            container: $this->container,
            oriPublicationService: $this->oriService,
            logger: $this->logger,
            motionService: $this->motionService,
        );

    }//end setUp()

    /**
     * Test that checkQuorum returns true when active participants meet the quorum.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCheckQuorumMet(): void
    {
        $meeting = [
            'quorumRequired' => 3,
            'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
        ];

        $participants = [
            'results' => [
                ['displayName' => 'A', 'leftAt' => null],
                ['displayName' => 'B', 'leftAt' => null],
                ['displayName' => 'C', 'leftAt' => null],
            ],
        ];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($meeting);

        $this->objectService->expects($this->once())
            ->method('findObjects')
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
        $meeting = [
            'quorumRequired' => 5,
            'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
        ];

        $participants = [
            'results' => [
                ['displayName' => 'A', 'leftAt' => null],
                ['displayName' => 'B', 'leftAt' => '2025-04-14T20:00:00+02:00'],
                ['displayName' => 'C', 'leftAt' => null],
            ],
        ];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($meeting);

        $this->objectService->expects($this->once())
            ->method('findObjects')
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

        $meeting = [
            'quorumRequired' => 10,
            'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
        ];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($meeting);

        // Only 1 active participant — quorum not met.
        $this->objectService->method('findObjects')
            ->willReturn(['results' => [['displayName' => 'A', 'leftAt' => null]]]);

        $this->service->openVotingRound('motion-uuid', 'meeting-uuid', 'for-against-abstain', false, null);

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
        $round = [
            'openedAt' => '2025-04-14T20:05:00+02:00',
            'closedAt' => null,
        ];

        $existingVote = [
            'id'      => 'existing-vote-uuid',
            'value'   => 'against',
            'isProxy' => false,
        ];

        $savedVote = ['value' => 'for', 'isProxy' => false, 'castAt' => '2025-04-14T20:08:00+02:00'];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($round);

        // isProxy=false → only one findObjects call for existing vote lookup.
        $this->objectService->expects($this->once())
            ->method('findObjects')
            ->willReturn(['results' => [$existingVote]]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn($obj) => ($obj['value'] ?? '') === 'for'),
                $this->anything(),
            )
            ->willReturn($savedVote);

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
        $this->expectExceptionMessage('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');

        $round = [
            'openedAt' => '2025-04-14T20:05:00+02:00',
            'closedAt' => null,
        ];

        $existingProxyVote = [
            'id'      => 'proxy-vote-uuid',
            'value'   => 'for',
            'isProxy' => true,
            'relations' => [
                ['schema' => 'voting-round', 'id' => 'round-uuid'],
                ['schema' => 'participant',  'id' => 'delegator-uuid', 'type' => 'delegator'],
            ],
        ];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($round);

        // First findObjects call is for proxy check — return an existing proxy.
        $this->objectService->expects($this->once())
            ->method('findObjects')
            ->willReturn(['results' => [$existingProxyVote]]);

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
        $votes = [
            'results' => [
                ['value' => 'for',     'weight' => 1],
                ['value' => 'for',     'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ],
        ];

        $round = ['openedAt' => '2025-04-14T20:05:00+02:00'];

        $this->objectService->method('findObjects')->willReturn($votes);
        $this->objectService->method('getObject')->willReturn($round);
        $this->objectService->method('saveObject')->willReturn($round);

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
        $votes = [
            'results' => [
                ['value' => 'for',     'weight' => 1],
                ['value' => 'against', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ],
        ];

        $round = ['openedAt' => '2025-04-14T20:05:00+02:00'];

        $this->objectService->method('findObjects')->willReturn($votes);
        $this->objectService->method('getObject')->willReturn($round);
        $this->objectService->method('saveObject')->willReturn($round);

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
        $votes = [
            'results' => [
                ['value' => 'for',     'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ],
        ];

        $round = ['openedAt' => '2025-04-14T20:05:00+02:00'];

        $this->objectService->method('findObjects')->willReturn($votes);
        $this->objectService->method('getObject')->willReturn($round);
        $this->objectService->method('saveObject')->willReturn($round);

        $result = $this->service->tallyResults('round-uuid');

        self::assertSame('tied', $result['result']);

    }//end testTallyResultsTied()

    /**
     * Test that closeVotingRound closes the round and triggers motion lifecycle update.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCloseVotingRoundTransitionsLifecycle(): void
    {
        $round = [
            'openedAt'  => '2025-04-14T20:05:00+02:00',
            'closedAt'  => null,
            'relations' => [['schema' => 'motion', 'id' => 'motion-uuid']],
        ];

        $motion = ['lifecycle' => 'voting', 'title' => 'Test Motion'];

        // Return round for tally + close + getObject, motion for lifecycle update.
        $this->objectService->method('getObject')
            ->willReturnCallback(function() use ($round, $motion) {
                static $calls = 0;
                $calls++;
                // Third getObject call (after tally and close) fetches the motion.
                if ($calls === 3) {
                    return $motion;
                }
                return $round;
            });

        $this->objectService->method('findObjects')
            ->willReturn(['results' => [['value' => 'against', 'weight' => 1]]]);

        $this->objectService->method('saveObject')->willReturn($round);

        $this->oriService->method('publish');


        // Should complete without throwing.
        $this->service->closeVotingRound('round-uuid');
        $this->addToAssertionCount(1);

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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Deelnemer met rol 'observer' kan geen volmacht ontvangen");

        $delegate = [
            'displayName' => 'Observer X',
            'role'        => 'observer',
        ];

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($delegate);

        $this->service->grantProxy('round-uuid', 'granter-uuid', 'delegate-uuid');

    }//end testGrantProxyRejectsObserverRole()
}//end class
