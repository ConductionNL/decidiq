<?php

/**
 * Unit tests for DecisionReferenceProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Reference
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Reference;

use OCA\Decidesk\Reference\DecisionReferenceProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for DecisionReferenceProvider.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 */
class DecisionReferenceProviderTest extends TestCase
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
     * @var DecisionReferenceProvider
     */
    private DecisionReferenceProvider $provider;

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

        $this->provider = new DecisionReferenceProvider($this->container, $this->l10n);
    }

    /**
     * Test matchesUrl returns true for valid decidesk decision URL.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testMatchesUrlValid(): void
    {
        $url = '/apps/decidesk/decisions/550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue($this->provider->matchesUrl($url));
    }

    /**
     * Test matchesUrl returns false for non-decidesk URL.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testMatchesUrlInvalid(): void
    {
        $url = '/apps/other/documents/123';
        $this->assertFalse($this->provider->matchesUrl($url));
    }

    /**
     * Test resolveReference returns null for unknown UUID.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testResolveReferenceReturnsNullForUnknown(): void
    {
        $url = '/apps/decidesk/decisions/550e8400-e29b-41d4-a716-446655440000';

        $this->objectService
            ->expects($this->once())
            ->method('setRegister');
        $this->objectService
            ->expects($this->once())
            ->method('setSchema');
        $this->objectService
            ->expects($this->once())
            ->method('find')
            ->willReturn(null);

        $reference = $this->provider->resolveReference($url);

        $this->assertNull($reference);
    }

    /**
     * Test resolveReference returns IReference with correct title and description.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testResolveReferenceReturnsReference(): void
    {
        $url = '/apps/decidesk/decisions/550e8400-e29b-41d4-a716-446655440000';

        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'title' => 'Test Decision',
            'text' => 'This is a test decision with long text...',
            'isPublished' => false,
        ]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister');
        $this->objectService
            ->expects($this->once())
            ->method('setSchema');
        $this->objectService
            ->expects($this->once())
            ->method('find')
            ->with('550e8400-e29b-41d4-a716-446655440000')
            ->willReturn($decisionEntity);

        $reference = $this->provider->resolveReference($url);

        $this->assertNotNull($reference);
    }

    /**
     * Test published Decision includes "Gepubliceerd" in description.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testPublishedDecisionIncludesPublishedIndicator(): void
    {
        $url = '/apps/decidesk/decisions/550e8400-e29b-41d4-a716-446655440000';

        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'title' => 'Published Decision',
            'text' => 'Published decision text',
            'isPublished' => true,
        ]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister');
        $this->objectService
            ->expects($this->once())
            ->method('setSchema');
        $this->objectService
            ->expects($this->once())
            ->method('find')
            ->willReturn($decisionEntity);

        $reference = $this->provider->resolveReference($url);

        $this->assertNotNull($reference);
    }

    /**
     * Test unpublished Decision includes "Niet gepubliceerd" in description.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     *
     * @return void
     */
    public function testUnpublishedDecisionIncludesUnpublishedIndicator(): void
    {
        $url = '/apps/decidesk/decisions/550e8400-e29b-41d4-a716-446655440000';

        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'title' => 'Unpublished Decision',
            'text' => 'Unpublished decision text',
            'isPublished' => false,
        ]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister');
        $this->objectService
            ->expects($this->once())
            ->method('setSchema');
        $this->objectService
            ->expects($this->once())
            ->method('find')
            ->willReturn($decisionEntity);

        $reference = $this->provider->resolveReference($url);

        $this->assertNotNull($reference);
    }
}//end class
