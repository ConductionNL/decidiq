<?php

/**
 * Unit tests for BoardProxyService scope logic.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\BoardProxyService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for proxy scope and status logic (REQ-012).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
final class BoardProxyServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var BoardProxyService
     */
    private BoardProxyService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoardProxyService(
            $this->createMock(ContainerInterface::class),
            $this->createMock(BoardAuditLogService::class)
        );

    }//end setUp()

    /**
     * A full active proxy is valid for any resolution.
     *
     * @return void
     */
    public function testFullProxyActiveForAnyResolution(): void
    {
        $proxy = ['status' => 'active', 'scope' => 'full'];
        $this->assertTrue($this->service->isActiveForResolution($proxy, 'res-anything'));

    }//end testFullProxyActiveForAnyResolution()

    /**
     * A per-agenda-item proxy is only valid for in-scope resolutions.
     *
     * @return void
     */
    public function testScopedProxyOnlyForListedResolutions(): void
    {
        $proxy = ['status' => 'active', 'scope' => 'per-agenda-item', 'scopedResolutionUids' => ['res-1']];
        $this->assertTrue($this->service->isActiveForResolution($proxy, 'res-1'));
        $this->assertFalse($this->service->isActiveForResolution($proxy, 'res-2'));

    }//end testScopedProxyOnlyForListedResolutions()

    /**
     * A suspended proxy is never active.
     *
     * @return void
     */
    public function testSuspendedProxyInactive(): void
    {
        $proxy = ['status' => 'suspended', 'scope' => 'full'];
        $this->assertFalse($this->service->isActiveForResolution($proxy, 'res-1'));

    }//end testSuspendedProxyInactive()
}//end class
