<?php

/**
 * Unit tests for MinutesController.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MinutesController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
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
    private MinutesGenerationService&MockObject $generationService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request           = $this->createMock(originalClassName: IRequest::class);
        $this->generationService = $this->createMock(originalClassName: MinutesGenerationService::class);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->generationService,
        );

    }//end setUp()

    /**
     * Test that generateDraft returns preview JSON on success.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftReturnsPreviewJson(): void
    {
        $previewText = 'NOTULEN\nRaadsvergadering 10 april 2025\n...';

        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-uuid-1')
            ->willReturn($previewText);

        $result = $this->controller->generateDraft('minutes-uuid-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: $previewText, actual: $result->getData()['preview']);
        self::assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());

    }//end testGenerateDraftReturnsPreviewJson()

    /**
     * Test that generateDraft returns 404 for invalid minutesId.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftInvalidIdReturns404(): void
    {
        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('non-existent-id')
            ->willThrowException(new \RuntimeException('Notulen object niet gevonden'));

        $result = $this->controller->generateDraft('non-existent-id');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());
        self::assertArrayHasKey(key: 'message', array: $result->getData());

    }//end testGenerateDraftInvalidIdReturns404()

    /**
     * Test that generateDraft returns 404 when meeting is not linked.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-19
     */
    public function testGenerateDraftMissingMeetingReturns404(): void
    {
        $this->generationService->expects($this->once())
            ->method('generateDraft')
            ->with('minutes-no-meeting')
            ->willThrowException(new \RuntimeException('Geen vergadering gekoppeld'));

        $result = $this->controller->generateDraft('minutes-no-meeting');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());

    }//end testGenerateDraftMissingMeetingReturns404()
}//end class
