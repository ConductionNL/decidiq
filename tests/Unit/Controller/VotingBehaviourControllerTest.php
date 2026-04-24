<?php

/**
 * Unit tests for VotingBehaviourController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\VotingBehaviourController;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VotingBehaviourController.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourControllerTest extends TestCase
{

    /**
     * Controller under test.
     *
     * @var VotingBehaviourController
     */
    private VotingBehaviourController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock VotingBehaviourService.
     *
     * @var VotingBehaviourService&MockObject
     */
    private VotingBehaviourService&MockObject $behaviourService;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock IUser.
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $user;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->behaviourService = $this->createMock(VotingBehaviourService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->user             = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('user-1');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new VotingBehaviourController(
            request: $this->request,
            behaviourService: $this->behaviourService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
        );

    }//end setUp()

    /**
     * getStats() returns 401 when user is not authenticated.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsUnauthenticatedReturns401(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new VotingBehaviourController(
            request: $this->request,
            behaviourService: $this->behaviourService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
        );

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $controller->getStats(participantId: 'p1', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testGetStatsUnauthenticatedReturns401()

    /**
     * getStats() returns 403 when non-admin requests another user's stats.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsForbiddenForNonAdminAccessingOtherStats(): void
    {
        $this->groupManager->method('isAdmin')->with('user-1')->willReturn(false);

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $this->controller->getStats(participantId: 'other-user', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testGetStatsForbiddenForNonAdminAccessingOtherStats()

    /**
     * getStats() returns 400 when governanceBodyId is missing.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsMissingGovernanceBodyIdReturns400(): void
    {
        // User accessing own stats — no forbidden check triggered.
        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $this->controller->getStats(participantId: 'user-1', governanceBodyId: '');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testGetStatsMissingGovernanceBodyIdReturns400()

    /**
     * getStats() returns 200 with stats when user accesses own stats.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsReturns200ForOwnStats(): void
    {
        $expectedStats = [
            'participantId'     => 'user-1',
            'governanceBodyId'  => 'gb1',
            'totalRounds'       => 5,
            'participated'      => 4,
            'participationRate' => 80.0,
            'votesFor'          => 3,
            'votesAgainst'      => 1,
            'votesAbstain'      => 0,
            'proxiesGiven'      => 0,
            'proxiesReceived'   => 0,
        ];

        $this->behaviourService
            ->expects($this->once())
            ->method('getStats')
            ->with(participantId: 'user-1', governanceBodyId: 'gb1')
            ->willReturn($expectedStats);

        $result = $this->controller->getStats(participantId: 'user-1', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame($expectedStats, $result->getData());

    }//end testGetStatsReturns200ForOwnStats()

    /**
     * getStats() allows admin to access any participant's stats.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsAdminCanAccessOtherParticipantStats(): void
    {
        $this->groupManager->method('isAdmin')->with('user-1')->willReturn(true);

        $expectedStats = ['participantId' => 'other-participant', 'totalRounds' => 2];

        $this->behaviourService
            ->expects($this->once())
            ->method('getStats')
            ->with(participantId: 'other-participant', governanceBodyId: 'gb1')
            ->willReturn($expectedStats);

        $result = $this->controller->getStats(participantId: 'other-participant', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testGetStatsAdminCanAccessOtherParticipantStats()

}//end class
