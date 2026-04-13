<?php

/**
 * Unit tests for MinutesGenerationService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-17
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MinutesGenerationService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-17
 */
class MinutesGenerationServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var MinutesGenerationService
     */
    private MinutesGenerationService $service;

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

        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->appConfig->method('getValueString')
            ->willReturn('decidesk');

        $this->service = new MinutesGenerationService(
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that generateDraft produces correct Dutch template with agenda items.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-17
     */
    public function testGenerateDraftHappyPath(): void
    {
        $objectService = $this->createMockObjectService(
            minutes: [
                'id'        => 'min-1',
                'title'     => 'Test Notulen',
                'lifecycle' => 'draft',
                'relations' => [
                    ['schema' => 'meeting', 'objectId' => 'meet-1'],
                ],
            ],
            meeting: [
                'id'            => 'meet-1',
                'title'         => 'Raadsvergadering 10 april 2025',
                'scheduledDate' => '2025-04-10T19:30:00Z',
                'location'      => 'Raadzaal',
            ],
            agendaItems: [
                [
                    'id'          => 'ai-1',
                    'title'       => 'Opening',
                    'orderNumber' => 1,
                    'itemType'    => 'informational',
                    'relations'   => [],
                ],
                [
                    'id'          => 'ai-2',
                    'title'       => 'Woningbouwplan',
                    'orderNumber' => 2,
                    'itemType'    => 'decision',
                    'relations'   => [],
                ],
            ]
        );

        $this->container->method('get')
            ->willReturn($objectService);

        $result = $this->service->generateDraft('min-1');

        self::assertStringContainsString('NOTULEN', $result);
        self::assertStringContainsString('Raadsvergadering 10 april 2025', $result);
        self::assertStringContainsString('Opening', $result);
        self::assertStringContainsString('Woningbouwplan', $result);
        self::assertStringContainsString('Ter informatie', $result);
        self::assertStringContainsString('Besluitstuk', $result);
        self::assertStringContainsString('Locatie: Raadzaal', $result);

    }//end testGenerateDraftHappyPath()

    /**
     * Test that generateDraft with no agenda items returns minimal template.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-17
     */
    public function testGenerateDraftNoAgendaItems(): void
    {
        $objectService = $this->createMockObjectService(
            minutes: [
                'id'        => 'min-2',
                'title'     => 'Test Notulen',
                'lifecycle' => 'draft',
                'relations' => [
                    ['schema' => 'meeting', 'objectId' => 'meet-2'],
                ],
            ],
            meeting: [
                'id'            => 'meet-2',
                'title'         => 'Lege vergadering',
                'scheduledDate' => '2025-04-15T10:00:00Z',
            ],
            agendaItems: []
        );

        $this->container->method('get')
            ->willReturn($objectService);

        $result = $this->service->generateDraft('min-2');

        self::assertStringContainsString('NOTULEN', $result);
        self::assertStringContainsString('Lege vergadering', $result);
        self::assertStringContainsString('Geen agendapunten gevonden', $result);

    }//end testGenerateDraftNoAgendaItems()

    /**
     * Test that generateDraft with missing linked meeting throws exception.
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-17
     */
    public function testGenerateDraftMissingMeetingThrowsException(): void
    {
        $objectService = $this->createMockObjectService(
            minutes: [
                'id'        => 'min-3',
                'title'     => 'Test Notulen',
                'lifecycle' => 'draft',
                'relations' => [],
            ],
            meeting: null,
            agendaItems: []
        );

        $this->container->method('get')
            ->willReturn($objectService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Geen vergadering gekoppeld');

        $this->service->generateDraft('min-3');

    }//end testGenerateDraftMissingMeetingThrowsException()

    /**
     * Create a mock ObjectService with prepared return values.
     *
     * @param array      $minutes     The minutes object to return
     * @param array|null $meeting     The meeting object to return
     * @param array      $agendaItems The agenda items to return
     *
     * @return object The mock ObjectService
     */
    private function createMockObjectService(array $minutes, ?array $meeting, array $agendaItems): object
    {
        $mock = new class($minutes, $meeting, $agendaItems) {
            public function __construct(
                private array $minutes,
                private ?array $meeting,
                private array $agendaItems,
            ) {
            }

            public function findObject(string $register, string $schema, string $id): ?array
            {
                if ($schema === 'minutes') {
                    return $this->minutes;
                }

                if ($schema === 'meeting') {
                    return $this->meeting;
                }

                return null;
            }

            public function findObjects(string $register, string $schema, array $params = []): array
            {
                if ($schema === 'agenda-item') {
                    return ['results' => $this->agendaItems];
                }

                return ['results' => []];
            }
        };

        return $mock;

    }//end createMockObjectService()
}//end class
