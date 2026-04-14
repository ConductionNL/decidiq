<?php

/**
 * Unit tests for MeetingController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MeetingController;
use OCA\Decidesk\Service\MeetingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MeetingController.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.2
 */
class MeetingControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var MeetingController
     */
    private MeetingController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock MeetingService.
     *
     * @var MeetingService&MockObject
     */
    private MeetingService&MockObject $meetingService;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request        = $this->createMock(originalClassName: IRequest::class);
        $this->meetingService = $this->createMock(originalClassName: MeetingService::class);
        $this->groupManager   = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession    = $this->createMock(originalClassName: IUserSession::class);

        $mockUser = $this->createMock(originalClassName: IUser::class);
        $mockUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($mockUser);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->controller = new MeetingController(
            request: $this->request,
            meetingService: $this->meetingService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );

    }//end setUp()

    /**
     * Test that a valid action returns HTTP 200 with the updated meeting.
     *
     * @return void
     */
    public function testLifecycleReturnsOkOnSuccess(): void
    {
        $uuid    = 'aaaaaaaa-0000-0000-0000-000000000001';
        $meeting = ['lifecycle' => 'opened', 'id' => $uuid];

        $this->request->method('getParam')
            ->with('action', '')
            ->willReturn('open');

        $this->meetingService->expects($this->once())
            ->method('transition')
            ->with(meetingId: $uuid, action: 'open')
            ->willReturn(['success' => true, 'meeting' => $meeting, 'message' => "Meeting transitioned to 'opened'."]);

        $result = $this->controller->lifecycle(id: $uuid);

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());
        self::assertTrue(condition: $result->getData()['success']);

    }//end testLifecycleReturnsOkOnSuccess()

    /**
     * Test that an invalid transition returns HTTP 422.
     *
     * @return void
     */
    public function testLifecycleReturnsUnprocessableOnInvalidTransition(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000002';

        $this->request->method('getParam')
            ->with('action', '')
            ->willReturn('pause');

        $this->meetingService->expects($this->once())
            ->method('transition')
            ->with(meetingId: $uuid, action: 'pause')
            ->willReturn([
                'success' => false,
                'meeting' => null,
                'message' => "Cannot 'pause' a meeting in 'draft' state.",
            ]);

        $result = $this->controller->lifecycle(id: $uuid);

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testLifecycleReturnsUnprocessableOnInvalidTransition()

    /**
     * Test that a missing action parameter returns HTTP 422.
     *
     * @return void
     */
    public function testLifecycleReturnsBadRequestWhenActionMissing(): void
    {
        $this->request->method('getParam')
            ->with('action', '')
            ->willReturn('');

        $this->meetingService->expects($this->never())
            ->method('transition');

        $result = $this->controller->lifecycle(id: 'some-uuid');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testLifecycleReturnsBadRequestWhenActionMissing()

    /**
     * Test that meeting-not-found returns HTTP 422 (service returns failure).
     *
     * @return void
     */
    public function testLifecycleReturnsUnprocessableWhenMeetingNotFound(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000099999999';

        $this->request->method('getParam')
            ->with('action', '')
            ->willReturn('open');

        $this->meetingService->expects($this->once())
            ->method('transition')
            ->willReturn([
                'success' => false,
                'meeting' => null,
                'message' => "Meeting '$uuid' not found.",
            ]);

        $result = $this->controller->lifecycle(id: $uuid);

        self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $result->getStatus());
        self::assertStringContainsString(
            needle: 'not found',
            haystack: $result->getData()['message']
        );

    }//end testLifecycleReturnsUnprocessableWhenMeetingNotFound()

}//end class
