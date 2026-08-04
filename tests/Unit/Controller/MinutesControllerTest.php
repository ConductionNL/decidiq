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
use OCA\Decidesk\Controller\MinutesCorrectionController;
use OCA\Decidesk\Controller\MinutesResponder;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\MinutesAccessGuard;
use OCA\Decidesk\Service\MinutesDocumentService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesWorkflowService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
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
     * Mock MinutesWorkflowService.
     *
     * @var MinutesWorkflowService&MockObject
     */
    private MinutesWorkflowService&MockObject $workflowService;

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
     * Mock ParticipantResolver.
     *
     * @var ParticipantResolver&MockObject
     */
    private ParticipantResolver&MockObject $participantResolver;

    /**
     * Mock MinutesDocumentService.
     *
     * @var MinutesDocumentService&MockObject
     */
    private MinutesDocumentService&MockObject $minutesDocumentService;

    /**
     * The correction half of the Minutes API, under test for the correction cases.
     *
     * @var MinutesCorrectionController
     */
    private MinutesCorrectionController $correctionController;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request                  = $this->createMock(originalClassName: IRequest::class);
        $this->minutesGenerationService = $this->createMock(originalClassName: MinutesGenerationService::class);
        $this->alvMinutesService        = $this->createMock(originalClassName: ALVMinutesService::class);
        $this->workflowService          = $this->createMock(originalClassName: MinutesWorkflowService::class);
        $this->userSession              = $this->createMock(originalClassName: IUserSession::class);
        $this->groupManager             = $this->createMock(originalClassName: IGroupManager::class);
        $this->objectService            = $this->createMock(originalClassName: ObjectService::class);
        $this->user                     = $this->createMock(originalClassName: IUser::class);
        $this->participantResolver      = $this->createMock(originalClassName: ParticipantResolver::class);
        $this->minutesDocumentService   = $this->createMock(originalClassName: MinutesDocumentService::class);

        $this->user->method('getUID')->willReturn('testuser');
        $this->user->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($this->user);

        // The responder is a pure exception-to-status mapper with no
        // dependencies, so the real one is used: these tests assert on the
        // status codes it produces, which a mock could not demonstrate.
        $this->controller = new MinutesController(
            request: $this->request,
            generationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            workflowService: $this->workflowService,
            userSession: $this->userSession,
            accessGuard: new MinutesAccessGuard(
                objectService: $this->objectService,
                participantResolver: $this->participantResolver,
                userSession: $this->userSession,
                groupManager: $this->groupManager,
            ),
            documentService: $this->minutesDocumentService,
            responder: new MinutesResponder(),
        );

        // The correction endpoints moved to MinutesCorrectionController; it is
        // built from the SAME mocks and the SAME (real) MinutesAccessGuard, so
        // the correction tests below still exercise the identical
        // authorisation + persistence behaviour they always did.
        $this->correctionController = new MinutesCorrectionController(
            request: $this->request,
            accessGuard: new MinutesAccessGuard(
                objectService: $this->objectService,
                participantResolver: $this->participantResolver,
                userSession: $this->userSession,
                groupManager: $this->groupManager,
            ),
            objectService: $this->objectService,
            userSession: $this->userSession,
        );

    }//end setUp()


    /**
     * Build a minutes ObjectEntity mock linked to a meeting.
     *
     * The mock represents a minutes record with a meeting relation, which is
     * required by requireChairOrAdminForMinutes to resolve the meeting ID.
     *
     * @param string $minutesId The minutes UUID
     * @param string $meetingId The linked meeting UUID
     *
     * @return ObjectEntity&MockObject
     */
    private function makeMinutesEntity(string $minutesId, string $meetingId): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(
            [
                'id'        => $minutesId,
                'relations' => ['meeting' => ['id' => $meetingId]],
            ]
        );
        return $entity;

    }//end makeMinutesEntity()


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

        // Provide a minutes entity so requireChairOrAdminForMinutes can resolve the meeting.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);

        // Testuser is not chair/secretary → participantResolver denies.
        $this->participantResolver->method('hasRole')->willReturn(false);

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
            generationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            workflowService: $this->workflowService,
            userSession: $unauthSession,
            accessGuard: new MinutesAccessGuard(
                objectService: $this->objectService,
                participantResolver: $this->participantResolver,
                userSession: $unauthSession,
                groupManager: $this->groupManager,
            ),
            documentService: $this->minutesDocumentService,
            responder: new MinutesResponder(),
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

        // Provide a minutes entity so requireChairOrAdminForMinutes can resolve the meeting.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);

        // Testuser is not chair/secretary → participantResolver denies.
        $this->participantResolver->method('hasRole')->willReturn(false);

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
            generationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            workflowService: $this->workflowService,
            userSession: $unauthSession,
            accessGuard: new MinutesAccessGuard(
                objectService: $this->objectService,
                participantResolver: $this->participantResolver,
                userSession: $unauthSession,
                groupManager: $this->groupManager,
            ),
            documentService: $this->minutesDocumentService,
            responder: new MinutesResponder(),
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

        // Provide a minutes entity and deny role check so requireChairOrAdminForMinutes returns 403.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->participantResolver->method('hasRole')->willReturn(false);

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
            generationService: $this->minutesGenerationService,
            alvMinutesService: $this->alvMinutesService,
            workflowService: $this->workflowService,
            userSession: $unauthSession,
            accessGuard: new MinutesAccessGuard(
                objectService: $this->objectService,
                participantResolver: $this->participantResolver,
                userSession: $unauthSession,
                groupManager: $this->groupManager,
            ),
            documentService: $this->minutesDocumentService,
            responder: new MinutesResponder(),
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

        // Provide a minutes entity and deny role check so requireChairOrAdminForMinutes returns 403.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->participantResolver->method('hasRole')->willReturn(false);

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
        $entity = $this->createMock(ObjectEntity::class);
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

        // requireChairOrAdminForMinutes calls find() to resolve the meeting; provide minutes entity.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->participantResolver->method('hasRole')->willReturn(false);

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
        $this->workflowService->method('extractActionItems')
            ->willThrowException(new MissingObjectException(message: 'Minutes not found.'));

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
        $this->workflowService->method('extractActionItems')->willReturn(
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

        // requireChairOrAdminForMinutes calls find() to resolve the meeting; provide minutes entity.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->participantResolver->method('hasRole')->willReturn(false);

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

        // requireChairOrAdminForMinutes calls find() to resolve the meeting; provide minutes entity.
        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->participantResolver->method('hasRole')->willReturn(false);

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
        $this->workflowService->method('submitForApproval')
            ->willThrowException(
                new \RuntimeException('Minutes must be in draft state to submit for approval.', 409)
            );

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
        $this->workflowService->expects($this->once())
            ->method('submitForApproval')
            ->willReturn(
                [
                    'lifecycle' => 'review',
                    'notified'  => 2,
                ]
            );

        $result = $this->controller->submitForApproval('minutes-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame('review', $result->getData()['lifecycle']);
        self::assertSame(2, $result->getData()['notified']);

    }//end testSubmitForApprovalByAdminSucceeds()

    /**
     * addCorrection by a meeting participant succeeds with a
     * server-attributed author (client-sent author fields are ignored).
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionByParticipantSucceedsWithServerAttribution(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->participantResolver->method('isParticipant')
            ->with(meetingId: 'meeting-uuid-1', nextcloudUid: 'testuser')
            ->willReturn(true);
        $this->request->method('getParam')->with('text')->willReturn('The vote count for item 5 should read 12.');

        $minutesEntity = $this->makeEntity(
            [
                'id'        => 'minutes-uuid-001',
                'lifecycle' => 'review',
                'relations' => ['meeting' => ['id' => 'meeting-uuid-1']],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->once())->method('saveObject');

        $result = $this->correctionController->addCorrection('minutes-uuid-001');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        $corrections = $result->getData()['corrections'];
        self::assertCount(1, $corrections);
        self::assertSame('testuser', $corrections[0]['author']);
        self::assertSame('Test User', $corrections[0]['authorName']);
        self::assertSame('proposed', $corrections[0]['status']);

    }//end testAddCorrectionByParticipantSucceedsWithServerAttribution()

    /**
     * addCorrection with empty text returns 400.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionWithEmptyTextReturns400(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('text')->willReturn('   ');

        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->addCorrection('minutes-uuid-001');

        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testAddCorrectionWithEmptyTextReturns400()

    /**
     * addCorrection by a non-participant returns 403 (fail closed).
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionByNonParticipantReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->participantResolver->method('isParticipant')->willReturn(false);

        $minutesEntity = $this->makeEntity(
            [
                'id'        => 'minutes-uuid-001',
                'lifecycle' => 'review',
                'relations' => ['meeting' => ['id' => 'meeting-uuid-1']],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->addCorrection('minutes-uuid-001');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testAddCorrectionByNonParticipantReturns403()

    /**
     * addCorrection with an unresolvable meeting returns 403 for
     * non-admins — the guard fails closed, never open.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionFailsClosedWhenMeetingUnresolvable(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);

        // Minutes record without a meeting relation → meeting unresolvable.
        $minutesEntity = $this->makeEntity(['id' => 'minutes-uuid-001', 'lifecycle' => 'review']);
        $this->objectService->method('find')->willReturn($minutesEntity);

        $this->participantResolver->expects($this->never())->method('isParticipant');
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->addCorrection('minutes-uuid-001');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testAddCorrectionFailsClosedWhenMeetingUnresolvable()

    /**
     * addCorrection on approved minutes returns 409.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionOnApprovedMinutesReturns409(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('text')->willReturn('Too late.');

        $minutesEntity = $this->makeEntity(
            [
                'id'        => 'minutes-uuid-001',
                'lifecycle' => 'approved',
                'relations' => ['meeting' => ['id' => 'meeting-uuid-1']],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->addCorrection('minutes-uuid-001');

        self::assertSame(Http::STATUS_CONFLICT, $result->getStatus());

    }//end testAddCorrectionOnApprovedMinutesReturns409()

    /**
     * addCorrection on unknown minutes returns 404 (admin caller).
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testAddCorrectionUnknownMinutesReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('text')->willReturn('Fix it.');
        $this->objectService->method('find')->willReturn(null);

        $result = $this->correctionController->addCorrection('minutes-uuid-404');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testAddCorrectionUnknownMinutesReturns404()

    /**
     * resolveCorrection accepts a proposed correction and records the
     * resolver server-side.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testResolveCorrectionAcceptRecordsResolver(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('status')->willReturn('accepted');

        $minutesEntity = $this->makeEntity(
            [
                'id'          => 'minutes-uuid-001',
                'lifecycle'   => 'review',
                'relations'   => ['meeting' => ['id' => 'meeting-uuid-1']],
                'corrections' => [
                    ['id' => 'corr-1', 'text' => 'Fix item 5', 'status' => 'proposed'],
                ],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->once())->method('saveObject');

        $result = $this->correctionController->resolveCorrection('minutes-uuid-001', 'corr-1');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        $correction = $result->getData()['correction'];
        self::assertSame('accepted', $correction['status']);
        self::assertSame('testuser', $correction['resolvedBy']);

    }//end testResolveCorrectionAcceptRecordsResolver()

    /**
     * resolveCorrection with an invalid status returns 400.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testResolveCorrectionInvalidStatusReturns400(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('status')->willReturn('maybe');

        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->resolveCorrection('minutes-uuid-001', 'corr-1');

        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testResolveCorrectionInvalidStatusReturns400()

    /**
     * resolveCorrection on an already-resolved correction returns 409.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testResolveCorrectionAlreadyResolvedReturns409(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('status')->willReturn('rejected');

        $minutesEntity = $this->makeEntity(
            [
                'id'          => 'minutes-uuid-001',
                'corrections' => [
                    ['id' => 'corr-1', 'text' => 'Fix item 5', 'status' => 'accepted'],
                ],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->resolveCorrection('minutes-uuid-001', 'corr-1');

        self::assertSame(Http::STATUS_CONFLICT, $result->getStatus());

    }//end testResolveCorrectionAlreadyResolvedReturns409()

    /**
     * resolveCorrection on an unknown correction id returns 404.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testResolveCorrectionUnknownCorrectionReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('status')->willReturn('accepted');

        $minutesEntity = $this->makeEntity(['id' => 'minutes-uuid-001', 'corrections' => []]);
        $this->objectService->method('find')->willReturn($minutesEntity);

        $result = $this->correctionController->resolveCorrection('minutes-uuid-001', 'corr-x');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testResolveCorrectionUnknownCorrectionReturns404()

    /**
     * resolveCorrection by a non-chair participant returns 403 — resolution
     * stays chair/secretary-gated even though suggesting is participant-open.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testResolveCorrectionByNonChairReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->participantResolver->method('hasRole')->willReturn(false);

        $minutesEntity = $this->makeEntity(
            [
                'id'        => 'minutes-uuid-001',
                'relations' => ['meeting' => ['id' => 'meeting-uuid-1']],
            ]
        );
        $this->objectService->method('find')->willReturn($minutesEntity);
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->correctionController->resolveCorrection('minutes-uuid-001', 'corr-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testResolveCorrectionByNonChairReturns403()

    /**
     * reject delegates to MinutesGenerationService::reject and returns 200.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testRejectByAdminSucceeds(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('comment')->willReturn('Attendance list incomplete');

        $this->minutesGenerationService->expects($this->once())
            ->method('reject')
            ->with(
                minutesId: 'minutes-uuid-001',
                comment: 'Attendance list incomplete',
                userId: 'testuser',
            )
            ->willReturn(['id' => 'minutes-uuid-001', 'lifecycle' => 'draft']);

        $result = $this->controller->reject('minutes-uuid-001');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame('draft', $result->getData()['lifecycle']);

    }//end testRejectByAdminSucceeds()

    /**
     * reject without a comment maps the service's InvalidArgumentException
     * to 422 — a rejection without a comment is refused.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testRejectWithoutCommentReturns422(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('comment')->willReturn('');

        $this->minutesGenerationService->method('reject')
            ->willThrowException(new \InvalidArgumentException('A rejection comment is required.', 422));

        $result = $this->controller->reject('minutes-uuid-001');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testRejectWithoutCommentReturns422()

    /**
     * reject on unknown minutes returns 404.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testRejectUnknownMinutesReturns404(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('comment')->willReturn('Nope');

        $this->minutesGenerationService->method('reject')
            ->willThrowException(new MissingObjectException(message: 'Minutes "x" not found.'));

        $result = $this->controller->reject('minutes-uuid-404');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testRejectUnknownMinutesReturns404()

    /**
     * reject by a non-chair participant returns 403, service never called.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testRejectByNonChairReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->participantResolver->method('hasRole')->willReturn(false);

        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);

        $this->minutesGenerationService->expects($this->never())->method('reject');

        $result = $this->controller->reject('minutes-uuid-001');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testRejectByNonChairReturns403()

    /**
     * generateDocument delegates to MinutesDocumentService and returns 200
     * with the honest docudesk availability flag.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testGenerateDocumentByAdminSucceeds(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('format', 'markdown')->willReturn('pdf');

        $this->minutesDocumentService->expects($this->once())
            ->method('generate')
            ->with(
                minutesId: 'minutes-uuid-001',
                format: 'pdf',
                displayName: 'Test User',
            )
            ->willReturn(
                [
                    'path'     => 'Decidesk/Raad/2026-06-12 Raadsvergadering/Minutes/Notulen v1.md',
                    'format'   => 'markdown',
                    'docudesk' => false,
                    'note'     => 'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.',
                ]
            );

        $result = $this->controller->generateDocument('minutes-uuid-001');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertFalse($result->getData()['docudesk']);
        self::assertArrayHasKey('note', $result->getData());

    }//end testGenerateDocumentByAdminSucceeds()

    /**
     * generateDocument with an unsupported format returns 422.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testGenerateDocumentUnsupportedFormatReturns422(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('format', 'markdown')->willReturn('odt');

        $this->minutesDocumentService->method('generate')
            ->willThrowException(new \InvalidArgumentException('Unsupported format "odt".', 422));

        $result = $this->controller->generateDocument('minutes-uuid-001');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testGenerateDocumentUnsupportedFormatReturns422()

    /**
     * generateDocument when the Files backend is unavailable returns 503.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testGenerateDocumentFilesUnavailableReturns503(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
        $this->request->method('getParam')->with('format', 'markdown')->willReturn('markdown');

        $this->minutesDocumentService->method('generate')
            ->willThrowException(new \RuntimeException('Files backend unavailable.', 503));

        $result = $this->controller->generateDocument('minutes-uuid-001');

        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testGenerateDocumentFilesUnavailableReturns503()

    /**
     * generateDocument by a non-chair participant returns 403.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testGenerateDocumentByNonChairReturns403(): void
    {
        $this->groupManager->method('isAdmin')->with('testuser')->willReturn(false);
        $this->participantResolver->method('hasRole')->willReturn(false);

        $minutesEntity = $this->makeMinutesEntity('minutes-uuid-001', 'meeting-uuid-1');
        $this->objectService->method('find')->willReturn($minutesEntity);

        $this->minutesDocumentService->expects($this->never())->method('generate');

        $result = $this->controller->generateDocument('minutes-uuid-001');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testGenerateDocumentByNonChairReturns403()

}//end class
