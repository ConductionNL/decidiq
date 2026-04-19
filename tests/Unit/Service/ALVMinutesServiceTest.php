<?php

/**
 * Unit tests for ALVMinutesService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ALVMinutesService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ALVMinutesService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
 */
class ALVMinutesServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ALVMinutesService
     */
    private ALVMinutesService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new ALVMinutesService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test generateALVDraft produces correct quorum statement for quorum met.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     *
     * @return void
     */
    public function testGenerateALVDraftProducesQuorumStatement(): void
    {
        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'title' => 'Test ALV',
            'meeting' => 'meeting-1',
        ]);

        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('getObject')->willReturn([
            'id' => 'meeting-1',
            'title' => 'Test ALV Meeting',
            'meetingType' => 'ALV',
            'scheduledDate' => date('c'),
            'location' => 'Test Location',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($minutesEntity, $meetingEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $result = $this->service->generateALVDraft('minutes-1');

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('recipientCount', $result);
        $this->assertStringContainsString('Quorum', $result['content']);
    }//end testGenerateALVDraftProducesQuorumStatement()

    /**
     * Test generateALVDraft returns validation error for non-ALV meeting.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     *
     * @return void
     */
    public function testGenerateALVDraftReturnsValidationErrorForNonALVMeeting(): void
    {
        $this->expectException(\RuntimeException::class);

        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'title' => 'Test Minutes',
            'meeting' => 'meeting-1',
        ]);

        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('getObject')->willReturn([
            'id' => 'meeting-1',
            'title' => 'Test Meeting',
            'meetingType' => 'Regular Council', // Not ALV
        ]);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($minutesEntity, $meetingEntity);

        $this->service->generateALVDraft('minutes-1');
    }//end testGenerateALVDraftReturnsValidationErrorForNonALVMeeting()

    /**
     * Test distribute returns 0 when no active participants.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     *
     * @return void
     */
    public function testDistributeReturnsZeroWhenNoActiveParticipants(): void
    {
        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'lifecycle' => 'approved',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($minutesEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $result = $this->service->distribute('minutes-1');

        $this->assertEquals($result, 0);
    }//end testDistributeReturnsZeroWhenNoActiveParticipants()

    /**
     * Test distribute throws when lifecycle is draft.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.5
     *
     * @return void
     */
    public function testDistributeThrowsWhenLifecycleIsDraft(): void
    {
        $this->expectException(\RuntimeException::class);

        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'lifecycle' => 'draft',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($minutesEntity);

        $this->service->distribute('minutes-1');
    }//end testDistributeThrowsWhenLifecycleIsDraft()
}//end class
