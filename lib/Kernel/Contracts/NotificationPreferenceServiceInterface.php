<?php

/**
 * Contract of NotificationPreferenceService
 *
 * The public surface of NotificationPreferenceService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Contract of NotificationPreferenceService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationPreferenceServiceInterface {

	/**
	 * Preference-aware, delegation-aware notification dispatch.
	 *
	 * 1. Event filter — the originating person's own toggle decides whether
	 *    the event notifies at all (their delegate inherits exactly what the
	 *    absent member would have received).
	 * 2. Recipient expansion — the person plus their active delegate.
	 * 3. Channel selection per recipient — each recipient's own
	 *    `deliveryMethod` decides in-app (a decidiq notification through
	 *    Nextcloud's notification manager, rendered by
	 *    OCA\Decidiq\Notification\Notifier) and/or e-mail (IMailer, to
	 *    governanceEmail ?? account email).
	 *
	 * Fail-soft by design: a failing channel is logged and never breaks the
	 * calling flow (publishing a decision / opening a voting round must not
	 * depend on notification delivery).
	 *
	 * @param string $personId The originating recipient (NC UID)
	 * @param string $eventType One of the DEFAULTS event-toggle keys
	 * @param string $title Notification title
	 * @param string $message Notification body
	 * @param string $deepLink In-app deep link (app-relative, e.g. /decisions/{id})
	 * @param array<string, mixed>|null $inApp The bell notice (subject, parameters, objectType, objectId), or null for decidiq_message
	 * @param array<int, array<string, string>> $attachments Files for the email, each { data, filename, contentType }
	 *
	 * @return int Number of channel deliveries performed
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-002-preference-aware-in-app-notices-are-sent-as-decidiq
	 */
	public function dispatch(
		string $personId,
		string $eventType,
		string $title,
		string $message,
		string $deepLink = '',
		?array $inApp = null,
		array $attachments = [],
	): int;

	/**
	 * Get the person's preference merged over the defaults.
	 *
	 * Always returns every known preference field so REST consumers (the
	 * settings UI) see the effective configuration, not a sparse object.
	 *
	 * @param string $personId Person UUID or user ID
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function getPreferenceWithDefaults(string $personId): array;

	/**
	 * Create or update a NotificationPreference object for a person.
	 *
	 * @param string $personId Person UUID or user ID
	 * @param array<string, mixed> $preferences Preference fields
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-7.1
	 */
	public function updatePreference(string $personId, array $preferences): array;
}//end interface
