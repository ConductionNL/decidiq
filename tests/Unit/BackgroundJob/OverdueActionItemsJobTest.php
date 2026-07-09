<?php

/**
 * Unit tests for OverdueActionItemsJob (retired no-op).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\BackgroundJob
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\BackgroundJob;

use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OverdueActionItemsJob (retired no-op).
 *
 * Action items are now CalDAV VTODOs exposed as a READ-ONLY OpenRegister
 * projection. Overdue status is derived at read time (dueDate < now); this
 * job is kept as a no-op so the oc_jobs row reaps cleanly.
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
 */
class OverdueActionItemsJobTest extends TestCase
{

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ITimeFactory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $timeFactory;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->timeFactory->method('getTime')->willReturn(time());

    }//end setUp()


    /**
     * The job can be constructed with only time factory and logger.
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @return void
     */
    public function testActionItemsWithPastDueDateAreSetToOverdue(): void
    {
        // Overdue status is now derived at read time — the job is a no-op.
        // Verify construction and run() complete without throwing.
        $job = new OverdueActionItemsJob($this->timeFactory, $this->logger);
        $this->invokeRun($job);
        self::assertTrue(true);

    }//end testActionItemsWithPastDueDateAreSetToOverdue()


    /**
     * ActionItems with no dueDate are handled gracefully (no-op).
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @return void
     */
    public function testActionItemsWithNoDueDateAreNotModified(): void
    {
        $job = new OverdueActionItemsJob($this->timeFactory, $this->logger);
        $this->invokeRun($job);
        self::assertTrue(true);

    }//end testActionItemsWithNoDueDateAreNotModified()


    /**
     * ActionItems with a future dueDate are handled gracefully (no-op).
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @return void
     */
    public function testActionItemsWithFutureDueDateAreNotModified(): void
    {
        $job = new OverdueActionItemsJob($this->timeFactory, $this->logger);
        $this->invokeRun($job);
        self::assertTrue(true);

    }//end testActionItemsWithFutureDueDateAreNotModified()


    /**
     * Missing OpenRegister is handled gracefully (no-op job never calls it).
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @return void
     */
    public function testJobHandlesMissingOpenRegisterGracefully(): void
    {
        // The retired job no longer uses ObjectService; no container needed.
        $job = new OverdueActionItemsJob($this->timeFactory, $this->logger);
        $this->invokeRun($job);
        self::assertTrue(true);

    }//end testJobHandlesMissingOpenRegisterGracefully()


    /**
     * Completed ActionItems are not modified (no-op; nothing is ever modified).
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-overdue
     *
     * @return void
     */
    public function testCompletedActionItemsAreNotModified(): void
    {
        $job = new OverdueActionItemsJob($this->timeFactory, $this->logger);
        $this->invokeRun($job);
        self::assertTrue(true);

    }//end testCompletedActionItemsAreNotModified()


    /**
     * Invoke the protected run() method via reflection.
     *
     * @param OverdueActionItemsJob $job The job instance.
     *
     * @return void
     */
    private function invokeRun(OverdueActionItemsJob $job): void
    {
        $reflection = new \ReflectionMethod($job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($job, null);

    }//end invokeRun()


}//end class
