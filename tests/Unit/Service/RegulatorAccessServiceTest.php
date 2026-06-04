<?php

/**
 * Unit tests for RegulatorAccessService token signing and scope filtering.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\RegulatorAccessService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for signed regulator access tokens (REQ-009).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
final class RegulatorAccessServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var RegulatorAccessService
     */
    private RegulatorAccessService $service;

    /**
     * Set up fixtures with a fixed signing secret.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn(str_repeat('ab', 32));

        $this->service = new RegulatorAccessService(
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class),
            $appConfig,
            $this->createMock(BoardAuditLogService::class)
        );

    }//end setUp()

    /**
     * Invalid scope is rejected at grant time.
     *
     * @return void
     */
    public function testGrantRejectsInvalidScope(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->grantAccess('a@example.org', 'not-a-scope', 7, 'actor');

    }//end testGrantRejectsInvalidScope()

    /**
     * A freshly granted token validates and carries its scope/recipient.
     *
     * @return void
     */
    public function testGrantedTokenValidates(): void
    {
        $grant      = $this->service->grantAccess('auditor@example.org', 'audit-committee-only', 7, 'actor');
        $validation = $this->service->validateToken($grant['token']);
        $this->assertTrue($validation['valid']);
        $this->assertSame('audit-committee-only', $validation['scope']);
        $this->assertSame('auditor@example.org', $validation['recipient']);

    }//end testGrantedTokenValidates()

    /**
     * A tampered token fails signature verification.
     *
     * @return void
     */
    public function testTamperedTokenIsRejected(): void
    {
        $grant    = $this->service->grantAccess('auditor@example.org', 'all-records', 7, 'actor');
        $tampered = $grant['token'].'x';
        $this->assertFalse($this->service->validateToken($tampered)['valid']);

    }//end testTamperedTokenIsRejected()

    /**
     * Scope filtering restricts visible records appropriately.
     *
     * @return void
     */
    public function testFilterByScope(): void
    {
        $data = [
            ['_type' => 'resolution', 'accessLevel' => 'board-only'],
            ['_type' => 'material', 'accessLevel' => 'audit-committee'],
            ['_type' => 'material', 'accessLevel' => 'board-only'],
        ];

        $this->assertCount(3, $this->service->filterByScope('all-records', $data));
        $this->assertCount(1, $this->service->filterByScope('all-resolutions', $data));
        $this->assertCount(1, $this->service->filterByScope('audit-committee-only', $data));

    }//end testFilterByScope()
}//end class
