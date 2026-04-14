<?php

/**
 * Unit tests for MinutesGenerationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesGenerationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MinutesGenerationService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class MinutesGenerationServiceTest extends TestCase
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
     * The service under test.
     *
     * @var MinutesGenerationService
     */
    private MinutesGenerationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject', 'getObjects', 'saveObject'])
            ->getMock();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new MinutesGenerationService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Happy path: generates a Dutch template with agenda items, motions, and decisions.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftHappyPathProducesStructuredDutchTemplate(): void
    {
        $minutesData = [
            'id'        => 'minutes-001',
            'title'     => 'Notulen Testgemeente 1 april 2025',
            'lifecycle' => 'draft',
            'relations' => [
                ['schema' => 'meeting', 'objectId' => 'meeting-001'],
            ],
        ];

        $meetingData = [
            'id'            => 'meeting-001',
            'title'         => 'Raadsvergadering 1 april 2025',
            'scheduledDate' => '2025-04-01T19:00:00Z',
            'location'      => 'Raadzaal',
            'meetingType'   => 'regular',
            'meetingMode'   => 'in-person',
            'relations'     => [
                ['schema' => 'agenda-item', 'objectId' => 'ai-001'],
            ],
        ];

        $agendaItem = [
            'id'          => 'ai-001',
            'title'       => 'Woningbouwplan Oost',
            'orderNumber' => 1,
            'itemType'    => 'decision',
            'relations'   => [],
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(static function (string $register, string $schema, string $id) use ($minutesData, $meetingData, $agendaItem): ?array {
                if ($schema === 'minutes') return $minutesData;
                if ($schema === 'meeting') return $meetingData;
                if ($schema === 'agenda-item') return $agendaItem;
                return null;
            });

        $result = $this->service->generateDraft('minutes-001');

        self::assertStringContainsString('Notulen Testgemeente 1 april 2025', $result);
        self::assertStringContainsString('Raadsvergadering 1 april 2025', $result);
        self::assertStringContainsString('Woningbouwplan Oost', $result);
        self::assertStringContainsString('Concept opgesteld op', $result);
        self::assertStringContainsString('automatisch gegenereerd concept', $result);

    }//end testGenerateDraftHappyPathProducesStructuredDutchTemplate()

    /**
     * Meeting with no agenda items returns a minimal valid template.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate(): void
    {
        $minutesData = [
            'id'        => 'minutes-002',
            'title'     => 'Concept notulen zonder agenda',
            'lifecycle' => 'draft',
            'relations' => [
                ['schema' => 'meeting', 'objectId' => 'meeting-002'],
            ],
        ];

        $meetingData = [
            'id'        => 'meeting-002',
            'title'     => 'Lege vergadering',
            'relations' => [],
        ];

        $this->objectService->method('getObject')
            ->willReturnCallback(static function (string $register, string $schema, string $id) use ($minutesData, $meetingData): ?array {
                if ($schema === 'minutes') return $minutesData;
                if ($schema === 'meeting') return $meetingData;
                return null;
            });

        $result = $this->service->generateDraft('minutes-002');

        self::assertStringContainsString('Concept notulen zonder agenda', $result);
        self::assertStringContainsString('Opening', $result);
        self::assertStringContainsString('Sluiting', $result);
        // No agenda section when no items.
        self::assertStringNotContainsString('## Agenda', $result);

    }//end testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate()

    /**
     * Missing linked meeting throws a descriptive InvalidArgumentException.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftMissingMinutesThrowsInvalidArgumentException(): void
    {
        $this->objectService->method('getObject')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Minutes object 'nonexistent-id' not found.");

        $this->service->generateDraft('nonexistent-id');

    }//end testGenerateDraftMissingMinutesThrowsInvalidArgumentException()

    /**
     * OpenRegister unavailable throws RuntimeException.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
     *
     * @return void
     */
    public function testGenerateDraftThrowsRuntimeExceptionWhenOpenRegisterUnavailable(): void
    {
        $containerNoOR = $this->createMock(ContainerInterface::class);
        $containerNoOR->method('get')
            ->willThrowException(new \Exception('Service not found'));

        $service = new MinutesGenerationService(
            container: $containerNoOR,
            logger: $this->logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister ObjectService is not available');

        $service->generateDraft('any-id');

    }//end testGenerateDraftThrowsRuntimeExceptionWhenOpenRegisterUnavailable()

}//end class
