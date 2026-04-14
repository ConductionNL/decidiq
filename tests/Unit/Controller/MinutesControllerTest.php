<?php

/**
 * Unit tests for MinutesController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MinutesController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class MinutesControllerTest extends TestCase
{

    /**
     * The controller under test (authenticated user).
     *
     * @var MinutesController
     */
    private MinutesController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock MinutesGenerationService.
     *
     * @var MinutesGenerationService&MockObject
     */
    private MinutesGenerationService&MockObject $minutesGenerationService;

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
     * Mock IUser (authenticated user).
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

        $this->request                  = $this->createMock(IRequest::class);
        $this->minutesGenerationService = $this->createMock(MinutesGenerationService::class);
        $this->userSession              = $this->createMock(IUserSession::class);
        $this->groupManager             = $this->createMock(IGroupManager::class);
        $this->user                     = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('testuser');
        $this->user->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
        );

    }//end setUp()

    /**
     * generateDraft returns a 200 JSON response with a preview field.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftReturnsPreviewJson(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $previewText = '# Concept notulen' . PHP_EOL . 'Gegenereerde inhoud...';

        $this->minutesGenerationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-uuid-001')
            ->willReturn($previewText);

        $result = $this->controller->generateDraft('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertArrayHasKey('preview', $result->getData());
        self::assertSame($previewText, $result->getData()['preview']);

    }//end testGenerateDraftReturnsPreviewJson()

    /**
     * generateDraft with invalid minutesId returns 404.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftWithInvalidIdReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->minutesGenerationService->expects($this->once())
            ->method('generateDraft')
            ->with('nonexistent-id')
            ->willThrowException(new \InvalidArgumentException("Minutes object 'nonexistent-id' not found."));

        $result = $this->controller->generateDraft('nonexistent-id');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftWithInvalidIdReturns404()

    /**
     * generateDraft when the linked Meeting is missing returns 422.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftWithMissingMeetingReturns422(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->minutesGenerationService->expects($this->once())
            ->method('generateDraft')
            ->willThrowException(new MissingRelationException('No linked Meeting found.'));

        $result = $this->controller->generateDraft('minutes-uuid-004');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftWithMissingMeetingReturns422()

    /**
     * generateDraft when OpenRegister is unavailable returns 503.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftWhenOpenRegisterUnavailableReturns503(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->minutesGenerationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-uuid-002')
            ->willThrowException(new \RuntimeException('OpenRegister ObjectService is not available.'));

        $result = $this->controller->generateDraft('minutes-uuid-002');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftWhenOpenRegisterUnavailableReturns503()

    /**
     * generateDraft by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);

        // Service must NOT be called — access check happens before delegation.
        $this->minutesGenerationService->expects($this->never())->method('generateDraft');

        $result = $this->controller->generateDraft('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftByNonAdminReturns403()

    /**
     * generateDraft for an unauthenticated request returns 401.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftUnauthenticatedReturns401(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
        );

        // The service must NOT be called for an unauthenticated request.
        $this->minutesGenerationService->expects($this->never())->method('generateDraft');

        $result = $unauthController->generateDraft('minutes-uuid-003');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftUnauthenticatedReturns401()

    /**
     * transition() with a non-admin user attempting a restricted state returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testTransitionToApprovedByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->request->method('getParam')->with('lifecycle')->willReturn('approved');

        // Service must NOT be called — access check happens before delegation.
        $this->minutesGenerationService->expects($this->never())->method('transition');

        $result = $this->controller->transition('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testTransitionToApprovedByNonAdminReturns403()

    /**
     * transition() by an admin succeeds and returns the updated minutes.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testTransitionByAdminSucceeds(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('lifecycle')->willReturn('approved');

        $updated = ['id' => 'minutes-uuid-001', 'lifecycle' => 'approved', 'approvedAt' => '2026-04-14T10:00:00+00:00'];
        $this->minutesGenerationService->expects($this->once())
            ->method('transition')
            ->with(
                minutesId: 'minutes-uuid-001',
                newLifecycle: 'approved',
                displayName: 'Test User'
            )
            ->willReturn($updated);

        $result = $this->controller->transition('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame('approved', $result->getData()['lifecycle']);

    }//end testTransitionByAdminSucceeds()

    /**
     * transition() with an invalid state returns 422.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testTransitionInvalidStepReturns422(): void
    {
        $this->request->method('getParam')->with('lifecycle')->willReturn('published');
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);

        $this->minutesGenerationService->expects($this->once())
            ->method('transition')
            ->willThrowException(new \InvalidArgumentException('Invalid lifecycle transition.'));

        $result = $this->controller->transition('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testTransitionInvalidStepReturns422()

    /**
     * transition() when OpenRegister is unavailable returns 503.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testTransitionWhenOpenRegisterUnavailableReturns503(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('lifecycle')->willReturn('review');

        $this->minutesGenerationService->expects($this->once())
            ->method('transition')
            ->willThrowException(new \RuntimeException('OpenRegister ObjectService is not available.'));

        $result = $this->controller->transition('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testTransitionWhenOpenRegisterUnavailableReturns503()

    /**
     * transition() when minutes not found returns 404.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testTransitionMinutesNotFoundReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('lifecycle')->willReturn('review');

        $this->minutesGenerationService->expects($this->once())
            ->method('transition')
            ->willThrowException(new MissingObjectException('Minutes object "x" not found.'));

        $result = $this->controller->transition('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testTransitionMinutesNotFoundReturns404()

}//end class
