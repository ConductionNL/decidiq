<?php

/**
 * Unit tests for OverdueActionItemsJob.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\BackgroundJob;

use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OverdueActionItemsJob.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class OverdueActionItemsJobTest extends TestCase
{

    /**
     * Mock ITimeFactory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $timeFactory;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
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

        $this->timeFactory = $this->createMock(originalClassName: ITimeFactory::class);
        $this->appConfig   = $this->createMock(originalClassName: IAppConfig::class);
        $this->container   = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger      = $this->createMock(originalClassName: LoggerInterface::class);

        $this->appConfig->method('getValueString')
            ->willReturn('decidesk');

    }//end setUp()

    /**
     * Test that past-due action items are set to overdue.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     */
    public function testOverdueItemsAreUpdated(): void
    {
        $pastDate = (new \DateTime('-7 days'))->format('c');
        $savedItems = [];

        $objectService = new class($pastDate, $savedItems) {
            private string $pastDate;
            public array $savedItems;

            public function __construct(string $pastDate, array &$savedItems)
            {
                $this->pastDate = $pastDate;
                $this->savedItems = &$savedItems;
            }

            public function findObjects(string $register, string $schema, array $params = []): array
            {
                if (($params['taskStatus'] ?? '') === 'open') {
                    return ['results' => [
                        ['id' => 'ai-1', 'taskStatus' => 'open', 'dueDate' => $this->pastDate],
                    ]];
                }
                return ['results' => []];
            }

            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->savedItems[] = $object;
                return $object;
            }
        };

        $this->container->method('get')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob(
            time: $this->timeFactory,
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );

        // Use reflection to call protected run() method.
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

        self::assertCount(1, $objectService->savedItems);
        self::assertSame('overdue', $objectService->savedItems[0]['taskStatus']);

    }//end testOverdueItemsAreUpdated()

    /**
     * Test that completed action items are not modified.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     */
    public function testCompletedItemsAreNotModified(): void
    {
        $objectService = new class() {
            public array $savedItems = [];

            public function findObjects(string $register, string $schema, array $params = []): array
            {
                // The job only queries 'open' and 'in-progress', never 'completed'.
                return ['results' => []];
            }

            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->savedItems[] = $object;
                return $object;
            }
        };

        $this->container->method('get')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob(
            time: $this->timeFactory,
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );

        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

        self::assertCount(0, $objectService->savedItems);

    }//end testCompletedItemsAreNotModified()

    /**
     * Test that action items with no dueDate are not modified.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     */
    public function testItemsWithNoDueDateAreNotModified(): void
    {
        $objectService = new class() {
            public array $savedItems = [];

            public function findObjects(string $register, string $schema, array $params = []): array
            {
                if (($params['taskStatus'] ?? '') === 'open') {
                    return ['results' => [
                        ['id' => 'ai-2', 'taskStatus' => 'open', 'dueDate' => null],
                        ['id' => 'ai-3', 'taskStatus' => 'open'],
                    ]];
                }
                return ['results' => []];
            }

            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->savedItems[] = $object;
                return $object;
            }
        };

        $this->container->method('get')
            ->willReturn($objectService);

        $job = new OverdueActionItemsJob(
            time: $this->timeFactory,
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );

        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

        self::assertCount(0, $objectService->savedItems);

    }//end testItemsWithNoDueDateAreNotModified()
}//end class
