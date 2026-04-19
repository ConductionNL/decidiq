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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionNotificationService;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DecisionNotificationService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 */
class DecisionNotificationServiceTest extends TestCase
{
    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock IManager (notification manager).
     *
     * @var IManager&MockObject
     */
    private IManager&MockObject $notificationManager;

    /**
     * Service under test.
     *
     * @var DecisionNotificationService
     */
    private DecisionNotificationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->notificationManager = $this->createMock(IManager::class);

        $this->service = new DecisionNotificationService(
            $this->appConfig,
            $this->notificationManager
        );
    }

    /**
     * Test subscribe adds entry to IAppConfig.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testSubscribeAddsEntry(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn([]);

        $this->appConfig
            ->expects($this->once())
            ->method('setValueArray')
            ->with(
                'decidesk',
                'notification_subscriptions_decision-1',
                $this->callback(static function ($value) {
                    return is_array($value)
                        && count($value) === 1
                        && $value[0]['userId'] === 'user1'
                        && $value[0]['objectType'] === 'decision';
                })
            );

        $this->service->subscribe('decision-1', 'decision', 'user1');
    }

    /**
     * Test subscribe is idempotent (no duplicate if already subscribed).
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testSubscribeIsIdempotent(): void
    {
        $existingSubscription = [
            [
                'userId' => 'user1',
                'objectType' => 'decision',
                'subscribedAt' => '2026-04-19T12:00:00Z',
            ],
        ];

        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn($existingSubscription);

        $this->appConfig
            ->expects($this->never())
            ->method('setValueArray');

        $this->service->subscribe('decision-1', 'decision', 'user1');
    }

    /**
     * Test unsubscribe removes the correct entry.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testUnsubscribeRemovesEntry(): void
    {
        $existingSubscriptions = [
            [
                'userId' => 'user1',
                'objectType' => 'decision',
                'subscribedAt' => '2026-04-19T12:00:00Z',
            ],
            [
                'userId' => 'user2',
                'objectType' => 'decision',
                'subscribedAt' => '2026-04-19T12:00:00Z',
            ],
        ];

        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn($existingSubscriptions);

        $this->appConfig
            ->expects($this->once())
            ->method('setValueArray')
            ->with(
                'decidesk',
                'notification_subscriptions_decision-1',
                $this->callback(static function ($value) {
                    return is_array($value)
                        && count($value) === 1
                        && $value[0]['userId'] === 'user2';
                })
            );

        $this->service->unsubscribe('decision-1', 'user1');
    }

    /**
     * Test isSubscribed returns true after subscribe.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testIsSubscribedReturnsTrue(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn([
                [
                    'userId' => 'user1',
                    'objectType' => 'decision',
                    'subscribedAt' => '2026-04-19T12:00:00Z',
                ],
            ]);

        $this->assertTrue($this->service->isSubscribed('decision-1', 'user1'));
    }

    /**
     * Test isSubscribed returns false for unsubscribed user.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testIsSubscribedReturnsFalse(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn([]);

        $this->assertFalse($this->service->isSubscribed('decision-1', 'user1'));
    }

    /**
     * Test dispatch calls NotificationService::notify for each subscriber.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     *
     * @return void
     */
    public function testDispatchNotifiesSubscribers(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueArray')
            ->with('decidesk', 'notification_subscriptions_decision-1')
            ->willReturn([
                [
                    'userId' => 'user1',
                    'objectType' => 'decision',
                    'subscribedAt' => '2026-04-19T12:00:00Z',
                ],
                [
                    'userId' => 'user2',
                    'objectType' => 'decision',
                    'subscribedAt' => '2026-04-19T12:00:00Z',
                ],
            ]);

        $mockNotification = $this->createMock(INotification::class);
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setApp')
            ->willReturnSelf();
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setUser')
            ->willReturnSelf();
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setDateTime')
            ->willReturnSelf();
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setObject')
            ->willReturnSelf();
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setSubject')
            ->willReturnSelf();
        $mockNotification
            ->expects($this->exactly(2))
            ->method('setLink')
            ->willReturnSelf();

        $this->notificationManager
            ->expects($this->exactly(2))
            ->method('createNotification')
            ->willReturn($mockNotification);

        $this->notificationManager
            ->expects($this->exactly(2))
            ->method('notify');

        $this->service->dispatch('decision-1', 'decision', 'draft', 'published', 'Test Decision');
    }
}//end class
