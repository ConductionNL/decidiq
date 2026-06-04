<?php

/**
 * Unit tests for WrittenResolutionService unanimity logic.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\EidasSignatureService;
use OCA\Decidesk\Service\WrittenResolutionService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for written-resolution unanimity (REQ-011).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */
final class WrittenResolutionServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var WrittenResolutionService
     */
    private WrittenResolutionService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WrittenResolutionService(
            $this->createMock(ContainerInterface::class),
            $this->createMock(EidasSignatureService::class),
            $this->createMock(BoardAuditLogService::class)
        );

    }//end setUp()

    /**
     * Unanimity requires every required signatory to have signed.
     *
     * @return void
     */
    public function testUnanimityRequiresAllSignatories(): void
    {
        $required = ['m1', 'm2', 'm3'];
        $this->assertTrue($this->service->isUnanimous($required, ['m1', 'm2', 'm3']));
        $this->assertFalse($this->service->isUnanimous($required, ['m1', 'm2']));

    }//end testUnanimityRequiresAllSignatories()

    /**
     * An empty required-signatory set is never unanimous.
     *
     * @return void
     */
    public function testEmptyRequiredIsNotUnanimous(): void
    {
        $this->assertFalse($this->service->isUnanimous([], []));

    }//end testEmptyRequiredIsNotUnanimous()
}//end class
