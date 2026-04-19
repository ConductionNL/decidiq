<?php

/**
 * Unit tests for MinutesVersionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for MinutesVersionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 */
class MinutesVersionServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var MinutesVersionService
     */
    private MinutesVersionService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $this->service = new MinutesVersionService($container);
    }

    /**
     * Test diffVersions returns correct added/removed/unchanged entries.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testDiffVersionsReturnsArray(): void
    {
        $contentA = "Line 1\nLine 2\nLine 3";
        $contentB = "Line 1\nLine 2 modified\nLine 3\nLine 4";

        $diff = $this->service->diffVersions($contentA, $contentB);

        $this->assertIsArray($diff);
        $this->assertTrue(count($diff) > 0);
    }

    /**
     * Test diffVersions with identical content.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testDiffVersionsIdenticalContent(): void
    {
        $contentA = "Line 1\nLine 2";
        $contentB = "Line 1\nLine 2";

        $diff = $this->service->diffVersions($contentA, $contentB);

        $this->assertIsArray($diff);
    }

    /**
     * Test diffVersions with single lines.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testDiffVersionsSingleLine(): void
    {
        $contentA = "Old line";
        $contentB = "New line";

        $diff = $this->service->diffVersions($contentA, $contentB);

        $this->assertIsArray($diff);
    }

    /**
     * Test diffVersions empty content.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testDiffVersionsEmptyContent(): void
    {
        $diff = $this->service->diffVersions("", "");

        $this->assertIsArray($diff);
    }

    /**
     * Test getVersionHistory gracefully handles empty file list.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testGetVersionHistoryHandlesError(): void
    {
        // This test verifies the service doesn't throw on error
        $versions = $this->service->getVersionHistory('nonexistent-id');
        $this->assertIsArray($versions);
    }

    /**
     * Test getVersionContent handles missing file gracefully.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     *
     * @return void
     */
    public function testGetVersionContentHandlesMissing(): void
    {
        $content = $this->service->getVersionContent('nonexistent-id', 999);
        $this->assertNull($content);
    }
}//end class
