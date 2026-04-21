<?php

/**
 * Unit tests for DecisionAutoRecordService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionAutoRecordService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionAutoRecordService auto-creation of Decision records.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
 */
class DecisionAutoRecordServiceTest extends TestCase
{

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
     * Mock ObjectService.
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Service under test.
     *
     * @var DecisionAutoRecordService
     */
    private DecisionAutoRecordService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new DecisionAutoRecordService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * createFromAdoptedMotion throws RuntimeException when Motion not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     *
     * @return void
     */
    public function testCreateFromAdoptedMotionThrowsWhenMotionNotFound(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->service->createFromAdoptedMotion(motionId: 'nonexistent-motion');

    }//end testCreateFromAdoptedMotionThrowsWhenMotionNotFound()

    /**
     * createFromAdoptedMotion returns existing Decision UUID when one already exists (idempotent).
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     *
     * @return void
     */
    public function testCreateFromAdoptedMotionIsIdempotentWhenDecisionExists(): void
    {
        $motionData   = ['title' => 'Test Motion', 'decisionText' => 'Adopt policy'];
        $motionEntity = $this->createEntityMock($motionData);

        $existingDecision = ['@self' => ['id' => 'existing-decision-uuid']];

        $this->objectService->method('find')->willReturn($motionEntity);
        $this->objectService->method('findAll')->willReturn([$existingDecision]);

        // saveObject must NOT be called when a Decision already exists.
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->service->createFromAdoptedMotion(motionId: 'motion-001');

        self::assertSame('existing-decision-uuid', $result);

    }//end testCreateFromAdoptedMotionIsIdempotentWhenDecisionExists()

    /**
     * createFromAdoptedMotion creates a new Decision when none exists yet.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     *
     * @return void
     */
    public function testCreateFromAdoptedMotionCreatesNewDecisionWhenNoneExists(): void
    {
        $motionData   = ['title' => 'Motie woningbouw', 'decisionText' => 'Aangenomen'];
        $motionEntity = $this->createEntityMock($motionData);

        $this->objectService->method('find')->willReturn($motionEntity);
        $this->objectService->method('findAll')->willReturn([]);

        $createdDecision = ['@self' => ['id' => 'new-decision-uuid']];
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($createdDecision);

        $result = $this->service->createFromAdoptedMotion(motionId: 'motion-002');

        self::assertSame('new-decision-uuid', $result);

    }//end testCreateFromAdoptedMotionCreatesNewDecisionWhenNoneExists()

    /**
     * Helper: create a mock entity with getObject() returning the given data.
     *
     * @param array<string,mixed> $data Object data
     *
     * @return object
     */
    private function createEntityMock(array $data): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject'])
            ->getMock();
        $mock->method('getObject')->willReturn($data);
        return $mock;

    }//end createEntityMock()

}//end class
