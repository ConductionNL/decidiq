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
     * Mock OriPublicationService.
     *
     * @var OriPublicationService&MockObject
     */
    private OriPublicationService&MockObject $oriService;

    /**
     * Mock ObjectService (stdClass with added methods matching OpenRegister API).
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

        $this->container  = $this->createMock(ContainerInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->oriService = $this->createMock(OriPublicationService::class);

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'getObject', 'findObjects', 'saveObject'])
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container
            ->method('get')
            ->willReturn($this->objectService);

        $this->service = new VotingService(
            container: $this->container,
            oriPublicationService: $this->oriService,
            logger: $this->logger,
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
        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn(
                [
                    'quorumRequired' => 3,
                    'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
                ]
            );

        $this->objectService->expects($this->once())
            ->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        ['displayName' => 'A', 'leftAt' => null],
                        ['displayName' => 'B', 'leftAt' => null],
                        ['displayName' => 'C', 'leftAt' => null],
                    ],
                ]
            );

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
        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn(
                [
                    'quorumRequired' => 5,
                    'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
                ]
            );

        $this->objectService->expects($this->once())
            ->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        ['displayName' => 'A', 'leftAt' => null],
                        ['displayName' => 'B', 'leftAt' => '2025-04-14T20:00:00+02:00'],
                        ['displayName' => 'C', 'leftAt' => null],
                    ],
                ]
            );

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

        $this->objectService->method('getObject')
            ->willReturn(
                [
                    'quorumRequired' => 10,
                    'relations'      => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
                ]
            );

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
        $this->objectService->method('getObject')
            ->willReturn(['openedAt' => '2025-04-14T20:05:00+02:00', 'closedAt' => null]);

        $this->objectService->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        [
                            'id'      => 'existing-vote-uuid',
                            'uuid'    => 'existing-vote-uuid',
                            'value'   => 'against',
                            'isProxy' => false,
                        ],
                    ],
                ]
            );

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn(['value' => 'for', 'isProxy' => false, 'castAt' => '2025-04-14T20:08:00+02:00']);

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

        $this->objectService->method('getObject')
            ->willReturn(['openedAt' => '2025-04-14T20:05:00+02:00', 'closedAt' => null]);

        // Proxy check returns an existing proxy vote for the delegator.
        $this->objectService->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        [
                            'id'        => 'proxy-vote-uuid',
                            'value'     => 'for',
                            'isProxy'   => true,
                            'relations' => [
                                ['schema' => 'voting-round', 'id' => 'round-uuid'],
                                ['schema' => 'participant',  'id' => 'delegator-uuid', 'type' => 'delegator'],
                            ],
                        ],
                    ],
                ]
            );

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
        $this->objectService->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        ['value' => 'for',     'weight' => 1],
                        ['value' => 'for',     'weight' => 1],
                        ['value' => 'against', 'weight' => 1],
                    ],
                ]
            );

        $this->objectService->method('getObject')
            ->willReturn(['openedAt' => '2025-04-14T20:05:00+02:00']);

        $this->objectService->method('saveObject')->willReturn([]);

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
        $this->objectService->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        ['value' => 'for',     'weight' => 1],
                        ['value' => 'against', 'weight' => 1],
                        ['value' => 'against', 'weight' => 1],
                    ],
                ]
            );

        $this->objectService->method('getObject')
            ->willReturn(['openedAt' => '2025-04-14T20:05:00+02:00']);

        $this->objectService->method('saveObject')->willReturn([]);

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
        $this->objectService->method('findObjects')
            ->willReturn(
                [
                    'results' => [
                        ['value' => 'for',     'weight' => 1],
                        ['value' => 'against', 'weight' => 1],
                    ],
                ]
            );

        $this->objectService->method('getObject')
            ->willReturn(['openedAt' => '2025-04-14T20:05:00+02:00']);

        $this->objectService->method('saveObject')->willReturn([]);

        $result = $this->service->tallyResults('round-uuid');

        self::assertSame('tied', $result['result']);

    }//end testTallyResultsTied()

    /**
     * Test that closeVotingRound triggers motion lifecycle transition to 'rejected'.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCloseVotingRoundTransitionsLifecycle(): void
    {
        $roundData = [
            'openedAt'     => '2025-04-14T20:05:00+02:00',
            'closedAt'     => null,
            'votesFor'     => 0,
            'votesAgainst' => 0,
            'votesAbstain' => 0,
            'result'       => null,
            'relations'    => [['schema' => 'motion', 'id' => 'motion-uuid']],
        ];
        $motionData = ['title' => 'Test Motion', 'lifecycle' => 'voting', 'status' => 'voting'];

        // One vote against → result = 'rejected'.
        $this->objectService->method('findObjects')
            ->willReturn(['results' => [['value' => 'against', 'weight' => 1]]]);

        // getObject is called multiple times: round (tallyResults), round (closeVotingRound), motion.
        $this->objectService->method('getObject')
            ->willReturnOnConsecutiveCalls($roundData, $roundData, $motionData);

        $savedObjects = [];
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function() use (&$savedObjects, $roundData, $motionData) {
                    $args   = func_get_args();
                    $schema = $args[1] ?? null;
                    $object = $args[2] ?? null;
                    if ($schema !== null && $object !== null) {
                        $savedObjects[$schema] = $object;
                    }

                    return $object ?? [];
                }
            );

        $this->oriService->method('publish')->willReturn(null);

        $this->service->closeVotingRound('round-uuid');

        self::assertArrayHasKey('motion', $savedObjects);
        self::assertSame('rejected', $savedObjects['motion']['lifecycle']);

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

        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn(['displayName' => 'Observer X', 'role' => 'observer']);

        $this->service->grantProxy('round-uuid', 'granter-uuid', 'delegate-uuid');

    }//end testGrantProxyRejectsObserverRole()

}//end class
