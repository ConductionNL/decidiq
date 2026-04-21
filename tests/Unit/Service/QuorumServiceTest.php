<?php

/**
 * Unit tests for QuorumService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\QuorumService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for QuorumService.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.1
 */
class QuorumServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var QuorumService
     */
    private QuorumService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ObjectService from OpenRegister.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(originalClassName: ObjectService::class);
        $this->container     = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new QuorumService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test quorum calculation with fixed count requirement.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.2
     *
     * @return void
     */
    public function testCalculateQuorumWithFixedCount(): void
    {
        $meetingId = '550e8400-e29b-41d4-a716-446655440000';
        $quorumRequired = 5;

        $this->objectService->method('find')
            ->willReturnCallback(function($args) use ($meetingId) {
                if ($args === $meetingId) {
                    $mock = $this->createMock(originalClassName: 'OCA\OpenRegister\Db\ObjectEntity');
                    $mock->method('jsonSerialize')
                        ->willReturn([
                            'id' => $meetingId,
                            'title' => 'Test Meeting',
                            'quorumRequired' => 5,
                            'governanceBody' => 'body-id-123',
                        ]);
                    return $mock;
                }
                return null;
            });

        $result = $this->service->calculateQuorum($meetingId);

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['quorumRequired']);
    }//end testCalculateQuorumWithFixedCount()

    /**
     * Test quorum validation returns true when met.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     *
     * @return void
     */
    public function testValidateQuorumReturnsTrue(): void
    {
        $meetingId = '550e8400-e29b-41d4-a716-446655440000';

        $this->objectService->method('find')
            ->willReturnCallback(function($args) {
                if ($args === '550e8400-e29b-41d4-a716-446655440000') {
                    $mock = $this->createMock(originalClassName: 'OCA\OpenRegister\Db\ObjectEntity');
                    $mock->method('jsonSerialize')
                        ->willReturn([
                            'quorumRequired' => 3,
                            'governanceBody' => 'body-id',
                        ]);
                    return $mock;
                }
                return null;
            });

        $result = $this->service->validateQuorum($meetingId);

        $this->assertTrue($result);
    }//end testValidateQuorumReturnsTrue()

    /**
     * Test calculation handles missing meeting gracefully.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.2
     *
     * @return void
     */
    public function testCalculateQuorumWithMissingMeeting(): void
    {
        $meetingId = 'nonexistent-id';

        $this->objectService->method('find')
            ->willReturn(null);

        $result = $this->service->calculateQuorum($meetingId);

        $this->assertFalse($result['met']);
        $this->assertEquals(0, $result['presentCount']);
    }//end testCalculateQuorumWithMissingMeeting()

}//end class
