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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
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
     * In-memory object service for testing.
     *
     * @var object
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

        $this->container  = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger     = $this->createMock(originalClassName: LoggerInterface::class);
        $this->oriService = $this->createMock(originalClassName: OriPublicationService::class);

        $objectService = new class {
            /** @var array<string,array<string,mixed>> */
            public array $store = [];
            /** @var array<string,mixed> */
            public array $findResult = ['results' => []];

            /**
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $uuid     The UUID
             * @return array<string,mixed>|null
             */
            public function getObject(string $register, string $schema, string $uuid): ?array
            {
                return ($this->store[$schema . ':' . $uuid] ?? null);
            }

            /**
             * @param string              $register The register
             * @param string              $schema   The schema
             * @param array<string,mixed> $object   The object
             * @return array<string,mixed>
             */
            public function saveObject(string $register, string $schema, array $object): array
            {
                $key               = $schema . ':' . ($object['uuid'] ?? $object['id'] ?? uniqid());
                $this->store[$key] = $object;
                return $object;
            }

            /**
             * @param string              $register The register
             * @param string              $schema   The schema
             * @param array<string,mixed> $filters  The filters
             * @return array<string,mixed>
             */
            public function findObjects(string $register, string $schema, array $filters=[]): array
            {
                return $this->findResult;
            }
        };

        $this->objectService = $objectService;

        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->service = new VotingService(
            container: $this->container,
            logger: $this->logger,
            oriPublicationService: $this->oriService
        );

    }//end setUp()


    /**
     * Test checkQuorum returns true when quorumRequired is 0 (no quorum set).
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCheckQuorumReturnsTrueWhenNoQuorumRequired(): void
    {
        $this->objectService->store['meeting:meeting-1'] = [
            'id'             => 'meeting-1',
            'uuid'           => 'meeting-1',
            'quorumRequired' => 0,
        ];

        $result = $this->service->checkQuorum(meetingId: 'meeting-1');

        self::assertTrue(condition: $result);

    }//end testCheckQuorumReturnsTrueWhenNoQuorumRequired()


    /**
     * Test checkQuorum returns false when meeting not found.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCheckQuorumReturnsFalseWhenMeetingNotFound(): void
    {
        $result = $this->service->checkQuorum(meetingId: 'nonexistent');

        self::assertFalse(condition: $result);

    }//end testCheckQuorumReturnsFalseWhenMeetingNotFound()


    /**
     * Test openVotingRound throws RuntimeException when quorum is not met.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testOpenVotingRoundThrowsWhenQuorumNotMet(): void
    {
        // Meeting not found → checkQuorum returns false.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quorum niet bereikt');

        $this->service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'missing-meeting',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null
        );

    }//end testOpenVotingRoundThrowsWhenQuorumNotMet()


    /**
     * Test openVotingRound creates VotingRound when quorum is met.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testOpenVotingRoundCreatesRoundWhenQuorumMet(): void
    {
        $this->objectService->store['meeting:meeting-ok'] = [
            'id'             => 'meeting-ok',
            'uuid'           => 'meeting-ok',
            'quorumRequired' => 0,
        ];
        $this->objectService->store['motion:motion-ok'] = [
            'id'        => 'motion-ok',
            'uuid'      => 'motion-ok',
            'lifecycle' => 'debating',
        ];

        $round = $this->service->openVotingRound(
            motionId: 'motion-ok',
            meetingId: 'meeting-ok',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null
        );

        self::assertSame(expected: 'for-against-abstain', actual: $round['votingMethod']);
        self::assertFalse(condition: $round['isSecret']);
        self::assertNotNull(actual: $round['openedAt']);

    }//end testOpenVotingRoundCreatesRoundWhenQuorumMet()


    /**
     * Test castVote saves a vote and returns the created object.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCastVoteCreatesvote(): void
    {
        $this->objectService->store['voting-round:round-1'] = [
            'id'       => 'round-1',
            'uuid'     => 'round-1',
            'openedAt' => '2025-04-14T20:00:00+02:00',
            'closedAt' => null,
        ];
        // No existing vote.
        $this->objectService->findResult = ['results' => []];

        $vote = $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'participant-1',
            value: 'for',
            isProxy: false,
            delegatorId: null
        );

        self::assertSame(expected: 'for', actual: $vote['value']);
        self::assertFalse(condition: $vote['isProxy']);

    }//end testCastVoteCreatesvote()


    /**
     * Test castVote overwrites existing vote (no duplicate).
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCastVoteOverwritesExistingVote(): void
    {
        $this->objectService->store['voting-round:round-2'] = [
            'id'       => 'round-2',
            'uuid'     => 'round-2',
            'openedAt' => '2025-04-14T20:00:00+02:00',
            'closedAt' => null,
        ];
        // Existing vote by same participant.
        $this->objectService->findResult = [
            'results' => [
                ['id' => 'vote-existing', 'uuid' => 'vote-existing', 'value' => 'against'],
            ],
        ];

        $vote = $this->service->castVote(
            votingRoundId: 'round-2',
            participantId: 'participant-2',
            value: 'for',
            isProxy: false,
            delegatorId: null
        );

        // Should overwrite (include existing vote UUID).
        self::assertSame(expected: 'for', actual: $vote['value']);
        self::assertSame(expected: 'vote-existing', actual: ($vote['uuid'] ?? $vote['id']));

    }//end testCastVoteOverwritesExistingVote()


    /**
     * Test proxy vote throws when second proxy for same delegator.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCastVoteProxyEnforcesOnePerRound(): void
    {
        $this->objectService->store['voting-round:round-3'] = [
            'id'       => 'round-3',
            'uuid'     => 'round-3',
            'openedAt' => '2025-04-14T20:00:00+02:00',
            'closedAt' => null,
        ];
        // Existing proxy vote for same delegator.
        $this->objectService->findResult = [
            'results' => [
                [
                    'id'       => 'vote-proxy',
                    'uuid'     => 'vote-proxy',
                    'isProxy'  => true,
                    'relations' => [
                        ['schema' => 'participant', 'id' => 'delegator-1', 'type' => 'delegator'],
                    ],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);

        $this->service->castVote(
            votingRoundId: 'round-3',
            participantId: 'proxy-voter',
            value: 'for',
            isProxy: true,
            delegatorId: 'delegator-1'
        );

    }//end testCastVoteProxyEnforcesOnePerRound()


    /**
     * Test tallyResults returns adopted when more votes for than against.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testTallyResultsAdopted(): void
    {
        $this->objectService->store['voting-round:round-tally'] = [
            'id'       => 'round-tally',
            'uuid'     => 'round-tally',
            'openedAt' => '2025-04-14T20:00:00+02:00',
        ];
        $this->objectService->findResult = [
            'results' => [
                ['value' => 'for'],
                ['value' => 'for'],
                ['value' => 'against'],
            ],
        ];

        $tally = $this->service->tallyResults(votingRoundId: 'round-tally');

        self::assertSame(expected: 'adopted', actual: $tally['result']);
        self::assertSame(expected: 2, actual: $tally['votesFor']);
        self::assertSame(expected: 1, actual: $tally['votesAgainst']);

    }//end testTallyResultsAdopted()


    /**
     * Test tallyResults returns rejected when more against than for.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testTallyResultsRejected(): void
    {
        $this->objectService->store['voting-round:round-tally2'] = [
            'id'   => 'round-tally2',
            'uuid' => 'round-tally2',
        ];
        $this->objectService->findResult = [
            'results' => [
                ['value' => 'against'],
                ['value' => 'against'],
                ['value' => 'for'],
            ],
        ];

        $tally = $this->service->tallyResults(votingRoundId: 'round-tally2');

        self::assertSame(expected: 'rejected', actual: $tally['result']);

    }//end testTallyResultsRejected()


    /**
     * Test tallyResults returns tied when equal for and against.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testTallyResultsTied(): void
    {
        $this->objectService->store['voting-round:round-tally3'] = [
            'id'   => 'round-tally3',
            'uuid' => 'round-tally3',
        ];
        $this->objectService->findResult = [
            'results' => [
                ['value' => 'for'],
                ['value' => 'against'],
            ],
        ];

        $tally = $this->service->tallyResults(votingRoundId: 'round-tally3');

        self::assertSame(expected: 'tied', actual: $tally['result']);

    }//end testTallyResultsTied()


    /**
     * Test grantProxy throws when receiver has observer role.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testGrantProxyThrowsForObserverRole(): void
    {
        $this->objectService->store['participant:observer-1'] = [
            'id'   => 'observer-1',
            'uuid' => 'observer-1',
            'role' => 'observer',
        ];
        $this->objectService->store['voting-round:round-prx'] = [
            'id'       => 'round-prx',
            'uuid'     => 'round-prx',
            'openedAt' => null,
            'notes'    => [],
        ];

        $this->expectException(\InvalidArgumentException::class);

        $this->service->grantProxy(
            votingRoundId: 'round-prx',
            fromParticipantId: 'member-1',
            toParticipantId: 'observer-1'
        );

    }//end testGrantProxyThrowsForObserverRole()


    /**
     * Test closeVotingRound transitions motion lifecycle.
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-11.2
     */
    public function testCloseVotingRoundTransitionsMotionLifecycle(): void
    {
        $this->objectService->store['voting-round:round-close'] = [
            'id'       => 'round-close',
            'uuid'     => 'round-close',
            'openedAt' => '2025-04-14T20:00:00+02:00',
            'closedAt' => null,
            'relations' => [
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => 'motion-close'],
            ],
        ];
        $this->objectService->store['motion:motion-close'] = [
            'id'        => 'motion-close',
            'uuid'      => 'motion-close',
            'lifecycle' => 'voting',
        ];
        // 2 for, 1 against → adopted.
        $this->objectService->findResult = [
            'results' => [
                ['value' => 'for'],
                ['value' => 'for'],
                ['value' => 'against'],
            ],
        ];

        $this->oriService->method('publish')->willReturn(null);

        $this->service->closeVotingRound(votingRoundId: 'round-close');

        $savedMotion = ($this->objectService->store['motion:motion-close'] ?? null);
        self::assertNotNull(actual: $savedMotion);
        self::assertSame(expected: 'adopted', actual: $savedMotion['lifecycle']);

    }//end testCloseVotingRoundTransitionsMotionLifecycle()


}//end class
