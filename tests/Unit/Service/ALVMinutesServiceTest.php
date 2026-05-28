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
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->service   = new ALVMinutesService($this->container, $this->logger);
    }

    /**
     * Build a mock ObjectEntity that returns $data from jsonSerialize().
     *
     * @param array<string,mixed> $data Object data
     *
     * @return object
     */
    private function makeEntity(array $data): object
    {
        $entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;
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
        $minutesData = [
            'id'        => 'minutes-1',
            'title'     => 'ALV 2025',
            'relations' => ['Meeting' => ['meeting-1']],
        ];
        $meetingData = [
            'id'             => 'meeting-1',
            'meetingType'    => 'alv',
            'title'          => 'Algemene Ledenvergadering',
            'scheduledDate'  => '2025-04-15',
            'location'       => 'Amsterdam',
            'quorumRequired' => 0,
            'relations'      => ['GovernanceBody' => ['body-1']],
        ];

        $minutesEntity = $this->makeEntity($minutesData);
        $meetingEntity = $this->makeEntity($meetingData);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjectService->method('setRegister')->willReturnSelf();
        $mockObjectService->method('setSchema')->willReturnSelf();

        $mockObjectService->expects($this->any())
            ->method('find')
            ->willReturnCallback(function (int|string $id) use ($minutesEntity, $meetingEntity): ?object {
                if ($id === 'minutes-1') {
                    return $minutesEntity;
                }
                if ($id === 'meeting-1') {
                    return $meetingEntity;
                }
                return null;
            });

        $mockObjectService->expects($this->any())
            ->method('findAll')
            ->willReturn([]);

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
        $minutesData = [
            'id'        => 'minutes-1',
            'relations' => ['Meeting' => ['meeting-1']],
        ];
        $meetingData = [
            'id'          => 'meeting-1',
            'meetingType' => 'council',
        ];

        $minutesEntity = $this->makeEntity($minutesData);
        $meetingEntity = $this->makeEntity($meetingData);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjectService->method('setRegister')->willReturnSelf();
        $mockObjectService->method('setSchema')->willReturnSelf();

        $mockObjectService->expects($this->any())
            ->method('find')
            ->willReturnCallback(function (int|string $id) use ($minutesEntity, $meetingEntity): ?object {
                if ($id === 'minutes-1') {
                    return $minutesEntity;
                }
                if ($id === 'meeting-1') {
                    return $meetingEntity;
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
        $minutesData = [
            'id'        => 'minutes-1',
            'lifecycle' => 'approved',
            'relations' => ['Meeting' => ['meeting-1']],
        ];
        $meetingData = [
            'id'        => 'meeting-1',
            'relations' => ['GovernanceBody' => ['body-1']],
        ];

        $minutesEntity = $this->makeEntity($minutesData);
        $meetingEntity = $this->makeEntity($meetingData);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjectService->method('setRegister')->willReturnSelf();
        $mockObjectService->method('setSchema')->willReturnSelf();

        $mockObjectService->expects($this->any())
            ->method('find')
            ->willReturnCallback(function (int|string $id) use ($minutesEntity, $meetingEntity): ?object {
                if ($id === 'minutes-1') {
                    return $minutesEntity;
                }
                if ($id === 'meeting-1') {
                    return $meetingEntity;
                }
                return null;
            });

        $mockObjectService->expects($this->any())
            ->method('findAll')
            ->willReturn([]);

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
        $minutesData   = [
            'id'        => 'minutes-1',
            'lifecycle' => 'draft',
        ];
        $minutesEntity = $this->makeEntity($minutesData);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjectService->method('setRegister')->willReturnSelf();
        $mockObjectService->method('setSchema')->willReturnSelf();

        $mockObjectService->expects($this->any())
            ->method('find')
            ->willReturn($minutesEntity);

        $this->container->expects($this->any())
            ->method('get')
            ->willReturn($mockObjectService);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);

        $this->service->distribute('minutes-1');
    }
}
