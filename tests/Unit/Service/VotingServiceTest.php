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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\VotingService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingService.
 */
class VotingServiceTest extends TestCase
{

    /**
     * The service under test.
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
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock MotionService.
     *
     * @var MotionService&MockObject
     */
    private MotionService&MockObject $motionService;

    /**
     * Mock ObjectService (generic stdClass with added methods).
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

        $this->objectService = $this->getMockBuilder(className: \stdClass::class)
            ->addMethods(['getObject', 'saveObject', 'getObjects'])
            ->getMock();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->appConfig     = $this->createMock(originalClassName: IAppConfig::class);
        $this->motionService = $this->createMock(originalClassName: MotionService::class);

        $this->service = new VotingService(
            container: $this->container,
            logger: $this->logger,
            appConfig: $this->appConfig,
            motionService: $this->motionService,
        );

    }//end setUp()

    /**
     * Test that checkQuorum returns true when active participants meet the requirement.
     *
     * @return void
     */
    public function testCheckQuorumMet(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'             => 'meeting-1',
                        'quorumRequired' => 3,
                        'governanceBody' => 'body-1',
                    ]
                    );

        $this->objectService->method('getObjects')
            ->willReturn(
                    [
                        ['id' => 'p1', 'leftAt' => null],
                        ['id' => 'p2', 'leftAt' => null],
                        ['id' => 'p3', 'leftAt' => null],
                        ['id' => 'p4', 'leftAt' => null],
                    ]
                    );

        $result = $this->service->checkQuorum(meetingId: 'meeting-1');

        self::assertTrue(condition: $result);

    }//end testCheckQuorumMet()

    /**
     * Test that checkQuorum returns false when active participants are insufficient.
     *
     * @return void
     */
    public function testCheckQuorumNotMet(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'             => 'meeting-2',
                        'quorumRequired' => 5,
                        'governanceBody' => 'body-2',
                    ]
                    );

        $this->objectService->method('getObjects')
            ->willReturn(
                    [
                        ['id' => 'p1', 'leftAt' => null],
                        ['id' => 'p2', 'leftAt' => null],
                        ['id' => 'p3', 'leftAt' => null],
                    ]
                    );

        $result = $this->service->checkQuorum(meetingId: 'meeting-2');

        self::assertFalse(condition: $result);

    }//end testCheckQuorumNotMet()

    /**
     * Test that openVotingRound throws RuntimeException when quorum is not met.
     *
     * @return void
     */
    public function testOpenVotingRoundQuorumBlock(): void
    {
        $this->objectService->method('getObject')
            ->willReturnCallback(
                    function (string $type, string $id): array {
                        if ($id === 'motion-1') {
                            return [
                                'id'      => 'motion-1',
                                'meeting' => 'meeting-q',
                            ];
                        }

                        // Meeting object returned for quorum check.
                        return [
                            'id'             => 'meeting-q',
                            'quorumRequired' => 10,
                            'governanceBody' => 'body-q',
                        ];
                    }
                    );

        // No active participants at all.
        $this->objectService->method('getObjects')
            ->willReturn([]);

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Quorum niet bereikt');

        $this->service->openVotingRound(
            motionId: 'motion-1',
            votingMethod: 'show_of_hands',
            isSecret: false,
        );

    }//end testOpenVotingRoundQuorumBlock()

    /**
     * Test that casting a duplicate vote updates the existing vote instead of creating a new one.
     *
     * @return void
     */
    public function testCastVoteDuplicateUpdate(): void
    {
        // Voting round is open (no closedAt).
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'       => 'round-1',
                        'closedAt' => null,
                    ]
                    );

        // Return an existing vote for this participant.
        $existingVote = [
            'id'          => 'vote-existing',
            'value'       => 'for',
            'votingRound' => 'round-1',
            'participant' => 'participant-1',
        ];

        $this->objectService->method('getObjects')
            ->willReturn([$existingVote]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                    $this->equalTo('vote'),
                    $this->callback(
                            callback:
                    function (array $data): bool {
                        return $data['id'] === 'vote-existing'
                        && $data['value'] === 'against';
                    }
                    )
                    )
            ->willReturnCallback(
                    function (string $type, array $data): array {
                        return $data;
                    }
                    );

        $result = $this->service->castVote(
            votingRoundId: 'round-1',
            participantId: 'participant-1',
            value: 'against',
        );

        self::assertSame(expected: 'vote-existing', actual: $result['id']);
        self::assertSame(expected: 'against', actual: $result['value']);

    }//end testCastVoteDuplicateUpdate()

    /**
     * Test that a proxy vote for the same delegator in the same round throws RuntimeException.
     *
     * @return void
     */
    public function testCastVoteProxyOnePerRound(): void
    {
        // Voting round is open and has a valid proxy grant from delegator-1 to participant-2.
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'       => 'round-2',
                        'closedAt' => null,
                        'notes'    => [
                            ['type' => 'proxy', 'from' => 'delegator-1', 'to' => 'participant-2'],
                        ],
                    ]
                    );

        $callCount = 0;
        $this->objectService->method('getObjects')
            ->willReturnCallback(
                    function (string $type, array $filters) use (&$callCount): array {
                        $callCount++;
                        // First call: existing votes for this participant (none).
                        if ($callCount === 1) {
                            return [];
                        }

                        // Second call: existing proxy votes for the delegator (one found).
                        return [
                            [
                                'id'          => 'proxy-vote-1',
                                'isProxy'     => true,
                                'delegator'   => 'delegator-1',
                                'votingRound' => 'round-2',
                            ],
                        ];
                    }
                    );

        $this->expectException(exception: \RuntimeException::class);

        $this->service->castVote(
            votingRoundId: 'round-2',
            participantId: 'participant-2',
            value: 'for',
            isProxy: true,
            delegatorId: 'delegator-1',
        );

    }//end testCastVoteProxyOnePerRound()

    /**
     * Test that tallyResults returns adopted when votes for exceed votes against.
     *
     * @return void
     */
    public function testTallyResultsAdopted(): void
    {
        $votes = [
            ['value' => 'for'],
            ['value' => 'for'],
            ['value' => 'for'],
            ['value' => 'against'],
            ['value' => 'abstain'],
        ];

        $this->objectService->method('getObjects')
            ->willReturn($votes);

        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'           => 'round-tally-1',
                        'votesFor'     => 0,
                        'votesAgainst' => 0,
                        'votesAbstain' => 0,
                        'result'       => null,
                    ]
                    );

        $this->objectService->method('saveObject')
            ->willReturnCallback(
                    function (string $type, array $data): array {
                        return $data;
                    }
                    );

        $result = $this->service->tallyResults(votingRoundId: 'round-tally-1');

        self::assertSame(expected: 3, actual: $result['votesFor']);
        self::assertSame(expected: 1, actual: $result['votesAgainst']);
        self::assertSame(expected: 1, actual: $result['votesAbstain']);
        self::assertSame(expected: 'adopted', actual: $result['result']);

    }//end testTallyResultsAdopted()

    /**
     * Test that tallyResults returns rejected when votes against exceed votes for.
     *
     * @return void
     */
    public function testTallyResultsRejected(): void
    {
        $votes = [
            ['value' => 'for'],
            ['value' => 'against'],
            ['value' => 'against'],
            ['value' => 'against'],
        ];

        $this->objectService->method('getObjects')
            ->willReturn($votes);

        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'           => 'round-tally-2',
                        'votesFor'     => 0,
                        'votesAgainst' => 0,
                        'votesAbstain' => 0,
                        'result'       => null,
                    ]
                    );

        $this->objectService->method('saveObject')
            ->willReturnCallback(
                    function (string $type, array $data): array {
                        return $data;
                    }
                    );

        $result = $this->service->tallyResults(votingRoundId: 'round-tally-2');

        self::assertSame(expected: 1, actual: $result['votesFor']);
        self::assertSame(expected: 3, actual: $result['votesAgainst']);
        self::assertSame(expected: 'rejected', actual: $result['result']);

    }//end testTallyResultsRejected()

    /**
     * Test that tallyResults returns tied when votes for equal votes against.
     *
     * @return void
     */
    public function testTallyResultsTied(): void
    {
        $votes = [
            ['value' => 'for'],
            ['value' => 'for'],
            ['value' => 'against'],
            ['value' => 'against'],
        ];

        $this->objectService->method('getObjects')
            ->willReturn($votes);

        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'           => 'round-tally-3',
                        'votesFor'     => 0,
                        'votesAgainst' => 0,
                        'votesAbstain' => 0,
                        'result'       => null,
                    ]
                    );

        $this->objectService->method('saveObject')
            ->willReturnCallback(
                    function (string $type, array $data): array {
                        return $data;
                    }
                    );

        $result = $this->service->tallyResults(votingRoundId: 'round-tally-3');

        self::assertSame(expected: 2, actual: $result['votesFor']);
        self::assertSame(expected: 2, actual: $result['votesAgainst']);
        self::assertSame(expected: 'tied', actual: $result['result']);

    }//end testTallyResultsTied()

    /**
     * Test that grantProxy throws InvalidArgumentException when receiver is an observer.
     *
     * @return void
     */
    public function testGrantProxyObserverRejection(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'   => 'participant-obs',
                        'role' => 'observer',
                    ]
                    );

        $this->expectException(exception: \InvalidArgumentException::class);

        $this->service->grantProxy(
            votingRoundId: 'round-proxy',
            fromParticipantId: 'from-p',
            toParticipantId: 'participant-obs',
        );

    }//end testGrantProxyObserverRejection()

    /**
     * Test that revokeProxy throws RuntimeException when the round isOpen flag is true.
     *
     * @return void
     */
    public function testRevokeProxyThrowsWhenRoundIsOpen(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(
                    [
                        'id'       => 'round-open',
                        'isOpen'   => true,
                        'closedAt' => null,
                    ]
                    );

        $this->expectException(exception: \RuntimeException::class);
        $this->expectExceptionMessage(message: 'Kan volmacht niet intrekken: stemronde is al geopend');

        $this->service->revokeProxy(
            votingRoundId: 'round-open',
            fromParticipantId: 'participant-1',
        );

    }//end testRevokeProxyThrowsWhenRoundIsOpen()

    /**
     * Test that revokeProxy succeeds and removes the proxy note when isOpen is false.
     *
     * @return void
     */
    public function testRevokeProxySucceedsWhenRoundIsNotOpen(): void
    {
        $votingRound = [
            'id'       => 'round-not-open',
            'closedAt' => '2026-01-01T00:00:00+00:00',
            'notes'    => [
                ['type' => 'proxy', 'from' => 'participant-1', 'to' => 'participant-2'],
            ],
        ];

        $this->objectService->method('getObject')
            ->willReturn($votingRound);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                    $this->equalTo(value: 'votingRound'),
                    $this->callback(
                            callback: function (array $data): bool {
                                return count($data['notes']) === 0;
                            }
                    )
                    )
            ->willReturnCallback(
                    function (string $type, array $data): array {
                        return $data;
                    }
                    );

        $this->service->revokeProxy(
            votingRoundId: 'round-not-open',
            fromParticipantId: 'participant-1',
        );

    }//end testRevokeProxySucceedsWhenRoundIsNotOpen()
}//end class
