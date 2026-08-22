<?php

/**
 * Decidiq Motion Notifier
 *
 * Delivers the Nextcloud notifications raised by the motion workflow
 * (co-signature requests and forwarding-approval notices). Extracted from
 * MotionService so motion domain logic no longer builds notification objects
 * itself — the two call sites had drifted into near-duplicate blocks.
 *
 * Delivery is best-effort by design: a notification backend that is missing or
 * failing must never abort the motion transaction that raised it, so every
 * failure is logged at warning level and swallowed. That mirrors the behaviour
 * the call sites already had.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Best-effort delivery of motion-related Nextcloud notifications.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
 */
class MotionNotifier {
	/**
	 * Construct the MotionNotifier.
	 *
	 * @param ContainerInterface $container The DI container for lazy-loading the notification manager
	 * @param LoggerInterface $logger Logger interface
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Send one motion notification, swallowing and logging any delivery failure.
	 *
	 * @param string $userId Nextcloud UID of the recipient
	 * @param string $motionId UUID the notification is about
	 * @param string $subject Notification subject key
	 * @param array<string, mixed> $parameters Subject parameters
	 * @param string $failureLog Warning message prefix logged on failure
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
	 *
	 * @return void
	 */
	public function notify(string $userId, string $motionId, string $subject, array $parameters, string $failureLog): void {
		try {
			$manager = $this->container->get(IManager::class);
			$notification = $manager->createNotification();
			$notification->setApp('decidesk')
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('motion', $motionId)
				->setSubject($subject, $parameters);
			$manager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: $failureLog . $e->getMessage(),
				context: ['exception' => $e]
			);
		}//end try

	}//end notify()
}//end class
