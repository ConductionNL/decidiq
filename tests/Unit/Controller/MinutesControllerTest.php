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
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesService;
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
     * Mock ALVMinutesService.
     *
     * @var ALVMinutesService&MockObject
     */
    private ALVMinutesService&MockObject $alvMinutesService;

    /**
     * Mock ActionItemExtractionService.
     *
     * @var ActionItemExtractionService&MockObject
     */
    private ActionItemExtractionService&MockObject $extractionService;

    /**
     * Mock MinutesService.
     *
     * @var MinutesService&MockObject
     */
    private MinutesService&MockObject $minutesService;

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

        $this->request                  = $this->createMock(originalClassName: IRequest::class);
        $this->minutesGenerationService = $this->createMock(originalClassName: MinutesGenerationService::class);
        $this->alvMinutesService        = $this->createMock(originalClassName: ALVMinutesService::class);
        $this->extractionService        = $this->createMock(originalClassName: ActionItemExtractionService::class);
        $this->minutesService           = $this->createMock(originalClassName: MinutesService::class);
        $this->userSession              = $this->createMock(originalClassName: IUserSession::class);
        $this->groupManager             = $this->createMock(originalClassName: IGroupManager::class);
        $this->objectService            = $this->createMock(originalClassName: ObjectService::class);
        $this->user                     = $this->createMock(originalClassName: IUser::class);

        $this->user->method('getUID')->willReturn('testuser');
        $this->user->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            extractionService: $this->extractionService,
            minutesService: $this->minutesService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            objectService: $this->objectService,
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
            alvMinutesService: $this->alvMinutesService,
            extractionService: $this->extractionService,
            minutesService: $this->minutesService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            objectService: $this->objectService,
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
                displayName: 'Test User',
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

    /**
     * generateALVDraft by an unauthenticated request returns 401.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     *
     * @return void
     */
    public function testGenerateALVDraftUnauthenticatedReturns401(): void
    {
        $unauthSession = $this->createMock(originalClassName: IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            extractionService: $this->extractionService,
            minutesService: $this->minutesService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            objectService: $this->objectService,
        );

        $this->alvMinutesService->expects($this->never())->method('generateALVDraft');

        $result = $unauthController->generateALVDraft('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testGenerateALVDraftUnauthenticatedReturns401()

    /**
     * generateALVDraft by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     *
     * @return void
     */
    public function testGenerateALVDraftByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->alvMinutesService->expects($this->never())->method('generateALVDraft');

        $result = $this->controller->generateALVDraft('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testGenerateALVDraftByNonAdminReturns403()

    /**
     * generateALVDraft by an admin returns the preview.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     *
     * @return void
     */
    public function testGenerateALVDraftByAdminReturnsPreview(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->alvMinutesService->expects($this->once())
            ->method('generateALVDraft')
            ->with('minutes-uuid-001')
            ->willReturn(['content' => 'ALV concept notulen...']);

        $result = $this->controller->generateALVDraft('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertArrayHasKey('preview', $result->getData());

    }//end testGenerateALVDraftByAdminReturnsPreview()

    /**
     * distributeALVMinutes by an unauthenticated request returns 401.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     *
     * @return void
     */
    public function testDistributeALVMinutesUnauthenticatedReturns401(): void
    {
        $unauthSession = $this->createMock(originalClassName: IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $unauthController = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            extractionService: $this->extractionService,
            minutesService: $this->minutesService,
            userSession: $unauthSession,
            groupManager: $this->groupManager,
            objectService: $this->objectService,
        );

        $this->alvMinutesService->expects($this->never())->method('distribute');

        $result = $unauthController->distributeALVMinutes('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testDistributeALVMinutesUnauthenticatedReturns401()

    /**
     * distributeALVMinutes by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     *
     * @return void
     */
    public function testDistributeALVMinutesByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->alvMinutesService->expects($this->never())->method('distribute');

        $result = $this->controller->distributeALVMinutes('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testDistributeALVMinutesByNonAdminReturns403()

    /**
     * Build a mock ObjectEntity returning $data from jsonSerialize().
     *
     * @param array<string,mixed> $data
     *
     * @return object
     */
    private function makeEntity(array $data): object
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;
    }

    /**
     * extractActionItems by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     *
     * @return void
     */
    public function testExtractActionItemsByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->objectService->expects($this->never())->method('find');

        $result = $this->controller->extractActionItems('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testExtractActionItemsByNonAdminReturns403()

    /**
     * extractActionItems by an admin when Minutes not found returns 404.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     *
     * @return void
     */
    public function testExtractActionItemsNotFoundReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller->extractActionItems('minutes-uuid-999');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testExtractActionItemsNotFoundReturns404()

    /**
     * extractActionItems by an admin succeeds and returns candidates.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     *
     * @return void
     */
    public function testExtractActionItemsByAdminReturnsCandidates(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $minutesEntity = $this->makeEntity(['id' => 'minutes-uuid-001', 'content' => 'Actie: Jan doet X']);
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->extractionService->method('extractFromContent')->willReturn(
            [['title' => 'Jan doet X', 'suggestedAssignee' => 'Jan']]
        );

        $result = $this->controller->extractActionItems('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertArrayHasKey('candidates', $result->getData());

    }//end testExtractActionItemsByAdminReturnsCandidates()

    /**
     * saveExtractedActionItems by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     *
     * @return void
     */
    public function testSaveExtractedActionItemsByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->objectService->expects($this->never())->method('find');

        $result = $this->controller->saveExtractedActionItems('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testSaveExtractedActionItemsByNonAdminReturns403()

    /**
     * submitForApproval by a non-admin returns 403.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
     *
     * @return void
     */
    public function testSubmitForApprovalByNonAdminReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->objectService->expects($this->never())->method('find');

        $result = $this->controller->submitForApproval('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testSubmitForApprovalByNonAdminReturns403()

    /**
     * submitForApproval by an admin when lifecycle is not draft returns 409.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
     *
     * @return void
     */
    public function testSubmitForApprovalNonDraftReturns409(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $minutesEntity = $this->makeEntity(['id' => 'minutes-uuid-001', 'lifecycle' => 'review']);
        $this->objectService->method('find')->willReturn($minutesEntity);

        $result = $this->controller->submitForApproval('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_CONFLICT, $result->getStatus());

    }//end testSubmitForApprovalNonDraftReturns409()

    /**
     * submitForApproval by an admin with draft Minutes succeeds.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
     *
     * @return void
     */
    public function testSubmitForApprovalByAdminSucceeds(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $minutesEntity = $this->makeEntity(['id' => 'minutes-uuid-001', 'lifecycle' => 'draft']);
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->once())->method('saveObject');
        $this->minutesService->method('notifyApproversOnSubmit')->willReturn(2);

        $result = $this->controller->submitForApproval('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame('review', $result->getData()['lifecycle']);
        self::assertSame(2, $result->getData()['notified']);

    }//end testSubmitForApprovalByAdminSucceeds()

}//end class
