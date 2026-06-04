<?php

/**
 * Unit tests for GovernanceReportingService statistics and compliance flags.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\GovernanceReportingService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for independence ratio and Code compliance checks.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */
final class GovernanceReportingServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var GovernanceReportingService
     */
    private GovernanceReportingService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GovernanceReportingService(
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()

    /**
     * Independence ratio is the fraction of independent members.
     *
     * @return void
     */
    public function testIndependenceRatio(): void
    {
        $members = [
            ['independenceStatus' => 'independent'],
            ['independenceStatus' => 'independent'],
            ['independenceStatus' => 'non-independent'],
            ['independenceStatus' => 'non-independent'],
        ];
        $this->assertSame(0.5, $this->service->independenceRatio($members));
        $this->assertSame(0.0, $this->service->independenceRatio([]));

    }//end testIndependenceRatio()

    /**
     * Compliance flags fire on too few meetings and low independence.
     *
     * @return void
     */
    public function testComplianceFlagCheck(): void
    {
        $ok = $this->service->complianceFlagCheck(['meetingCount' => 6, 'independenceRatio' => 0.6]);
        $this->assertTrue($ok['passed']);
        $this->assertSame([], $ok['flags']);

        $bad = $this->service->complianceFlagCheck(['meetingCount' => 2, 'independenceRatio' => 0.3]);
        $this->assertFalse($bad['passed']);
        $this->assertContains('insufficient-meeting-frequency', $bad['flags']);
        $this->assertContains('low-independence-ratio', $bad['flags']);

    }//end testComplianceFlagCheck()
}//end class
