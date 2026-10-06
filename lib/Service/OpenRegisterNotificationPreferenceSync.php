<?php

/**
 * Decidiq OpenRegister Notification Preference Sync
 *
 * Writes a member's decidiq notification switches into OpenRegister's
 * per-user overrides, so the notices OpenRegister sends from the schema
 * declarations stop together with decidiq's own (issue #1381).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors decidiq notification switches onto OpenRegister's per-user overrides.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class OpenRegisterNotificationPreferenceSync {

	/**
	 * Toggles whose notices OpenRegister sends from the schema declarations.
	 *
	 * Per toggle, the `[schemaSlug, notificationKey]` pairs of the
	 * `x-openregister-notifications` rules that tell a member the same thing.
	 * OpenRegister's dispatcher does not read decidiq's preference object; it
	 * reads its own per-user override (`notification_pref/<schema>/<key>`). So
	 * a switch the member turns off in decidiq is written there too, and the
	 * declared notice stops with it (issue #1381). Toggles with no declared
	 * notice (meetingCreated: its rule is switched off; votingOpened,
	 * agendaChanged: decidiq sends those itself) are not listed.
	 *
	 * @var array<string, array<int, array{0: string, 1: string}>>
	 */
	public const OPENREGISTER_NOTICES = [
		'decisionPublished' => [
			['decision', 'decisionPublished'],
		],
		'taskAssigned'      => [
			['action-item', 'actionAssigned'],
			['action-item', 'actionItemAssignedToYou'],
		],
		'meetingReminder'   => [
			['meeting', 'meetingStartingSoon'],
		],
	];

	/**
	 * OpenRegister's per-user notification preference service.
	 */
	private const OPENREGISTER_PREFERENCES = 'OCA\OpenRegister\Service\Notification\NotificationPreferenceService';

	/**
	 * Construct the OpenRegisterNotificationPreferenceSync.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads OpenRegister's preference service)
	 * @param LoggerInterface    $logger    Logger interface
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the member's switches into OpenRegister's per-user overrides.
	 *
	 * A switch that is off stores `{enabled: false}` for every declared notice
	 * it covers. A switch that is on clears the override, so the schema
	 * default (and any team default an administrator set in OpenRegister)
	 * applies again rather than being pinned on.
	 *
	 * Fail-soft: the decidiq preference is already saved. Without OpenRegister's
	 * preference service (an older OpenRegister) nothing is mirrored and that
	 * is logged.
	 *
	 * @param string               $personId Nextcloud UID of the member
	 * @param array<string, mixed> $merged   The saved preference, merged over the defaults
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function sync(string $personId, array $merged): void {
		try {
			$overrides = $this->container->get(self::OPENREGISTER_PREFERENCES);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: OpenRegister notification preferences unavailable, switches not applied to declared notices',
				['personId' => $personId, 'error' => $e->getMessage()]
			);
			return;
		}

		foreach (self::OPENREGISTER_NOTICES as $toggle => $notices) {
			$override = null;
			if ((bool)($merged[$toggle] ?? true) === false) {
				$override = ['enabled' => false];
			}

			foreach ($notices as [$schemaSlug, $notificationKey]) {
				try {
					$overrides->setOverride(
						userId: $personId,
						schemaSlug: $schemaSlug,
						notificationKey: $notificationKey,
						override: $override
					);
				} catch (\Throwable $e) {
					$this->logger->warning(
						'Decidiq: could not apply a notification switch to OpenRegister',
						['personId' => $personId, 'notice' => $schemaSlug . '/' . $notificationKey, 'error' => $e->getMessage()]
					);
				}
			}
		}//end foreach
	}//end sync()
}//end class
