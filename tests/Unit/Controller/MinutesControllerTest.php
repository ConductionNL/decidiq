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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MinutesController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
 */
class MinutesControllerTest extends TestCase
{

    /**
     * Controller under test.
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request                  = $this->createMock(originalClassName: IRequest::class);
        $this->minutesGenerationService = $this->createMock(originalClassName: MinutesGenerationService::class);

        $this->controller = new MinutesController(
            request: $this->request,
            minutesGenerationService: $this->minutesGenerationService,
        );

    }//end setUp()

    /**
     * Test that generateDraft returns a JSON response with the preview text.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
     *
     * @return void
     */
    public function testGenerateDraftReturnsPreviewJson(): void
    {
        $minutesId   = 'valid-minutes-uuid';
        $previewText = '# Notulen Testvergadering' . "\n\n" . 'Concept gegenereerd...';

        $this->minutesGenerationService
            ->expects($this->once())
            ->method('generateDraft')
            ->with($minutesId)
            ->willReturn($previewText);

        $response = $this->controller->generateDraft($minutesId);
        $data     = $response->getData();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertArrayHasKey('preview', $data);
        self::assertSame($previewText, $data['preview']);

    }//end testGenerateDraftReturnsPreviewJson()

    /**
     * Test that generateDraft returns 404 when the Minutes object is not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
     *
     * @return void
     */
    public function testGenerateDraftWithInvalidMinutesIdReturns404(): void
    {
        $minutesId = 'non-existent-uuid';

        $this->minutesGenerationService
            ->method('generateDraft')
            ->with($minutesId)
            ->willThrowException(new \RuntimeException('Minutes object with id "' . $minutesId . '" not found.'));

        $response = $this->controller->generateDraft($minutesId);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $data = $response->getData();
        self::assertArrayHasKey('message', $data);
        self::assertStringContainsString('not found', $data['message']);

    }//end testGenerateDraftWithInvalidMinutesIdReturns404()

    /**
     * Test that generateDraft returns 400 when an empty minutesId is provided.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
     *
     * @return void
     */
    public function testGenerateDraftWithEmptyMinutesIdReturnsBadRequest(): void
    {
        $response = $this->controller->generateDraft('');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $data = $response->getData();
        self::assertArrayHasKey('message', $data);

    }//end testGenerateDraftWithEmptyMinutesIdReturnsBadRequest()

    /**
     * Test that generateDraft returns 400 when no linked meeting exists.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
     *
     * @return void
     */
    public function testGenerateDraftReturnsBadRequestWhenMeetingMissing(): void
    {
        $minutesId = 'minutes-without-meeting';

        $this->minutesGenerationService
            ->method('generateDraft')
            ->with($minutesId)
            ->willThrowException(
                new \RuntimeException(
                    'No linked Meeting found for Minutes "' . $minutesId . '".'
                )
            );

        $response = $this->controller->generateDraft($minutesId);

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $data = $response->getData();
        self::assertArrayHasKey('message', $data);

    }//end testGenerateDraftReturnsBadRequestWhenMeetingMissing()

    /**
     * Test that generateDraft returns 500 on unexpected errors.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.3
     *
     * @return void
     */
    public function testGenerateDraftReturnsInternalServerErrorOnUnexpectedException(): void
    {
        $minutesId = 'some-uuid';

        $this->minutesGenerationService
            ->method('generateDraft')
            ->with($minutesId)
            ->willThrowException(new \Exception('Unexpected error'));

        $response = $this->controller->generateDraft($minutesId);

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        self::assertArrayHasKey('message', $data);

    }//end testGenerateDraftReturnsInternalServerErrorOnUnexpectedException()

}//end class
