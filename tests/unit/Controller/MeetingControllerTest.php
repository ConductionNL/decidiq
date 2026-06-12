<?php

/**
 * Unit tests for MeetingController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MeetingController;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ProofPackageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MeetingController lifecycle endpoint.
 *
 * Meeting CRUD is delegated to OpenRegister's object API; only the
 * guarded lifecycle transition endpoint lives in this controller.
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
     * Mock ParticipantResolver.
     *
     * @var ParticipantResolver&MockObject
     */
    private ParticipantResolver&MockObject $participantResolver;

    /**
     * Mock ProofPackageService.
     *
     * @var ProofPackageService&MockObject
     */
    private ProofPackageService&MockObject $proofPackageService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request             = $this->createMock(originalClassName: IRequest::class);
        $this->meetingService      = $this->createMock(originalClassName: MeetingService::class);
        $this->userSession         = $this->createMock(originalClassName: IUserSession::class);
        $this->groupManager        = $this->createMock(originalClassName: IGroupManager::class);
        $this->participantResolver = $this->createMock(originalClassName: ParticipantResolver::class);
        $this->proofPackageService = $this->createMock(originalClassName: ProofPackageService::class);

        // Default: authenticated user present.
        $mockUser = $this->createMock(originalClassName: IUser::class);
        $mockUser->method('getUID')->willReturn('testuser');
        $mockUser->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($mockUser);

        $this->controller = new MeetingController(
            request: $this->request,
            meetingService: $this->meetingService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            participantResolver: $this->participantResolver,
            proofPackageService: $this->proofPackageService,
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

    /**
     * Test that an unauthenticated request returns HTTP 401.
     *
     * @return void
     */
    public function testLifecycleReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        // Override the default mock to return null (unauthenticated).
        $unauthSession = $this->createMock(originalClassName: IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new MeetingController(
            request: $this->request,
            meetingService: $this->meetingService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            participantResolver: $this->participantResolver,
            proofPackageService: $this->proofPackageService,
        );

        $this->request->method('getParam')
            ->with('action', '')
            ->willReturn('open');

        $this->meetingService->expects($this->never())
            ->method('transition');

        $result = $controller->lifecycle(id: 'some-uuid');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $result->getStatus());

    }//end testLifecycleReturnsUnauthorizedWhenNotAuthenticated()

    /**
     * Proof package: chair/secretary role yields 200 with the package summary.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageReturnsOkForChair(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000010';

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->participantResolver->method('hasRole')
            ->with(meetingId: $uuid, nextcloudUid: 'testuser', roles: ['chair', 'secretary'])
            ->willReturn(true);

        $this->proofPackageService->expects($this->once())
            ->method('assemble')
            ->with(meetingId: $uuid, generatedBy: 'Test User')
            ->willReturn(
                [
                    'files'       => ['Decidesk/Raad/2026-06-12 Raadsvergadering/Minutes/Proof package 2026-06-12 1000.json'],
                    'sha256'      => str_repeat('a', 64),
                    'generatedAt' => '2026-06-12T10:00:00+00:00',
                ]
            );

        $result = $this->controller->proofPackage(id: $uuid);

        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());
        self::assertSame(expected: str_repeat('a', 64), actual: $result->getData()['sha256']);

    }//end testProofPackageReturnsOkForChair()

    /**
     * Proof package: NC admin passes without a meeting role (fallback).
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageAllowsNcAdminFallback(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000011';

        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->participantResolver->expects($this->never())->method('hasRole');

        $this->proofPackageService->method('assemble')
            ->willReturn(['files' => [], 'sha256' => str_repeat('b', 64), 'generatedAt' => 'now']);

        $result = $this->controller->proofPackage(id: $uuid);

        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());

    }//end testProofPackageAllowsNcAdminFallback()

    /**
     * Proof package: a participant without chair/secretary role gets 403
     * (fail closed) and the service is never invoked.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageReturnsForbiddenWithoutRole(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->participantResolver->method('hasRole')->willReturn(false);

        $this->proofPackageService->expects($this->never())->method('assemble');

        $result = $this->controller->proofPackage(id: 'some-uuid');

        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());

    }//end testProofPackageReturnsForbiddenWithoutRole()

    /**
     * Proof package: unauthenticated request gets 401, no service call.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(originalClassName: IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new MeetingController(
            request: $this->request,
            meetingService: $this->meetingService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            participantResolver: $this->participantResolver,
            proofPackageService: $this->proofPackageService,
        );

        $this->proofPackageService->expects($this->never())->method('assemble');

        $result = $controller->proofPackage(id: 'some-uuid');

        self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $result->getStatus());

    }//end testProofPackageReturnsUnauthorizedWhenNotAuthenticated()

    /**
     * Proof package: unknown meeting maps MissingObjectException to 404.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageReturnsNotFoundForUnknownMeeting(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->proofPackageService->method('assemble')
            ->willThrowException(new MissingObjectException(message: 'Meeting "x" not found.'));

        $result = $this->controller->proofPackage(id: 'x');

        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());

    }//end testProofPackageReturnsNotFoundForUnknownMeeting()

    /**
     * Proof package: backend unavailability maps RuntimeException to 503.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testProofPackageReturnsServiceUnavailableOnRuntimeError(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->proofPackageService->method('assemble')
            ->willThrowException(new \RuntimeException('Files backend unavailable.', 503));

        $result = $this->controller->proofPackage(id: 'some-uuid');

        self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $result->getStatus());

    }//end testProofPackageReturnsServiceUnavailableOnRuntimeError()

}//end class
