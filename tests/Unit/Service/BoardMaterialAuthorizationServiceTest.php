<?php

/**
 * Unit tests for BoardMaterialAuthorizationService access matrix.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for least-privilege material access (compartments).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */
final class BoardMaterialAuthorizationServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var BoardMaterialAuthorizationService
     */
    private BoardMaterialAuthorizationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoardMaterialAuthorizationService(
            $this->createMock(ContainerInterface::class),
            $this->createMock(BoardAuditLogService::class)
        );

    }//end setUp()

    /**
     * A plain member sees board-only material but not executive sessions.
     *
     * @return void
     */
    public function testMemberCannotSeeExecutiveOnly(): void
    {
        $this->assertTrue($this->service->roleCanViewLevel('member', 'board-only'));
        $this->assertFalse($this->service->roleCanViewLevel('member', 'executive-only'));

    }//end testMemberCannotSeeExecutiveOnly()

    /**
     * An external auditor sees audit-committee material but not the boardroom.
     *
     * @return void
     */
    public function testExternalAuditorScope(): void
    {
        $this->assertTrue($this->service->roleCanViewLevel('external-auditor', 'audit-committee'));
        $this->assertTrue($this->service->roleCanViewLevel('external-auditor', 'external-auditor'));
        $this->assertFalse($this->service->roleCanViewLevel('external-auditor', 'board-only'));

    }//end testExternalAuditorScope()

    /**
     * Filtering reduces a list to the role's permitted compartments.
     *
     * @return void
     */
    public function testFilterMaterialsByRole(): void
    {
        $materials = [
            ['title' => 'a', 'accessLevel' => 'board-only'],
            ['title' => 'b', 'accessLevel' => 'executive-only'],
            ['title' => 'c', 'accessLevel' => 'regulator'],
        ];
        $filtered  = $this->service->filterMaterialsByRole($materials, 'member');
        $this->assertCount(1, $filtered);
        $this->assertSame('a', $filtered[0]['title']);

    }//end testFilterMaterialsByRole()
}//end class
