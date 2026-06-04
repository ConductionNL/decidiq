<?php

/**
 * Unit tests for EidasSignatureService verification logic.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\EidasSignatureService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for eIDAS signature verification (REQ-004).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
final class EidasSignatureServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var EidasSignatureService
     */
    private EidasSignatureService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EidasSignatureService(
            $this->createMock(ContainerInterface::class),
            $this->createMock(IAppManager::class),
            $this->createMock(BoardAuditLogService::class)
        );

    }//end setUp()

    /**
     * A non-empty signature verifies and yields a certificate thumbprint.
     *
     * @return void
     */
    public function testValidSignatureVerifies(): void
    {
        $result = $this->service->verifySignature('req-1', 'c2lnbmF0dXJl');
        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['certificateThumbprint']);

    }//end testValidSignatureVerifies()

    /**
     * An empty signature fails verification with no thumbprint.
     *
     * @return void
     */
    public function testEmptySignatureFails(): void
    {
        $result = $this->service->verifySignature('req-1', '');
        $this->assertFalse($result['valid']);
        $this->assertNull($result['certificateThumbprint']);

    }//end testEmptySignatureFails()
}//end class
