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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\VotingService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
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
     * Mock ObjectService.
     *
     * @var object&MockObject
     */
    private object&MockObject $objectService;

    /**
     * Mock NotificationService.
     *
     * @var object&MockObject
     */
    private object&MockObject $notificationService;

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
     * Mock Logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test doubles.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'saveObject', 'findAll', 'deleteObject'])
            ->getMock();

        $this->notificationService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['sendNotification'])
            ->getMock();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(function ($id) {
                if (str_contains($id, 'ObjectService')) {
                    return $this->objectService;
                }

                if (str_contains($id, 'NotificationService')) {
                    return $this->notificationService;
                }

                if (str_contains($id, 'FileService')) {
                    $fs = $this->getMockBuilder(\stdClass::class)->addMethods(['createFolder'])->getMock();
                    return $fs;
                }

                if (str_contains($id, 'CalendarEventService')) {
                    $cs = $this->getMockBuilder(\stdClass::class)->addMethods(['createEvent'])->getMock();
                    return $cs;
                }

                throw new \RuntimeException("Unmocked service: {$id}");
            });

        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->motionService = $this->createMock(MotionService::class);

        $appConfig     = $this->createMock(IAppConfig::class);
        $clientService = $this->createMock(IClientService::class);

        $this->oriService = $this->getMockBuilder(OriPublicationService::class)
            ->setConstructorArgs([
                $appConfig,
                $clientService,
                $this->container,
                $this->logger,
            ])
            ->onlyMethods(['publish'])
            ->getMock();
        $this->oriService->method('publish')->willReturn(null);

        $this->service = new VotingService(
            container: $this->container,
            logger: $this->logger,
            motionService: $this->motionService,
            oriPublicationService: $this->oriService,
        );

    }//end setUp()

    /**
     * Test checkQuorum returns true when active participant count meets required.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testCheckQuorumMet(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'meeting-1', 'quorumRequired' => 3, 'governanceBodyId' => 'gb-1']);

        $this->objectService->method('findAll')
            ->willReturn([
                ['id' => 'p1', 'leftAt' => null],
                ['id' => 'p2', 'leftAt' => null],
                ['id' => 'p3', 'leftAt' => null],
            ]);

        self::assertTrue($this->service->checkQuorum('meeting-1'));

    }//end testCheckQuorumMet()

    /**
     * Test checkQuorum returns false when active participant count is insufficient.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testCheckQuorumNotMet(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'meeting-1', 'quorumRequired' => 5, 'governanceBodyId' => 'gb-1']);

        $this->objectService->method('findAll')
            ->willReturn([
                ['id' => 'p1', 'leftAt' => null],
                ['id' => 'p2', 'leftAt' => '2025-04-14T20:00:00+02:00'],
            ]);

        self::assertFalse($this->service->checkQuorum('meeting-1'));

    }//end testCheckQuorumNotMet()

    /**
     * Test openVotingRound throws RuntimeException when quorum is not met.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testOpenVotingRoundBlockedByQuorum(): void
    {
        // Meeting has high quorum requirement, only 1 active participant.
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'meeting-1', 'quorumRequired' => 10, 'governanceBodyId' => 'gb-1']);

        $this->objectService->method('findAll')
            ->willReturn([['id' => 'p1', 'leftAt' => null]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Quorum/');

        $this->service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            actorId: 'chair1',
        );

    }//end testOpenVotingRoundBlockedByQuorum()

    /**
     * Test castVote updates existing vote instead of creating a duplicate.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testCastVoteUpdateOnDuplicate(): void
    {
        $openRound = ['id' => 'round-1', 'closedAt' => null, 'status' => 'open'];

        $this->objectService->method('getObject')
            ->willReturn($openRound);

        // Return an existing vote for this participant.
        $this->objectService->method('findAll')
            ->willReturn([['id' => 'vote-existing', 'participantId' => 'user1', 'value' => 'for']]);

        $savedVote = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(static function ($register, $schema, $object) use (&$savedVote) {
                $savedVote = $object;
                return $object;
            });

        $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'user1',
            value: 'against',
            isProxy: false,
            delegatorId: null,
        );

        // The saved vote should carry the existing ID (overwrite).
        self::assertSame('vote-existing', ($savedVote['id'] ?? null));
        self::assertSame('against', ($savedVote['value'] ?? null));

    }//end testCastVoteUpdateOnDuplicate()

    /**
     * Test proxy one-per-round enforcement throws when proxy already exists.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testCastVoteProxyOnePerRoundEnforcement(): void
    {
        $openRound = ['id' => 'round-1', 'closedAt' => null, 'status' => 'open'];

        $this->objectService->method('getObject')
            ->willReturn($openRound);

        // Existing proxy vote for the same delegator.
        $this->objectService->method('findAll')
            ->willReturn([['id' => 'proxy-existing', 'isProxy' => true]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/proxy.*already exists/i');

        $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'user2',
            value: 'for',
            isProxy: true,
            delegatorId: 'user1',
        );

    }//end testCastVoteProxyOnePerRoundEnforcement()

    /**
     * Test tallyResults returns adopted when more for votes than against.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testTallyResultsAdopted(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'round-1']);

        $this->objectService->method('findAll')
            ->willReturn([
                ['value' => 'for', 'weight' => 1],
                ['value' => 'for', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ]);

        $this->objectService->method('saveObject')->willReturnArgument(2);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame('adopted', $tally['result']);
        self::assertSame(2, $tally['votesFor']);
        self::assertSame(1, $tally['votesAgainst']);

    }//end testTallyResultsAdopted()

    /**
     * Test tallyResults returns rejected when more against votes than for.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testTallyResultsRejected(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'round-1']);

        $this->objectService->method('findAll')
            ->willReturn([
                ['value' => 'for', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ]);

        $this->objectService->method('saveObject')->willReturnArgument(2);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame('rejected', $tally['result']);

    }//end testTallyResultsRejected()

    /**
     * Test tallyResults returns tied when for equals against.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testTallyResultsTied(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(['id' => 'round-1']);

        $this->objectService->method('findAll')
            ->willReturn([
                ['value' => 'for', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ]);

        $this->objectService->method('saveObject')->willReturnArgument(2);

        $tally = $this->service->tallyResults('round-1');

        self::assertSame('tied', $tally['result']);

    }//end testTallyResultsTied()

    /**
     * Test closeVotingRound transitions motion lifecycle to adopted.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testCloseVotingRoundLifecycleTransition(): void
    {
        $round = [
            'id'       => 'round-1',
            'motionId' => 'motion-1',
            'closedAt' => null,
            'status'   => 'open',
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(static function ($reg, $schema, $id) use ($round) {
                if ($schema === 'voting-round') {
                    return $round;
                }

                return ['id' => $id, '@self' => ['slug' => $id]];
            });

        // Return votes that result in "adopted".
        $this->objectService->method('findAll')
            ->willReturn([
                ['value' => 'for', 'weight' => 1],
                ['value' => 'for', 'weight' => 1],
                ['value' => 'against', 'weight' => 1],
            ]);

        $this->objectService->method('saveObject')->willReturnArgument(2);

        $this->motionService->expects($this->once())
            ->method('transitionLifecycle')
            ->with('motion-1', 'motion', 'adopted', 'chair1');

        $this->service->closeVotingRound('round-1', 'chair1');

    }//end testCloseVotingRoundLifecycleTransition()

    /**
     * Test grantProxy rejects observer role delegates.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11
     */
    public function testGrantProxyObserverRejection(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([['id' => 'p-observer', 'role' => 'observer']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/observer/i');

        $this->service->grantProxy(
            votingRoundId: 'round-1',
            fromParticipantId: 'user1',
            toParticipantId: 'observer-user',
        );

    }//end testGrantProxyObserverRejection()

}//end class
