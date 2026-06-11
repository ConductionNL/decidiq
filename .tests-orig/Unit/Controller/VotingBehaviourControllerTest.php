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
use OCA\OpenRegister\Service\ObjectService;
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
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

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
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->user             = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('user-1');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new VotingBehaviourController(
            request: $this->request,
            behaviourService: $this->behaviourService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            objectService: $this->objectService,
        );

    }//end setUp()

    /**
     * Build a mock participant entity that returns the given nextcloudUserId.
     *
     * @param string $nextcloudUserId
     *
     * @return object
     */
    private function makeParticipantEntity(string $nextcloudUserId): object
    {
        // Mock the real ObjectEntity type so the value is assignable to
        // ObjectService::find()'s declared `?ObjectEntity` return when the live
        // OpenRegister app is bootstrapped (a bare \stdClass mock is rejected by
        // PHPUnit's IncompatibleReturnValue check). The stub ObjectEntity also
        // declares jsonSerialize(), so this works standalone too.
        $entity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn(['nextcloudUserId' => $nextcloudUserId]);
        return $entity;

    }//end makeParticipantEntity()

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
            objectService: $this->objectService,
        );

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $controller->getStats(participantId: 'p1', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testGetStatsUnauthenticatedReturns401()

    /**
     * getStats() returns 403 when non-admin requests another user's stats.
     *
     * The participant UUID resolves to a different Nextcloud user, so $isOwnStats=false,
     * and the caller is not an admin.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsForbiddenForNonAdminAccessingOtherStats(): void
    {
        $this->groupManager->method('isAdmin')->with('user-1')->willReturn(false);

        // Participant UUID resolves to a different NC user.
        $this->objectService->method('find')->willReturn(
            $this->makeParticipantEntity('other-nc-user')
        );

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $this->controller->getStats(participantId: 'participant-uuid-other', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testGetStatsForbiddenForNonAdminAccessingOtherStats()

    /**
     * getStats() returns 400 when governanceBodyId is missing.
     *
     * The participant UUID resolves to the calling user (own stats path).
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsMissingGovernanceBodyIdReturns400(): void
    {
        // Participant UUID resolves to the calling user → own stats, no 403.
        $this->objectService->method('find')->willReturn(
            $this->makeParticipantEntity('user-1')
        );

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $this->controller->getStats(participantId: 'participant-uuid-own', governanceBodyId: '');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testGetStatsMissingGovernanceBodyIdReturns400()

    /**
     * getStats() returns 200 when user accesses own stats via UUID lookup.
     *
     * The participant UUID must resolve via ObjectService to a participant whose
     * nextcloudUserId matches the calling user's NC UID; only then is $isOwnStats=true.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsReturns200ForOwnStats(): void
    {
        $participantUuid = 'participant-uuid-abc123';

        // Participant UUID resolves to the calling user.
        $this->objectService->expects($this->once())
            ->method('find')
            ->with($participantUuid, [], false, 'decidesk', 'participant')
            ->willReturn($this->makeParticipantEntity('user-1'));

        $expectedStats = [
            'participantId'     => $participantUuid,
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
            ->with(participantId: $participantUuid, governanceBodyId: 'gb1')
            ->willReturn($expectedStats);

        $result = $this->controller->getStats(participantId: $participantUuid, governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame($expectedStats, $result->getData());

    }//end testGetStatsReturns200ForOwnStats()

    /**
     * getStats() allows admin to access any participant's stats.
     *
     * Even when the participant UUID resolves to a different NC user, admin bypasses the check.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsAdminCanAccessOtherParticipantStats(): void
    {
        $this->groupManager->method('isAdmin')->with('user-1')->willReturn(true);

        // Participant UUID resolves to a different NC user — but admin bypasses the check.
        $this->objectService->method('find')->willReturn(
            $this->makeParticipantEntity('other-nc-user')
        );

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

    /**
     * getStats() returns 403 when participant UUID cannot be resolved and caller is not admin.
     *
     * If the participant object doesn't exist, $isOwnStats stays false → non-admin gets 403.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testGetStatsForbiddenWhenParticipantNotFound(): void
    {
        $this->groupManager->method('isAdmin')->with('user-1')->willReturn(false);

        // Participant UUID resolves to null (not found).
        $this->objectService->method('find')->willReturn(null);

        $this->behaviourService->expects($this->never())->method('getStats');

        $result = $this->controller->getStats(participantId: 'nonexistent-uuid', governanceBodyId: 'gb1');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testGetStatsForbiddenWhenParticipantNotFound()

}//end class
