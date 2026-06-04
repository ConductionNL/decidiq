<?php

/**
 * Unit tests for BoardAuditLogService hash-chain integrity.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardAuditLogService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SHA-256 hash chaining in BoardAuditLogService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
final class BoardAuditLogServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var BoardAuditLogService
     */
    private BoardAuditLogService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoardAuditLogService(
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()

    /**
     * The hash is a 64-char hex SHA-256 and is deterministic regardless of UID order.
     *
     * @return void
     */
    public function testComputeHashIsDeterministicAndOrderInsensitive(): void
    {
        $hashA = $this->service->computeHash('actor-1', 'vote', ['b', 'a'], '2026-01-01T00:00:00+00:00', 'prev');
        $hashB = $this->service->computeHash('actor-1', 'vote', ['a', 'b'], '2026-01-01T00:00:00+00:00', 'prev');

        $this->assertSame($hashA, $hashB);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hashA);

    }//end testComputeHashIsDeterministicAndOrderInsensitive()

    /**
     * Changing any input changes the hash (tamper-evidence).
     *
     * @return void
     */
    public function testDifferentInputsYieldDifferentHashes(): void
    {
        $base    = $this->service->computeHash('actor-1', 'vote', ['a'], '2026-01-01T00:00:00+00:00', 'prev');
        $diffAct = $this->service->computeHash('actor-2', 'vote', ['a'], '2026-01-01T00:00:00+00:00', 'prev');
        $diffPre = $this->service->computeHash('actor-1', 'vote', ['a'], '2026-01-01T00:00:00+00:00', 'other');

        $this->assertNotSame($base, $diffAct);
        $this->assertNotSame($base, $diffPre);

    }//end testDifferentInputsYieldDifferentHashes()
}//end class
