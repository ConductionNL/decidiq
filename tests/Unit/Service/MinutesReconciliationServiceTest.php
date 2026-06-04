<?php

/**
 * Unit tests for MinutesReconciliationService structural comparison.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesReconciliationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for multilingual minutes reconciliation (REQ-006).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
 */
final class MinutesReconciliationServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var MinutesReconciliationService
     */
    private MinutesReconciliationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MinutesReconciliationService(
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()

    /**
     * Structure extraction counts resolution refs and headings.
     *
     * @return void
     */
    public function testExtractStructure(): void
    {
        $content   = "# Heading\nR-2025-001 adopted\n## Section\nR-2025-002 rejected";
        $structure = $this->service->extractStructure($content);
        $this->assertSame(2, $structure['resolutionCount']);
        $this->assertSame(2, $structure['sectionCount']);

    }//end testExtractStructure()

    /**
     * Matching structures reconcile cleanly.
     *
     * @return void
     */
    public function testReconcileMatchingContents(): void
    {
        $nl     = "# Kop\nR-2025-001 aangenomen";
        $en     = "# Heading\nR-2025-001 adopted";
        $result = $this->service->reconcileContents($nl, $en);
        $this->assertSame('ok', $result['severity']);
        $this->assertSame([], $result['discrepancies']);

    }//end testReconcileMatchingContents()

    /**
     * Diverging resolution counts produce an error-level discrepancy.
     *
     * @return void
     */
    public function testReconcileDivergingResolutions(): void
    {
        $nl     = "R-2025-001 aangenomen\nR-2025-002 aangenomen";
        $en     = "R-2025-001 adopted";
        $result = $this->service->reconcileContents($nl, $en);
        $this->assertSame('error', $result['severity']);
        $this->assertContains('resolution-count-mismatch', $result['discrepancies']);

    }//end testReconcileDivergingResolutions()
}//end class
