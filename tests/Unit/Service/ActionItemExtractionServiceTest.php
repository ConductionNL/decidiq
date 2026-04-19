<?php

/**
 * Unit tests for ActionItemExtractionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ActionItemExtractionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 */
class ActionItemExtractionServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ActionItemExtractionService
     */
    private ActionItemExtractionService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new ActionItemExtractionService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test extractFromContent detects Actie: marker.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     *
     * @return void
     */
    public function testExtractFromContentDetectsActieMarker(): void
    {
        $content = "Agendapunt 1\nActie: Griffier stuurt notulen vóór vrijdag\nActie: Secretaris maakt lijstje";

        $candidates = $this->service->extractFromContent($content);

        $this->assertGreaterThanOrEqual(2, count($candidates));
        $this->assertTrue(
            isset($candidates[0]['title']) && strpos($candidates[0]['title'], 'Griffier') !== false
        );
    }//end testExtractFromContentDetectsActieMarker()

    /**
     * Test extractFromContent detects wordt verzocht phrase.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     *
     * @return void
     */
    public function testExtractFromContentDetectsWordtVerzoechtPhrase(): void
    {
        $content = "Iedereen wordt verzocht om aanwezig te zijn op de volgende vergadering.";

        $candidates = $this->service->extractFromContent($content);

        $this->assertGreaterThan(0, count($candidates));
    }//end testExtractFromContentDetectsWordtVerzoechtPhrase()

    /**
     * Test extractFromContent returns empty for content with no markers.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     *
     * @return void
     */
    public function testExtractFromContentReturnsEmptyForUnmatchedContent(): void
    {
        $content = "Just regular minutes text without any action items. Nothing to see here.";

        $candidates = $this->service->extractFromContent($content);

        $this->assertEquals(count($candidates), 0);
    }//end testExtractFromContentReturnsEmptyForUnmatchedContent()

    /**
     * Test extractFromContent matches known participant name in line.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     *
     * @return void
     */
    public function testExtractFromContentMatchesParticipantName(): void
    {
        $content = "Actie: Jan Pieterzoon stuurt het rapport.";
        $knownParticipants = [
            'Jan Pieterzoon' => 'jan',
            'Maria Smith' => 'maria',
        ];

        $candidates = $this->service->extractFromContent($content, $knownParticipants);

        $this->assertGreaterThan(0, count($candidates));
        $this->assertNotNull($candidates[0]['suggestedAssignee']);
        $this->assertEquals($candidates[0]['suggestedAssignee'], 'Jan Pieterzoon');
    }//end testExtractFromContentMatchesParticipantName()
}//end class
