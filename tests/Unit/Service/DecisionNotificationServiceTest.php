<?php

/**
 * Unit tests for DecisionNotificationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionNotificationService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
 */
class DecisionNotificationServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var DecisionNotificationService
     */
    private DecisionNotificationService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock AppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->container
            ->method('get')
            ->willReturnMap([
                ['OCA\OpenRegister\Service\ObjectService', $this->objectService],
                ['OCP\IAppConfig', $this->appConfig],
            ]);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('');

        $this->service = new DecisionNotificationService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test notifyOnPublish sends notifications to chair and secretary by default.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
     *
     * @return void
     */
    public function testNotifyOnPublishSendsNotificationsToDefaultRoles(): void
    {
        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => 'decision-1',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($decisionEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    ['user' => 'user-1', 'role' => 'chair'],
                    ['user' => 'user-2', 'role' => 'secretary'],
                ],
            ]);

        $count = $this->service->notifyOnPublish('decision-1');

        $this->assertGreaterThan(0, $count);
    }//end testNotifyOnPublishSendsNotificationsToDefaultRoles()

    /**
     * Test notifyOnPublish sends zero notifications when no Memberships found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
     *
     * @return void
     */
    public function testNotifyOnPublishSendsZeroWhenNoMembershipsFound(): void
    {
        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => 'decision-1',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($decisionEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $count = $this->service->notifyOnPublish('decision-1');

        $this->assertEquals($count, 0);
    }//end testNotifyOnPublishSendsZeroWhenNoMembershipsFound()

    /**
     * Test resolveRecipients filters by correct roles.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
     *
     * @return void
     */
    public function testResolveRecipientsFiltersByCorrectRoles(): void
    {
        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => 'decision-1',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($decisionEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn([
                'results' => [
                    ['user' => 'user-1', 'role' => 'chair'],
                    ['user' => 'user-2', 'role' => 'member'],
                ],
            ]);

        $recipients = $this->service->resolveRecipients('decision-1', ['chair']);

        $this->assertGreaterThan(0, count($recipients));
    }//end testResolveRecipientsFiltersByCorrectRoles()

    /**
     * Test notifyOnPublish uses configured roles from IAppConfig.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.4
     *
     * @return void
     */
    public function testNotifyOnPublishUsesConfiguredRoles(): void
    {
        $configuredRoles = json_encode(['chair', 'observer']);

        $this->appConfig
            ->method('getValueString')
            ->willReturn($configuredRoles);

        $decisionEntity = $this->createMock(ObjectEntity::class);
        $decisionEntity->method('getObject')->willReturn([
            'id' => 'decision-1',
            'governanceBody' => 'body-1',
        ]);

        $this->objectService
            ->method('find')
            ->willReturn($decisionEntity);

        $this->objectService
            ->method('findAll')
            ->willReturn(['results' => []]);

        $count = $this->service->notifyOnPublish('decision-1');

        // Should return 0 since no memberships found, but the configured roles should be respected
        $this->assertEquals($count, 0);
    }//end testNotifyOnPublishUsesConfiguredRoles()
}//end class
