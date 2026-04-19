<?php

/**
 * Unit tests for MinutesApprovalService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\Decidesk\Service\MinutesApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for MinutesApprovalService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
 */
class MinutesApprovalServiceTest extends TestCase
{
    /**
     * Mock ContainerInterface.
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
     * Mock DecisionNotificationService.
     *
     * @var DecisionNotificationService&MockObject
     */
    private DecisionNotificationService&MockObject $notificationService;

    /**
     * Service under test.
     *
     * @var MinutesApprovalService
     */
    private MinutesApprovalService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->notificationService = $this->createMock(DecisionNotificationService::class);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new MinutesApprovalService($this->container, $this->notificationService);
    }

    /**
     * Test addApproval throws exception for invalid role.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testAddApprovalThrowsOnInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role');
        $this->service->addApproval('minutes-1', 'user1', 'invalid');
    }

    /**
     * Test addApproval on non-review Minutes throws exception.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testAddApprovalThrowsOnNonReviewLifecycle(): void
    {
        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'lifecycle' => 'approved',
            'signedBy' => [],
        ]);

        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('minutes-1')
            ->willReturn($minutesEntity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('review state');
        $this->service->addApproval('minutes-1', 'user1', 'chair');
    }

    /**
     * Test advance throws exception on invalid transition.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testAdvanceThrowsOnInvalidTransition(): void
    {
        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'lifecycle' => 'draft',
            'signedBy' => [],
        ]);

        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('minutes-1')
            ->willReturn($minutesEntity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transition');
        $this->service->advance('minutes-1', 'user1', 'signed');
    }

    /**
     * Test getApprovalStatus returns empty when Minutes not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testGetApprovalStatusReturnsEmptyForNotFound(): void
    {
        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('nonexistent')
            ->willReturn(null);

        $status = $this->service->getApprovalStatus('nonexistent');

        $this->assertFalse($status['chairApproved']);
        $this->assertFalse($status['secretaryApproved']);
    }

    /**
     * Test getApprovalStatus returns approvals array.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testGetApprovalStatusReturnsArray(): void
    {
        $minutesEntity = $this->createMock(ObjectEntity::class);
        $minutesEntity->method('getObject')->willReturn([
            'id' => 'minutes-1',
            'lifecycle' => 'review',
            'signedBy' => [],
        ]);

        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('minutes-1')
            ->willReturn($minutesEntity);

        $status = $this->service->getApprovalStatus('minutes-1');

        $this->assertIsArray($status);
        $this->assertArrayHasKey('chairApproved', $status);
        $this->assertArrayHasKey('secretaryApproved', $status);
        $this->assertArrayHasKey('approvals', $status);
    }

    /**
     * Test addApproval throws for missing Minutes.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testAddApprovalThrowsForMissingMinutes(): void
    {
        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('nonexistent')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->service->addApproval('nonexistent', 'user1', 'chair');
    }

    /**
     * Test advance throws for missing Minutes.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     *
     * @return void
     */
    public function testAdvanceThrowsForMissingMinutes(): void
    {
        $this->objectService
            ->method('setRegister');
        $this->objectService
            ->method('setSchema');
        $this->objectService
            ->method('find')
            ->with('nonexistent')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->service->advance('nonexistent', 'user1', 'signed');
    }
}//end class
