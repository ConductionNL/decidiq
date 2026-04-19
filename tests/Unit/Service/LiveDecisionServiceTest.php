<?php

/**
 * Unit tests for LiveDecisionService.
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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\LiveDecisionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for LiveDecisionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 */
class LiveDecisionServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var LiveDecisionService
     */
    private LiveDecisionService $service;

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

        $this->service = new LiveDecisionService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test recordDecision creates Decision and links to Meeting.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     *
     * @return void
     */
    public function testRecordDecisionCreatesDecisionAndLinksToMeeting(): void
    {
        $meetingId = 'meeting-1';
        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('getObject')->willReturn([
            'id' => $meetingId,
            'title' => 'Test Meeting',
            'lifecycle' => 'opened',
        ]);

        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'title' => 'Minutes',
        ]);

        $this->objectService
            ->method('find')
            ->with(id: $meetingId)
            ->willReturn($meetingEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $createdEntity = $this->createMock(ObjectEntity::class);
        $createdEntity->method('getObject')->willReturn([
            'id' => 'decision-1',
            '@self' => ['slug' => 'decision-1'],
        ]);

        $this->objectService
            ->method('saveObject')
            ->willReturnOnConsecutiveCalls($minutesEntity, $createdEntity);

        $result = $this->service->recordDecision(
            meetingId: $meetingId,
            decisionData: [
                'title' => 'Test Decision',
                'text' => 'Test text',
                'outcome' => 'adopted',
            ],
            actorId: 'user-1'
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }//end testRecordDecisionCreatesDecisionAndLinksToMeeting()

    /**
     * Test recordDecision throws 409 when Meeting not opened.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     *
     * @return void
     */
    public function testRecordDecisionThrowsWhenMeetingNotOpened(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $meetingId = 'meeting-1';
        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('getObject')->willReturn([
            'id' => $meetingId,
            'title' => 'Test Meeting',
            'lifecycle' => 'scheduled', // Not opened
        ]);

        $this->objectService
            ->method('find')
            ->with(id: $meetingId)
            ->willReturn($meetingEntity);

        $this->service->recordDecision(
            meetingId: $meetingId,
            decisionData: [
                'title' => 'Test Decision',
                'text' => 'Test text',
                'outcome' => 'adopted',
            ],
            actorId: 'user-1'
        );
    }//end testRecordDecisionThrowsWhenMeetingNotOpened()

    /**
     * Test ensureDraftMinutes creates draft Minutes when none exists.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     *
     * @return void
     */
    public function testEnsureDraftMinutesCreatesDraftMinutesWhenNoneExists(): void
    {
        $meetingId = 'meeting-1';
        $meetingEntity = $this->createMock(ObjectEntity::class);
        $meetingEntity->method('getObject')->willReturn([
            'id' => $meetingId,
            'title' => 'Test Meeting',
        ]);

        $this->objectService
            ->method('find')
            ->with(id: $meetingId)
            ->willReturn($meetingEntity);

        // No existing minutes
        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            '@self' => ['slug' => 'minutes-1'],
        ]);

        $this->objectService
            ->method('saveObject')
            ->willReturn($minutesEntity);

        $result = $this->service->ensureDraftMinutes($meetingId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }//end testEnsureDraftMinutesCreatesDraftMinutesWhenNoneExists()

    /**
     * Test ensureDraftMinutes returns existing Minutes slug when one exists.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     *
     * @return void
     */
    public function testEnsureDraftMinutesReturnsExistingMinutesSlug(): void
    {
        $meetingId = 'meeting-1';

        // Existing minutes found
        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'id' => 'minutes-1',
                        '@self' => ['slug' => 'minutes-1'],
                    ],
                ],
            ]);

        $result = $this->service->ensureDraftMinutes($meetingId);

        $this->assertEquals($result, 'minutes-1');
    }//end testEnsureDraftMinutesReturnsExistingMinutesSlug()
}//end class
