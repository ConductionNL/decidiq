<?php

/**
 * Test Suite for ActionItemExtractionService
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ActionItemExtractionService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ActionItemExtractionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 */
class ActionItemExtractionServiceTest extends TestCase
{
    private ActionItemExtractionService $service;
    private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ActionItemExtractionService($this->container, $this->logger);
    }

    /**
     * Test that extractFromContent detects "Actie:" marker.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     */
    public function testExtractFromContentDetectsActieMarker(): void
    {
        $content = <<<'CONTENT'
Agendapunt 1 - Financieel verslag
De financieel controller geeft toelichting op de cijfers.

Actie: Griffier verstuurt definitief rapport naar alle leden
AI: Voorzitter bereikt bestuursbesluit

Agendapunt 2 - Volgende vergadering
CONTENT;

        $candidates = $this->service->extractFromContent($content);

        $this->assertIsArray($candidates);
        $this->assertGreaterThanOrEqual(1, count($candidates));
        $this->assertStringContainsString('Griffier', $candidates[0]['title']);
    }

    /**
     * Test that extractFromContent detects "wordt verzocht" phrase.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     */
    public function testExtractFromContentDetectsWordtVerzoechtPhrase(): void
    {
        $content = <<<'CONTENT'
De secretaris wordt verzocht om de uitnodiging uit te sturen vóór 15 mei.
De penningmeester zal de begroting finaliseren.
CONTENT;

        $candidates = $this->service->extractFromContent($content);

        $this->assertIsArray($candidates);
        $this->assertGreaterThanOrEqual(1, count($candidates));
        // Should have detected "wordt verzocht" line
        $found = false;
        foreach ($candidates as $candidate) {
            if (stripos($candidate['title'], 'secretaris') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * Test that extractFromContent returns empty for content with no markers.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     */
    public function testExtractFromContentReturnsEmptyForUnmatchedContent(): void
    {
        $content = <<<'CONTENT'
Agendapunt 1 - Opening
Agendapunt 2 - Behandeling motie
Agendapunt 3 - Rondvraag
Agendapunt 4 - Sluiting
CONTENT;

        $candidates = $this->service->extractFromContent($content);

        $this->assertIsArray($candidates);
        $this->assertCount(0, $candidates);
    }

    /**
     * Test that extractFromContent matches known participant name.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
     */
    public function testExtractFromContentMatchesKnownParticipantName(): void
    {
        $content = "Actie: Jan de Vries bereikt bestuursbesluit op 1 mei";
        $knownParticipants = ['Jan de Vries', 'Marie Hendriks'];

        $candidates = $this->service->extractFromContent($content, $knownParticipants);

        $this->assertCount(1, $candidates);
        $this->assertEquals('Jan de Vries', $candidates[0]['suggestedAssignee']);
    }
}
