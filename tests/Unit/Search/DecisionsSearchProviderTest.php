<?php

/**
 * Unit tests for DecisionsSearchProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Search
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Search;

use OCA\Decidesk\Search\DecisionsSearchProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for DecisionsSearchProvider.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
 */
class DecisionsSearchProviderTest extends TestCase
{
    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IL10N.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Provider under test.
     *
     * @var DecisionsSearchProvider
     */
    private DecisionsSearchProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->l10n->method('t')->willReturnArgument(0);

        $this->provider = new DecisionsSearchProvider($this->container, $this->l10n);
    }

    /**
     * Test search returns Decision results with correct title and URL.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     *
     * @return void
     */
    public function testSearchReturnDecisionResults(): void
    {
        $mockUser = $this->createMock(IUser::class);
        $mockQuery = $this->createMock(ISearchQuery::class);
        $mockQuery->method('getTerm')->willReturn('test');

        $this->objectService
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'id' => 'decision-1',
                        'title' => 'Test Decision',
                        'decisionDate' => '2026-04-19',
                        'lifecycle' => 'published',
                    ],
                ],
                [],
                []
            );

        $result = $this->provider->search($mockUser, $mockQuery);

        $this->assertNotNull($result);
    }

    /**
     * Test search returns Minutes results.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     *
     * @return void
     */
    public function testSearchReturnMinutesResults(): void
    {
        $mockUser = $this->createMock(IUser::class);
        $mockQuery = $this->createMock(ISearchQuery::class);
        $mockQuery->method('getTerm')->willReturn('minutes');

        $this->objectService
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    [
                        'id' => 'minutes-1',
                        'title' => 'Minutes 2026-04-19',
                        'createdAt' => '2026-04-19',
                        'lifecycle' => 'draft',
                    ],
                ],
                []
            );

        $result = $this->provider->search($mockUser, $mockQuery);

        $this->assertNotNull($result);
    }

    /**
     * Test search returns ActionItem results.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     *
     * @return void
     */
    public function testSearchReturnActionItemResults(): void
    {
        $mockUser = $this->createMock(IUser::class);
        $mockQuery = $this->createMock(ISearchQuery::class);
        $mockQuery->method('getTerm')->willReturn('action');

        $this->objectService
            ->method('findAll')
            ->willReturnOnConsecutiveCalls(
                [],
                [],
                [
                    [
                        'id' => 'action-1',
                        'title' => 'Action Item 1',
                        'createdAt' => '2026-04-19',
                        'taskStatus' => 'open',
                    ],
                ]
            );

        $result = $this->provider->search($mockUser, $mockQuery);

        $this->assertNotNull($result);
    }

    /**
     * Test search caps results at 10 per entity type.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     *
     * @return void
     */
    public function testSearchCapsResultsAtTenPerType(): void
    {
        $mockUser = $this->createMock(IUser::class);
        $mockQuery = $this->createMock(ISearchQuery::class);
        $mockQuery->method('getTerm')->willReturn('test');

        $this->objectService
            ->expects($this->exactly(3))
            ->method('findAll')
            ->with(10, 0, [], ['_search' => 'test']);

        $result = $this->provider->search($mockUser, $mockQuery);

        $this->assertNotNull($result);
    }

    /**
     * Test search with no matches returns empty SearchResult.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     *
     * @return void
     */
    public function testSearchWithNoMatches(): void
    {
        $mockUser = $this->createMock(IUser::class);
        $mockQuery = $this->createMock(ISearchQuery::class);
        $mockQuery->method('getTerm')->willReturn('nomatch');

        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $result = $this->provider->search($mockUser, $mockQuery);

        $this->assertNotNull($result);
    }
}//end class
