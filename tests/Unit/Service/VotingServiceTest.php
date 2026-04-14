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
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ObjectService.
     *
     * @var MockObject
     */
    private MockObject $objectService;

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
    private OriPublicationService&MockObject $oriPublicationService;

    /**
     * Set up mocks and service.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(className: \stdClass::class)
            ->addMethods(['getObject', 'findObjects', 'saveObject', 'addRelation'])
            ->getMock();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->container
            ->method('get')
            ->willReturnCallback(
                    function (string $id) {
                        return match (true) {
                            $id === 'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
                            default => $this->createMock(originalClassName: \stdClass::class),
                        };
                    }
                    );

        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->motionService = $this->createMock(originalClassName: MotionService::class);
        $this->oriPublicationService = $this->createMock(originalClassName: OriPublicationService::class);

        $this->service = new VotingService(
            container: $this->container,
            logger: $this->logger,
            motionService: $this->motionService,
            oriPublicationService: $this->oriPublicationService
        );
    }//end setUp()

    /**
     * Test checkQuorum returns true when active participant count meets required quorum.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCheckQuorumMet(): void
    {
        $meeting = [
            'id'             => 'meeting-1',
            'quorumRequired' => 3,
            'relations'      => ['governance-body' => ['id' => 'gb-1']],
        ];

        $participants = [
            ['id' => 'p1', 'leftAt' => null],
            ['id' => 'p2', 'leftAt' => null],
            ['id' => 'p3', 'leftAt' => null],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($meeting);

        $this->objectService
            ->method('findObjects')
            ->willReturn($participants);

        $result = $this->service->checkQuorum(meetingId: 'meeting-1');
        $this->assertTrue(condition: $result);
    }//end testCheckQuorumMet()

    /**
     * Test checkQuorum returns false when active participant count is below quorum.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCheckQuorumNotMet(): void
    {
        $meeting = [
            'id'             => 'meeting-2',
            'quorumRequired' => 5,
            'relations'      => ['governance-body' => ['id' => 'gb-2']],
        ];

        $participants = [
            ['id' => 'p1', 'leftAt' => null],
            ['id' => 'p2', 'leftAt' => null],
            ['id' => 'p3', 'leftAt' => '2025-04-14T20:00:00+02:00'],
        // Left.
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($meeting);

        $this->objectService
            ->method('findObjects')
            ->willReturn($participants);

        $result = $this->service->checkQuorum(meetingId: 'meeting-2');
        $this->assertFalse(condition: $result);
    }//end testCheckQuorumNotMet()

    /**
     * Test openVotingRound throws RuntimeException when quorum is not met.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testOpenVotingRoundBlockedWhenQuorumNotMet(): void
    {
        $meeting = [
            'id'             => 'meeting-3',
            'quorumRequired' => 10,
            'relations'      => ['governance-body' => ['id' => 'gb-3']],
        ];

        $participants = [
            ['id' => 'p1', 'leftAt' => null],
            ['id' => 'p2', 'leftAt' => null],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($meeting);

        $this->objectService
            ->method('findObjects')
            ->willReturn($participants);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Quorum niet bereikt');

        $this->service->openVotingRound(
            motionId: 'motion-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            actorId: 'user1',
            meetingId: 'meeting-3'
        );
    }//end testOpenVotingRoundBlockedWhenQuorumNotMet()

    /**
     * Test castVote updates an existing vote instead of creating a duplicate.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCastVoteUpdatesDuplicate(): void
    {
        $openRound = [
            'id'       => 'round-1',
            'closedAt' => null,
        ];

        $existingVote = [
            'id'      => 'vote-1',
            'value'   => 'for',
            'weight'  => 1,
            'isProxy' => false,
            'castAt'  => '2025-04-14T20:06:00+02:00',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($openRound);

        $this->objectService
            ->method('findObjects')
            ->willReturnCallback(
                    function ($register, $schema, $filters) use ($existingVote) {
                        if ($schema === 'vote' && ($filters['isProxy'] ?? true) === false) {
                            return [$existingVote];
                        }

                        return [];
                    }
                    );

        $savedObject = null;
        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObject) {
                        $savedObject = $object;
                        return $object;
                    }
                    );

        $result = $this->service->castVote(votingRoundId: 'round-1', participantId: 'participant-1', value: 'against', isProxy: false);

        $this->assertEquals(expected: 'against', actual: $savedObject['value']);
        $this->assertEquals(expected: 'vote-1', actual: $savedObject['id']);
    }//end testCastVoteUpdatesDuplicate()

    /**
     * Test castVote proxy enforcement: second proxy for same delegator throws exception.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCastVoteProxyOnePerRoundEnforced(): void
    {
        $openRound = [
            'id'       => 'round-2',
            'closedAt' => null,
        ];

        $existingProxyVote = [
            'id'        => 'vote-proxy-1',
            'value'     => 'for',
            'isProxy'   => true,
            'relations' => [
                'delegator' => ['id' => 'delegator-1'],
            ],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($openRound);

        $this->objectService
            ->method('findObjects')
            ->willReturn([$existingProxyVote]);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/already has a proxy vote/');

        $this->service->castVote(votingRoundId: 'round-2', participantId: 'delegate-1', value: 'for', isProxy: true, delegatorId: 'delegator-1');
    }//end testCastVoteProxyOnePerRoundEnforced()

    /**
     * Test tallyResults correctly counts votes and returns "adopted" when for > against.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsAdopted(): void
    {
        $round = ['id' => 'round-3', 'closedAt' => null];
        $votes = [
            ['value' => 'for', 'weight' => 1],
            ['value' => 'for', 'weight' => 1],
            ['value' => 'for', 'weight' => 1],
            ['value' => 'against', 'weight' => 1],
            ['value' => 'abstain', 'weight' => 1],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($round);

        $this->objectService
            ->method('findObjects')
            ->willReturn($votes);

        $this->objectService
            ->method('saveObject')
            ->willReturnArgument(2);

        $tally = $this->service->tallyResults(votingRoundId: 'round-3');

        $this->assertEquals(expected: 'adopted', actual: $tally['result']);
        $this->assertEquals(expected: 3, actual: $tally['votesFor']);
        $this->assertEquals(expected: 1, actual: $tally['votesAgainst']);
        $this->assertEquals(expected: 1, actual: $tally['votesAbstain']);
    }//end testTallyResultsAdopted()

    /**
     * Test tallyResults returns "rejected" when against > for.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsRejected(): void
    {
        $round = ['id' => 'round-4', 'closedAt' => null];
        $votes = [
            ['value' => 'for', 'weight' => 1],
            ['value' => 'against', 'weight' => 1],
            ['value' => 'against', 'weight' => 1],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($round);

        $this->objectService
            ->method('findObjects')
            ->willReturn($votes);

        $this->objectService
            ->method('saveObject')
            ->willReturnArgument(2);

        $tally = $this->service->tallyResults(votingRoundId: 'round-4');
        $this->assertEquals(expected: 'rejected', actual: $tally['result']);
    }//end testTallyResultsRejected()

    /**
     * Test tallyResults returns "tied" when for === against.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testTallyResultsTied(): void
    {
        $round = ['id' => 'round-5', 'closedAt' => null];
        $votes = [
            ['value' => 'for', 'weight' => 1],
            ['value' => 'against', 'weight' => 1],
        ];

        $this->objectService
            ->method('getObject')
            ->willReturn($round);

        $this->objectService
            ->method('findObjects')
            ->willReturn($votes);

        $this->objectService
            ->method('saveObject')
            ->willReturnArgument(2);

        $tally = $this->service->tallyResults(votingRoundId: 'round-5');
        $this->assertEquals(expected: 'tied', actual: $tally['result']);
    }//end testTallyResultsTied()

    /**
     * Test closeVotingRound transitions Motion lifecycle to "adopted" when result is adopted.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testCloseVotingRoundTransitionsLifecycle(): void
    {
        $round = [
            'id'        => 'round-6',
            'closedAt'  => null,
            'relations' => ['motion' => [['id' => 'motion-close-1']]],
        ];

        $votes = [
            ['value' => 'for', 'weight' => 1],
            ['value' => 'for', 'weight' => 1],
        ];

        $savedObjects = [];
        $this->objectService
            ->method('getObject')
            ->willReturn($round);

        $this->objectService
            ->method('findObjects')
            ->willReturn($votes);

        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                    function ($register, $schema, $object) use (&$savedObjects) {
                        $savedObjects[] = $object;
                        return $object;
                    }
                    );

        $this->motionService
            ->expects($this->once())
            ->method('transitionLifecycle')
            ->with('motion-close-1', 'motion', 'adopted', '');

        $this->oriPublicationService
            ->method('publish')
            ->willReturn(null);

        $this->service->closeVotingRound(votingRoundId: 'round-6');
    }//end testCloseVotingRoundTransitionsLifecycle()

    /**
     * Test grantProxy throws RuntimeException when receiver is an observer.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
     *
     * @return void
     */
    public function testGrantProxyObserverRejected(): void
    {
        $round = [
            'id'       => 'round-7',
            'openedAt' => null,
        // Round not yet open — proxy can be granted.
            'closedAt' => null,
            'notes'    => [],
        ];

        $observer = [
            'id'   => 'participant-observer',
            'role' => 'observer',
        ];

        $this->objectService
            ->method('getObject')
            ->willReturnCallback(
                    function ($register, $schema, $uuid) use ($round, $observer) {
                        if ($schema === 'voting-round') {
                            return $round;
                        }

                        if ($schema === 'participant') {
                            return $observer;
                        }

                        return null;
                    }
                    );

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessageMatches(regularExpression: "/cannot receive a proxy/");

        $this->service->grantProxy(votingRoundId: 'round-7', fromParticipantId: 'from-participant', toParticipantId: 'participant-observer');
    }//end testGrantProxyObserverRejected()
}//end class
