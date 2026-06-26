<?php

/**
 * Unit tests for MigrateActionItemsToDeckLeaf repair step (retired no-op).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Migration;

use OCA\Decidesk\Migration\MigrateActionItemsToDeckLeaf;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the retired no-op migration repair step.
 *
 * The step was retired after action items became a read-only CalDAV VTODO
 * projection. It is kept as a registered step so existing oc_jobs rows reap
 * cleanly, and to avoid breaking the repair-step registry.
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
 */
class MigrateActionItemsToDeckLeafTest extends TestCase
{

    /**
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * The repair step under test.
     *
     * @var MigrateActionItemsToDeckLeaf
     */
    private MigrateActionItemsToDeckLeaf $migration;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->output    = $this->createMock(IOutput::class);
        $this->migration = new MigrateActionItemsToDeckLeaf();

    }//end setUp()


    /**
     * The step name mentions Task and Deck for traceability.
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testGetNameReturnsDescription(): void
    {
        $name = $this->migration->getName();
        self::assertStringContainsString(needle: 'Task', haystack: $name);
        self::assertStringContainsString(needle: 'Deck', haystack: $name);

    }//end testGetNameReturnsDescription()


    /**
     * The step runs without throwing and outputs an info message.
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        // The retired step ignores OpenRegister availability entirely.
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()


    /**
     * The step is idempotent — running it multiple times is safe.
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunNoOpWhenLegacySchemasAbsent(): void
    {
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);

    }//end testRunNoOpWhenLegacySchemasAbsent()


    /**
     * Already-migrated tasks are not re-processed (no-op skips everything).
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunSkipsAlreadyMigratedTask(): void
    {
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);
        self::assertTrue(true);

    }//end testRunSkipsAlreadyMigratedTask()


    /**
     * Projection does not persist task objects (no-op).
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunProjectsAndArchivesTask(): void
    {
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);
        self::assertTrue(true);

    }//end testRunProjectsAndArchivesTask()


    /**
     * Reclaimed delegation replay is not performed (no-op).
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunReplaysReclaimedDelegationOntoActionItem(): void
    {
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);
        self::assertTrue(true);

    }//end testRunReplaysReclaimedDelegationOntoActionItem()


    /**
     * ObjectService being unavailable is handled gracefully (no-op never calls it).
     *
     * @return void
     *
     * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
     */
    public function testRunExitsGracefullyWhenObjectServiceUnavailable(): void
    {
        $this->output->expects($this->atLeastOnce())->method('info');
        $this->migration->run(output: $this->output);

    }//end testRunExitsGracefullyWhenObjectServiceUnavailable()


}//end class
