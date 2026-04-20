<?php

/**
 * Test Suite for LiveDecisionService
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\LiveDecisionService;
use OCA\Decidesk\Exception\MissingObjectException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LiveDecisionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 */
class LiveDecisionServiceTest extends TestCase
{
    private LiveDecisionService $service;
    private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new LiveDecisionService($this->container, $this->logger);
    }

    /**
     * Test that recordDecision creates Decision and links to Meeting.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     */
    public function testRecordDecisionCreatesDecisionAndLinksToMeeting(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturn([
                'id' => 'meeting-1',
                'title' => 'Council Meeting',
                'lifecycle' => 'opened',
            ]);

        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([]); // No existing minutes

        $mockObjectService->expects($this->once())
            ->method('saveObject')
            ->willReturn([
                'id' => 'decision-1',
                '@self' => ['slug' => 'council-decision-1'],
            ]);

        $this->container->expects($this->any())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $decisionData = [
            'title' => 'Budget Approved',
            'text' => 'The budget was approved unanimously',
            'outcome' => 'adopted',
        ];

        $result = $this->service->recordDecision('meeting-1', $decisionData, 'user-1');

        $this->assertEquals('council-decision-1', $result);
    }

    /**
     * Test that recordDecision throws 409 when Meeting not opened.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     */
    public function testRecordDecisionThrows409ForNonOpenedMeeting(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $mockObjectService->expects($this->once())
            ->method('findObject')
            ->willReturn([
                'id' => 'meeting-1',
                'title' => 'Council Meeting',
                'lifecycle' => 'scheduled', // Not opened
            ]);

        $this->container->expects($this->any())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $decisionData = [
            'title' => 'Budget Approved',
            'text' => 'The budget was approved unanimously',
            'outcome' => 'adopted',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(409);

        $this->service->recordDecision('meeting-1', $decisionData, 'user-1');
    }

    /**
     * Test that ensureDraftMinutes creates draft Minutes when none exists.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     */
    public function testEnsureDraftMinutesCreatesDraftWhenNoneExists(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $mockObjectService->expects($this->any())
            ->method('findObject')
            ->willReturn([
                'id' => 'meeting-1',
                'title' => 'Council Meeting',
            ]);

        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([]); // No existing minutes

        $mockObjectService->expects($this->once())
            ->method('saveObject')
            ->willReturn([
                'id' => 'minutes-1',
                '@self' => ['slug' => 'concept-notulen-1'],
            ]);

        $this->container->expects($this->any())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->ensureDraftMinutes('meeting-1');

        $this->assertEquals('concept-notulen-1', $result);
    }

    /**
     * Test that ensureDraftMinutes returns existing Minutes slug when one exists.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
     */
    public function testEnsureDraftMinutesReturnsExistingMinutesSlug(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([
                [
                    'id' => 'minutes-1',
                    '@self' => ['slug' => 'existing-notulen'],
                    'relations' => [
                        'Meeting' => ['meeting-1'],
                    ],
                ],
            ]);

        $this->container->expects($this->any())
            ->method('get')
            ->with('OpenRegisterObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->ensureDraftMinutes('meeting-1');

        $this->assertEquals('existing-notulen', $result);
    }
}
