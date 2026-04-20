<?php

/**
 * Decidesk Decision Notification Service
 *
 * Manages notification subscriptions for Decisions and Minutes lifecycle changes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IAppConfig;
use OCP\Notification\IManager;

/**
 * Stateless service for managing notification subscriptions and dispatching notifications.
 *
 * Subscriptions are stored in IAppConfig per object, keyed by `notification_subscriptions_{objectId}`.
 * Each subscription is a JSON array of { userId, objectType, subscribedAt } entries.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 */
class DecisionNotificationService
{
    /**
     * Constructor for DecisionNotificationService.
     *
     * @param IAppConfig $appConfig           The app configuration service
     * @param IManager   $notificationManager The Nextcloud notification manager
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IManager $notificationManager,
    ) {
    }//end __construct()

    /**
     * Subscribe a user to lifecycle notifications for an object.
     *
     * @param string $objectId   UUID of the object (Decision, Minutes, etc.)
     * @param string $objectType Type of the object (e.g., 'decision', 'minutes')
     * @param string $userId     User ID subscribing
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function subscribe(string $objectId, string $objectType, string $userId): void
    {
        $key           = sprintf('notification_subscriptions_%s', $objectId);
        $subscriptions = $this->getSubscriptions($objectId);

        // Check if already subscribed
        foreach ($subscriptions as $sub) {
            if ($sub['userId'] === $userId) {
                return;
                // Already subscribed, idempotent
            }
        }

        // Add new subscription
        $subscriptions[] = [
            'userId'       => $userId,
            'objectType'   => $objectType,
            'subscribedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->appConfig->setValueArray(
            app: 'decidesk',
            key: $key,
            value: $subscriptions
        );
    }//end subscribe()

    /**
     * Unsubscribe a user from lifecycle notifications for an object.
     *
     * @param string $objectId UUID of the object
     * @param string $userId   User ID unsubscribing
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function unsubscribe(string $objectId, string $userId): void
    {
        $key           = sprintf('notification_subscriptions_%s', $objectId);
        $subscriptions = $this->getSubscriptions($objectId);

        // Filter out the matching subscription
        $subscriptions = array_filter(
            $subscriptions,
            static function (array $sub) use ($userId): bool {
                return $sub['userId'] !== $userId;
            }
        );

        // Re-index array and save
        $this->appConfig->setValueArray(
            app: 'decidesk',
            key: $key,
            value: array_values($subscriptions)
        );
    }//end unsubscribe()

    /**
     * Check if a user is subscribed to notifications for an object.
     *
     * @param string $objectId UUID of the object
     * @param string $userId   User ID to check
     *
     * @return bool True if subscribed, false otherwise
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function isSubscribed(string $objectId, string $userId): bool
    {
        $subscriptions = $this->getSubscriptions($objectId);
        foreach ($subscriptions as $sub) {
            if ($sub['userId'] === $userId) {
                return true;
            }
        }

        return false;
    }//end isSubscribed()

    /**
     * Dispatch a notification to all subscribers of an object.
     *
     * @param string $objectId    UUID of the object
     * @param string $objectType  Type of the object (e.g., 'decision', 'minutes')
     * @param string $oldState    Previous lifecycle state
     * @param string $newState    New lifecycle state
     * @param string $objectTitle Title/name of the object
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function dispatch(
        string $objectId,
        string $objectType,
        string $oldState,
        string $newState,
        string $objectTitle
    ): void {
        $subscriptions = $this->getSubscriptions($objectId);

        foreach ($subscriptions as $sub) {
            $userId = $sub['userId'] ?? null;
            if ($userId === null) {
                continue;
            }

            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('decidesk')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject($objectType, $objectId)
                ->setSubject(
                        'decision_state_changed',
                        [
                            'title'    => $objectTitle,
                            'oldState' => $oldState,
                            'newState' => $newState,
                        ]
                        )
                ->setLink(sprintf('/apps/decidesk/%ss/%s', $objectType, $objectId));

            $this->notificationManager->notify($notification);
        }//end foreach
    }//end dispatch()

    /**
     * Get all subscriptions for an object.
     *
     * @param string $objectId UUID of the object
     *
     * @return array<int, array<string, string>> Array of subscription entries
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    private function getSubscriptions(string $objectId): array
    {
        $key = sprintf('notification_subscriptions_%s', $objectId);
        try {
            $value = $this->appConfig->getValueArray(app: 'decidesk', key: $key);
            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }//end getSubscriptions()
}//end class
