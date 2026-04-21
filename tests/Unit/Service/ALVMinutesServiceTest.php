<?php

/**
 * Test Suite for ALVMinutesService
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Exception\MissingObjectException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ALVMinutesService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
 */
class ALVMinutesServiceTest extends TestCase
{
    private ALVMinutesService $service;
    private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ALVMinutesService($this->container, $this->logger);
    }

    /**
     * Test that generateALVDraft produces correct quorum statement for quorum met.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     */
    public function testGenerateALVDraftProducesCorrectQuorumStatement(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturnCallback(function (string $register, string $schema, string $id) {
                if ($schema === 'Minutes') {
                    return [
                        'id' => 'minutes-1',
                        'title' => 'ALV 2025',
                        'relations' => [
                            'Meeting' => ['meeting-1'],
                        ],
                    ];
                } elseif ($schema === 'Meeting') {
                    return [
                        'id' => 'meeting-1',
                        'meetingType' => 'alv',
                        'title' => 'Algemene Ledenvergadering',
                        'scheduledDate' => '2025-04-15',
                        'location' => 'Amsterdam',
                        'quorumRequired' => 0, // 0 = quorum always met regardless of attendance.
                        'relations' => [
                            'GovernanceBody' => ['body-1'],
                        ],
                    ];
                }
                return null;
            });

        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([]); // No participants or agenda items.

        $this->container->expects($this->any())
            ->method('get')
            ->willReturn($mockObjectService);

        $result = $this->service->generateALVDraft('minutes-1');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['content']);
        $this->assertStringContainsString('Quorum bereikt', $result['content']);
    }

    /**
     * Test that generateALVDraft returns validation error for non-ALV meeting.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     */
    public function testGenerateALVDraftReturnsValidationErrorForNonALVMeeting(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturnCallback(function (string $register, string $schema, string $id) {
                if ($schema === 'Minutes') {
                    return [
                        'id' => 'minutes-1',
                        'relations' => [
                            'Meeting' => ['meeting-1'],
                        ],
                    ];
                } elseif ($schema === 'Meeting') {
                    return [
                        'id' => 'meeting-1',
                        'meetingType' => 'council', // Not ALV.
                    ];
                }
                return null;
            });

        $this->container->expects($this->any())
            ->method('get')
            ->willReturn($mockObjectService);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);

        $this->service->generateALVDraft('minutes-1');
    }

    /**
     * Test that distribute returns 0 when no active participants.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     */
    public function testDistributeReturns0ForNoParticipants(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturnCallback(function (string $register, string $schema, string $id) {
                if ($schema === 'Minutes') {
                    return [
                        'id' => 'minutes-1',
                        'lifecycle' => 'approved',
                        'relations' => [
                            'Meeting' => ['meeting-1'],
                        ],
                    ];
                } elseif ($schema === 'Meeting') {
                    return [
                        'id' => 'meeting-1',
                        'relations' => [
                            'GovernanceBody' => ['body-1'],
                        ],
                    ];
                }
                return null;
            });

        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([]); // No participants.

        $this->container->expects($this->any())
            ->method('get')
            ->willReturn($mockObjectService);

        $result = $this->service->distribute('minutes-1');

        $this->assertEquals(0, $result);
    }

    /**
     * Test that distribute throws 403 when lifecycle is draft.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     */
    public function testDistributeThrows403ForDraftLifecycle(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturn([
                'id' => 'minutes-1',
                'lifecycle' => 'draft', // Not approved.
            ]);

        $this->container->expects($this->any())
            ->method('get')
            ->willReturn($mockObjectService);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);

        $this->service->distribute('minutes-1');
    }
}
