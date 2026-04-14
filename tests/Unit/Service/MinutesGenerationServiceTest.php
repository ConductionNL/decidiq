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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
 */
class MinutesGenerationServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var MinutesGenerationService
     */
    private MinutesGenerationService $service;

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

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new MinutesGenerationService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that generateDraft produces a valid Dutch template with agenda items, motions, and decisions.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
     *
     * @return void
     */
    public function testGenerateDraftHappyPathProducesCorrectDutchTemplate(): void
    {
        $minutesId = 'test-minutes-uuid-1';

        $minutesEntity = $this->createObjectEntityMock([
            'id'        => $minutesId,
            'title'     => 'Notulen Raadsvergadering 10 april 2025',
            'lifecycle' => 'draft',
            'meeting'   => 'meeting-uuid-1',
        ]);

        $meetingEntity = $this->createObjectEntityMock([
            'id'            => 'meeting-uuid-1',
            'title'         => 'Raadsvergadering 10 april 2025',
            'scheduledDate' => '2025-04-10T19:30:00Z',
            'location'      => 'Stadhuis Amsterdam',
        ]);

        $agendaItemEntities = [
            $this->createObjectEntityMock(['id' => 'ai-1', 'title' => 'Opening en mededelingen', 'orderNumber' => 1]),
            $this->createObjectEntityMock(['id' => 'ai-2', 'title' => 'Programmabegroting 2026', 'orderNumber' => 2]),
        ];

        $motionEntities = [
            $this->createObjectEntityMock(['id' => 'm-1', 'title' => 'Motie Duurzaamheid', 'text' => 'Verzoek zonnepanelen.']),
        ];

        $decisionEntities = [
            $this->createObjectEntityMock(['id' => 'd-1', 'title' => 'Vaststelling Programmabegroting 2026', 'outcome' => 'adopted', 'text' => 'De begroting wordt vastgesteld.']),
        ];

        $objectService = $this->createObjectServiceMock(
            minutesEntity: $minutesEntity,
            meetingEntity: $meetingEntity,
            agendaItemEntities: $agendaItemEntities,
            motionEntities: $motionEntities,
            votingRoundEntities: [],
            decisionEntities: $decisionEntities
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->service->generateDraft($minutesId);

        self::assertStringContainsString('Raadsvergadering 10 april 2025', $result);
        self::assertStringContainsString('Opening en mededelingen', $result);
        self::assertStringContainsString('Programmabegroting 2026', $result);
        self::assertStringContainsString('Motie Duurzaamheid', $result);
        self::assertStringContainsString('Vaststelling Programmabegroting 2026', $result);
        self::assertStringContainsString('Aangenomen', $result);
        self::assertStringContainsString('concept', strtolower($result));

    }//end testGenerateDraftHappyPathProducesCorrectDutchTemplate()

    /**
     * Test that generateDraft with no agenda items returns a minimal valid template.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
     *
     * @return void
     */
    public function testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate(): void
    {
        $minutesId = 'test-minutes-uuid-2';

        $minutesEntity = $this->createObjectEntityMock([
            'id'        => $minutesId,
            'title'     => 'Notulen Vergadering Zonder Agenda',
            'lifecycle' => 'draft',
            'meeting'   => 'meeting-uuid-2',
        ]);

        $meetingEntity = $this->createObjectEntityMock([
            'id'    => 'meeting-uuid-2',
            'title' => 'Vergadering Zonder Agendapunten',
        ]);

        $objectService = $this->createObjectServiceMock(
            minutesEntity: $minutesEntity,
            meetingEntity: $meetingEntity,
            agendaItemEntities: [],
            motionEntities: [],
            votingRoundEntities: [],
            decisionEntities: []
        );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->service->generateDraft($minutesId);

        self::assertStringContainsString('Vergadering Zonder Agendapunten', $result);
        self::assertStringContainsString('Opening', $result);
        self::assertStringContainsString('Sluiting', $result);

    }//end testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate()

    /**
     * Test that generateDraft throws a descriptive RuntimeException when no linked Meeting is found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
     *
     * @return void
     */
    public function testGenerateDraftThrowsExceptionWhenLinkedMeetingIsMissing(): void
    {
        $minutesId = 'test-minutes-uuid-3';

        // Minutes with no meeting relation.
        $minutesEntity = $this->createObjectEntityMock([
            'id'        => $minutesId,
            'title'     => 'Notulen Zonder Vergadering',
            'lifecycle' => 'draft',
            // No 'meeting' key.
        ]);

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'findAll', 'setRegister', 'setSchema', 'saveObject'])
            ->getMock();

        $objectService->method('find')
            ->willReturn($minutesEntity);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Meeting/');

        $this->service->generateDraft($minutesId);

    }//end testGenerateDraftThrowsExceptionWhenLinkedMeetingIsMissing()

    /**
     * Test that generateDraft throws RuntimeException when Minutes object is not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
     *
     * @return void
     */
    public function testGenerateDraftThrowsExceptionWhenMinutesNotFound(): void
    {
        $minutesId = 'non-existent-uuid';

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'findAll', 'setRegister', 'setSchema', 'saveObject'])
            ->getMock();

        $objectService->method('find')
            ->willReturn(null);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->service->generateDraft($minutesId);

    }//end testGenerateDraftThrowsExceptionWhenMinutesNotFound()

    /**
     * Test that generateDraft throws RuntimeException when OpenRegister is not available.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9.1
     *
     * @return void
     */
    public function testGenerateDraftThrowsExceptionWhenOpenRegisterNotAvailable(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('Service not found'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenRegister/');

        $this->service->generateDraft('some-uuid');

    }//end testGenerateDraftThrowsExceptionWhenOpenRegisterNotAvailable()

    /**
     * Helper: create a mock ObjectEntity-like object.
     *
     * @param array<string,mixed> $data Object data to return from getObject()
     *
     * @return object
     */
    private function createObjectEntityMock(array $data): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject'])
            ->getMock();

        $mock->method('getObject')->willReturn($data);

        return $mock;

    }//end createObjectEntityMock()

    /**
     * Helper: create a mock ObjectService with specific return values.
     *
     * @param object      $minutesEntity      Minutes entity mock
     * @param object|null $meetingEntity      Meeting entity mock (or null to simulate not found)
     * @param array<int,object> $agendaItemEntities Agenda item entity mocks
     * @param array<int,object> $motionEntities     Motion entity mocks
     * @param array<int,object> $votingRoundEntities VotingRound entity mocks
     * @param array<int,object> $decisionEntities   Decision entity mocks
     *
     * @return object
     */
    private function createObjectServiceMock(
        object $minutesEntity,
        ?object $meetingEntity,
        array $agendaItemEntities,
        array $motionEntities,
        array $votingRoundEntities,
        array $decisionEntities
    ): object {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'findAll', 'setRegister', 'setSchema', 'saveObject'])
            ->getMock();

        // find() returns minutes first, then meeting.
        $objectService->method('find')
            ->willReturnCallback(
                function (mixed $id) use ($minutesEntity, $meetingEntity): ?object {
                    if ($id === 'test-minutes-uuid-1'
                        || $id === 'test-minutes-uuid-2'
                        || $id === 'test-minutes-uuid-3'
                    ) {
                        return $minutesEntity;
                    }

                    return $meetingEntity;
                }
            );

        // findAll() returns different collections based on schema filter.
        $objectService->method('findAll')
            ->willReturnCallback(
                function (array $config) use (
                    $agendaItemEntities,
                    $motionEntities,
                    $votingRoundEntities,
                    $decisionEntities
                ): array {
                    $schema = $config['filters']['schema'] ?? '';
                    return match ($schema) {
                        'agenda-item'  => $agendaItemEntities,
                        'motion'       => $motionEntities,
                        'voting-round' => $votingRoundEntities,
                        'decision'     => $decisionEntities,
                        default        => [],
                    };
                }
            );

        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();

        return $objectService;

    }//end createObjectServiceMock()

}//end class
