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
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for MinutesController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class MinutesControllerTest extends TestCase
{

    /**
     * The controller under test.
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
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

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
        $this->container                = $this->createMock(ContainerInterface::class);

        // Default: authenticated user.
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            userSession: $this->userSession,
            container: $this->container,
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
     * generateDraft when OpenRegister is unavailable returns 503.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftWhenOpenRegisterUnavailableReturns503(): void
    {
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
     * generateDraft with unauthenticated request returns 401.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftUnauthenticatedReturns401(): void
    {
        // Create a fresh controller with a session that has no authenticated user.
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
            userSession: $unauthSession,
            container: $this->container,
        );

        // The generation service should never be called for unauthenticated requests.
        $this->minutesGenerationService->expects($this->never())->method('generateDraft');

        $result = $controller->generateDraft('minutes-uuid-003');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        self::assertArrayHasKey('message', $result->getData());

    }//end testGenerateDraftUnauthenticatedReturns401()

}//end class
