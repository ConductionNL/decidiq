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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MeetingController;
use OCA\Decidesk\Service\MeetingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MeetingController.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
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
     * Mock IL10N.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

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
        $this->l10n           = $this->createMock(originalClassName: IL10N::class);
        $this->l10n->method('t')->willReturnCallback(static fn(string $text) => $text);

        $this->controller = new MeetingController(
            request: $this->request,
            meetingService: $this->meetingService,
            l10n: $this->l10n,
        );

    }//end setUp()

    /**
     * Test that transitionLifecycle returns success JSON with correct data.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleReturnsSuccessJson(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('transition', '')
            ->willReturn('open');

        $this->meetingService->expects($this->once())
            ->method('transitionLifecycle')
            ->with(meetingId: 'meeting-1', transition: 'open')
            ->willReturn([
                'success'       => true,
                'previousState' => 'scheduled',
                'currentState'  => 'opened',
                'transition'    => 'open',
            ]);

        $result = $this->controller->transitionLifecycle(id: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertTrue(condition: $result->getData()['success']);
        self::assertSame(expected: 'opened', actual: $result->getData()['currentState']);

    }//end testTransitionLifecycleReturnsSuccessJson()

    /**
     * Test that transitionLifecycle returns 403 for unauthorized users.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleReturnsForbiddenForUnauthorized(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('transition', '')
            ->willReturn('open');

        $this->meetingService->expects($this->once())
            ->method('transitionLifecycle')
            ->willThrowException(new \RuntimeException('Forbidden', 403));

        $result = $this->controller->transitionLifecycle(id: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $result->getStatus());
        self::assertFalse(condition: $result->getData()['success']);

    }//end testTransitionLifecycleReturnsForbiddenForUnauthorized()

    /**
     * Test that transitionLifecycle returns 400 when transition parameter is missing.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testTransitionLifecycleReturnsBadRequestWhenMissingTransition(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('transition', '')
            ->willReturn('');

        $this->meetingService->expects($this->never())
            ->method('transitionLifecycle');

        $result = $this->controller->transitionLifecycle(id: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $result->getStatus());
        self::assertFalse(condition: $result->getData()['success']);

    }//end testTransitionLifecycleReturnsBadRequestWhenMissingTransition()

    /**
     * Test that userRole returns role JSON.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testUserRoleReturnsRoleJson(): void
    {
        $this->meetingService->expects($this->once())
            ->method('getUserRole')
            ->with(meetingId: 'meeting-1')
            ->willReturn(['role' => 'chair']);

        $result = $this->controller->userRole(id: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: 'chair', actual: $result->getData()['role']);

    }//end testUserRoleReturnsRoleJson()

    /**
     * Test that userRole returns 'none' on exception.
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-8
     */
    public function testUserRoleReturnsNoneOnException(): void
    {
        $this->meetingService->expects($this->once())
            ->method('getUserRole')
            ->willThrowException(new \RuntimeException('Service error'));

        $result = $this->controller->userRole(id: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: 'none', actual: $result->getData()['role']);

    }//end testUserRoleReturnsNoneOnException()
}//end class
