<?php

/**
 * Unit tests for DecisionApprovalController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\DecisionApprovalController;
use OCA\Decidesk\Service\DecisionApprovalService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionApprovalController — covers the reviewer impersonation fix.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
 */
class DecisionApprovalControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock DecisionApprovalService.
     *
     * @var DecisionApprovalService&MockObject
     */
    private DecisionApprovalService&MockObject $approvalService;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock IUser (authenticated user).
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $user;

    /**
     * Controller under test.
     *
     * @var DecisionApprovalController
     */
    private DecisionApprovalController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->approvalService = $this->createMock(DecisionApprovalService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->user            = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('test-reviewer');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new DecisionApprovalController(
            appName: 'decidesk',
            request: $this->request,
            approvalService: $this->approvalService,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * submitReview returns 200 when the caller is the named person.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testSubmitReviewReturns200ForAuthorisedReviewer(): void
    {
        $this->approvalService->expects($this->once())
            ->method('authorizeReviewerSubmission')
            ->with(personId: 'person-001', callerUid: 'test-reviewer');

        $this->approvalService->expects($this->once())
            ->method('submitReview')
            ->with(
                decisionId: 'decision-001',
                personId: 'person-001',
                value: 'approved',
                note: '',
            );

        $result = $this->controller->submitReview(
            id: 'decision-001',
            personId: 'person-001',
            value: 'approved',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testSubmitReviewReturns200ForAuthorisedReviewer()

    /**
     * submitReview returns 403 when the service denies the caller (impersonation attempt).
     *
     * This is the CWE-639 fix: any authenticated user who supplies another user's
     * personId is denied rather than allowed to forge a sign-off.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testSubmitReviewReturns403ForImpostorCaller(): void
    {
        $this->approvalService->expects($this->once())
            ->method('authorizeReviewerSubmission')
            ->willThrowException(new OCSForbiddenException('Authenticated user does not correspond to the supplied personId'));

        // submitReview must NOT be called after the authorization check fails.
        $this->approvalService->expects($this->never())->method('submitReview');

        $result = $this->controller->submitReview(
            id: 'decision-001',
            personId: 'victim-person-id',
            value: 'approved',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testSubmitReviewReturns403ForImpostorCaller()

    /**
     * submitReview returns 401 for unauthenticated requests.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testSubmitReviewReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new DecisionApprovalController(
            appName: 'decidesk',
            request: $this->request,
            approvalService: $this->approvalService,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $this->approvalService->expects($this->never())->method('authorizeReviewerSubmission');
        $this->approvalService->expects($this->never())->method('submitReview');

        $result = $unauthController->submitReview(
            id: 'decision-001',
            personId: 'person-001',
            value: 'approved',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testSubmitReviewReturns401WhenNotAuthenticated()

    /**
     * transitionLifecycle returns 200 for a valid authenticated request.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testTransitionLifecycleReturns200ForValidRequest(): void
    {
        $this->approvalService->expects($this->once())
            ->method('transitionLifecycle')
            ->with(
                decisionId: 'decision-001',
                toState: 'legal-review',
                actorId: 'test-reviewer',
                reason: '',
            );

        $result = $this->controller->transitionLifecycle(
            id: 'decision-001',
            toState: 'legal-review',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testTransitionLifecycleReturns200ForValidRequest()

    /**
     * transitionLifecycle returns 400 for invalid state transitions.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testTransitionLifecycleReturns400ForInvalidTransition(): void
    {
        $this->approvalService->expects($this->once())
            ->method('transitionLifecycle')
            ->willThrowException(new \InvalidArgumentException("Transition not allowed"));

        $result = $this->controller->transitionLifecycle(
            id: 'decision-001',
            toState: 'invalid-state',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testTransitionLifecycleReturns400ForInvalidTransition()

    /**
     * transitionLifecycle returns 403 when service throws OCSForbiddenException (role check).
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testTransitionLifecycleReturns403WhenForbidden(): void
    {
        $this->approvalService->expects($this->once())
            ->method('transitionLifecycle')
            ->willThrowException(new OCSForbiddenException('Actor lacks required role'));

        $result = $this->controller->transitionLifecycle(
            id: 'decision-001',
            toState: 'legal-review',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testTransitionLifecycleReturns403WhenForbidden()

    /**
     * assignReviewer returns 200 for authenticated request.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testAssignReviewerReturns200ForAuthenticatedRequest(): void
    {
        $this->approvalService->expects($this->once())
            ->method('authorizeAssignment')
            ->with(decisionId: 'decision-001', uid: 'test-reviewer');

        $this->approvalService->expects($this->once())
            ->method('assignReviewer')
            ->with(
                decisionId: 'decision-001',
                personId: 'person-001',
                actorId: 'test-reviewer',
            );

        $result = $this->controller->assignReviewer(
            id: 'decision-001',
            personId: 'person-001',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testAssignReviewerReturns200ForAuthenticatedRequest()

    /**
     * assignReviewer returns 403 when authorizeAssignment throws OCSForbiddenException.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testAssignReviewerReturns403WhenForbidden(): void
    {
        $this->approvalService->expects($this->once())
            ->method('authorizeAssignment')
            ->willThrowException(new OCSForbiddenException('Decision not found or access denied'));

        $this->approvalService->expects($this->never())->method('assignReviewer');

        $result = $this->controller->assignReviewer(
            id: 'missing-id',
            personId: 'person-001',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testAssignReviewerReturns403WhenForbidden()

    /**
     * remindReviewer returns 200 for authenticated request when decision found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testRemindReviewerReturns200ForAuthenticatedRequest(): void
    {
        $this->approvalService->expects($this->once())
            ->method('authorizeReminder')
            ->with(
                decisionId: 'decision-001',
                personId: 'person-001',
                uid: 'test-reviewer',
            );

        $result = $this->controller->remindReviewer(
            id: 'decision-001',
            personId: 'person-001',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testRemindReviewerReturns200ForAuthenticatedRequest()

    /**
     * remindReviewer returns 401 when user is unauthenticated.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testRemindReviewerReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new DecisionApprovalController(
            appName: 'decidesk',
            request: $this->request,
            approvalService: $this->approvalService,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $this->approvalService->expects($this->never())->method('authorizeReminder');

        $result = $unauthController->remindReviewer(
            id: 'decision-001',
            personId: 'person-001',
        );

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testRemindReviewerReturns401WhenNotAuthenticated()

}//end class
