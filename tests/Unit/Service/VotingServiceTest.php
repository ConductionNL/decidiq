<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

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
 * Unit tests for VotingService.
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

        $this->container             = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger                = $this->createMock(originalClassName: LoggerInterface::class);
        $this->motionService         = $this->createMock(originalClassName: MotionService::class);
        $this->oriPublicationService = $this->createMock(originalClassName: OriPublicationService::class);

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject', 'saveObject', 'findAll', 'addRelation'])
            ->getMock();

        $notificationService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['sendNotification'])
            ->getMock();

        $this->container->method('get')
            ->willReturnCallback(function ($id) use ($notificationService) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                if ($id === 'OCA\OpenRegister\Service\NotificationService') {
                    return $notificationService;
                }

                return null;
            });

        $this->service = new VotingService(
            container: $this->container,
            logger: $this->logger,
            motionService: $this->motionService,
            oriPublicationService: $this->oriPublicationService,
        );

    }//end setUp()

    /**
     * Test checkQuorum returns true when quorumRequired is zero.
     *
     * @return void
     */
    public function testCheckQuorumReturnsTrueWhenQuorumRequiredIsZero(): void
    {
        $meeting = [
            'id'              => 'meeting-1',
            'quorumRequired'  => 0,
        ];

        $this->objectService->method('findObject')->willReturn($meeting);

        self::assertTrue(condition: $this->service->checkQuorum('meeting-1'));

    }//end testCheckQuorumReturnsTrueWhenQuorumRequiredIsZero()

    /**
     * Test checkQuorum returns true when enough participants are present.
     *
     * @return void
     */
    public function testCheckQuorumReturnsTrueWhenQuorumMet(): void
    {
        $meeting = [
            'id'             => 'meeting-1',
            'quorumRequired' => 3,
        ];

        $this->objectService->method('findObject')->willReturn($meeting);
        $this->objectService->method('findAll')->willReturn([
            'results' => [
                ['id' => 'p1', 'role' => 'member'],
                ['id' => 'p2', 'role' => 'member'],
                ['id' => 'p3', 'role' => 'chair'],
            ],
        ]);

        self::assertTrue(condition: $this->service->checkQuorum('meeting-1'));

    }//end testCheckQuorumReturnsTrueWhenQuorumMet()

    /**
     * Test checkQuorum returns false when not enough participants are present.
     *
     * @return void
     */
    public function testCheckQuorumReturnsFalseWhenQuorumNotMet(): void
    {
        $meeting = [
            'id'             => 'meeting-1',
            'quorumRequired' => 5,
        ];

        $this->objectService->method('findObject')->willReturn($meeting);
        $this->objectService->method('findAll')->willReturn([
            'results' => [
                ['id' => 'p1'],
                ['id' => 'p2'],
            ],
        ]);

        self::assertFalse(condition: $this->service->checkQuorum('meeting-1'));

    }//end testCheckQuorumReturnsFalseWhenQuorumNotMet()

    /**
     * Test openVotingRound throws RuntimeException when quorum is not met.
     *
     * @return void
     */
    public function testOpenVotingRoundThrowsWhenQuorumNotMet(): void
    {
        $meeting = [
            'id'             => 'meeting-1',
            'quorumRequired' => 5,
        ];

        $this->objectService->method('findObject')->willReturn($meeting);
        $this->objectService->method('findAll')->willReturn(['results' => [['id' => 'p1']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quorum niet bereikt');

        $this->service->openVotingRound(
            motionId: 'motion-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            actorId: 'user-1',
            meetingId: 'meeting-1'
        );

    }//end testOpenVotingRoundThrowsWhenQuorumNotMet()

    /**
     * Test castVote returns an existing vote overwrite on duplicate.
     *
     * @return void
     */
    public function testCastVoteUpdatesExistingVoteOnDuplicate(): void
    {
        $openRound = [
            'id'       => 'round-1',
            'closedAt' => null,
            'openedAt' => '2026-04-14T20:00:00+00:00',
        ];

        $existingVote = [
            'id'    => 'vote-1',
            'value' => 'for',
        ];

        $savedVote = ['id' => 'vote-1', 'value' => 'against'];

        $this->objectService->method('findObject')->willReturn($openRound);
        $this->objectService->method('findAll')
            ->willReturnCallback(static function ($register, $schema, $filters) use ($existingVote) {
                // Proxy check returns empty; participant vote returns existing vote.
                if (isset($filters['isProxy']) === true && $filters['isProxy'] === true) {
                    return ['results' => []];
                }

                return ['results' => [$existingVote]];
            });
        $this->objectService->method('saveObject')->willReturn($savedVote);
        $this->objectService->method('addRelation')->willReturn(null);

        $vote = $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'participant-1',
            value: 'against',
        );

        self::assertSame(expected: 'against', actual: $vote['value']);

    }//end testCastVoteUpdatesExistingVoteOnDuplicate()

    /**
     * Test castVote enforces one-proxy-per-round.
     *
     * @return void
     */
    public function testCastVoteEnforcesOneProxyPerRound(): void
    {
        $openRound = [
            'id'       => 'round-1',
            'closedAt' => null,
        ];

        $existingProxy = [
            'id'      => 'vote-proxy-1',
            'value'   => 'for',
            'isProxy' => true,
        ];

        $this->objectService->method('findObject')->willReturn($openRound);
        $this->objectService->method('findAll')->willReturn(['results' => [$existingProxy]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/proxy vote already exists/');

        $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'delegate-1',
            value: 'against',
            isProxy: true,
            delegatorId: 'delegator-1'
        );

    }//end testCastVoteEnforcesOneProxyPerRound()

    /**
     * Test tallyResults correctly calculates adopted result.
     *
     * @return void
     */
    public function testTallyResultsCalculatesAdoptedResult(): void
    {
        $votes = [
            ['value' => 'for',     'weight' => 1],
            ['value' => 'for',     'weight' => 1],
            ['value' => 'for',     'weight' => 1],
            ['value' => 'against', 'weight' => 1],
            ['value' => 'abstain', 'weight' => 1],
        ];

        $this->objectService->method('findAll')->willReturn(['results' => $votes]);
        $this->objectService->method('findObject')->willReturn(['id' => 'round-1']);
        $this->objectService->method('saveObject')->willReturn(null);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame(expected: 'adopted', actual: $tally['result']);
        self::assertSame(expected: 3, actual: $tally['votesFor']);
        self::assertSame(expected: 1, actual: $tally['votesAgainst']);
        self::assertSame(expected: 1, actual: $tally['votesAbstain']);

    }//end testTallyResultsCalculatesAdoptedResult()

    /**
     * Test tallyResults correctly calculates rejected result.
     *
     * @return void
     */
    public function testTallyResultsCalculatesRejectedResult(): void
    {
        $votes = [
            ['value' => 'for',     'weight' => 1],
            ['value' => 'against', 'weight' => 1],
            ['value' => 'against', 'weight' => 1],
        ];

        $this->objectService->method('findAll')->willReturn(['results' => $votes]);
        $this->objectService->method('findObject')->willReturn(['id' => 'round-1']);
        $this->objectService->method('saveObject')->willReturn(null);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame(expected: 'rejected', actual: $tally['result']);

    }//end testTallyResultsCalculatesRejectedResult()

    /**
     * Test tallyResults returns tied when for and against are equal.
     *
     * @return void
     */
    public function testTallyResultsReturnsTiedWhenEqual(): void
    {
        $votes = [
            ['value' => 'for',     'weight' => 1],
            ['value' => 'against', 'weight' => 1],
        ];

        $this->objectService->method('findAll')->willReturn(['results' => $votes]);
        $this->objectService->method('findObject')->willReturn(['id' => 'round-1']);
        $this->objectService->method('saveObject')->willReturn(null);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame(expected: 'tied', actual: $tally['result']);

    }//end testTallyResultsReturnsTiedWhenEqual()

    /**
     * Test grantProxy throws when delegate role is observer.
     *
     * @return void
     */
    public function testGrantProxyThrowsForObserverDelegate(): void
    {
        $round       = ['id' => 'round-1', 'openedAt' => null];
        $observerDel = ['id' => 'delegate-1', 'role' => 'observer'];

        $this->objectService->method('findObject')
            ->willReturnCallback(static function ($register, $schema, $id) use ($round, $observerDel) {
                if ($schema === 'voting-round') {
                    return $round;
                }

                return $observerDel;
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/observer/');

        $this->service->grantProxy('round-1', 'participant-1', 'delegate-1');

    }//end testGrantProxyThrowsForObserverDelegate()

    /**
     * Test closeVotingRound transitions motion lifecycle after tally.
     *
     * @return void
     */
    public function testCloseVotingRoundTransitionsMotionLifecycle(): void
    {
        $round = [
            'id'        => 'round-1',
            'closedAt'  => null,
            'relations' => ['motion' => [['id' => 'motion-1']]],
        ];

        $votes = [
            ['value' => 'for', 'weight' => 1],
            ['value' => 'for', 'weight' => 1],
        ];

        $this->objectService->method('findObject')->willReturn($round);
        $this->objectService->method('findAll')->willReturn(['results' => $votes]);
        $this->objectService->method('saveObject')->willReturn($round);

        $this->motionService->expects($this->once())
            ->method('transitionLifecycle')
            ->with('motion-1', 'motion', 'adopted', $this->anything());

        $this->oriPublicationService->method('publish')->willReturn(null);

        $this->service->closeVotingRound('round-1', 'actor-1');

    }//end testCloseVotingRoundTransitionsMotionLifecycle()

}//end class
