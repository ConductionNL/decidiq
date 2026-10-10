<?php

/**
 * Decidiq Files Event Registrar
 *
 * Registers the listeners that react to Nextcloud Files events: an Office
 * paper added to a meeting or agenda item folder is queued for conversion to
 * PDF (agenda-office-files-to-pdf, REQ-OPDF-001).
 *
 * @category AppInfo
 * @package  OCA\Decidiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Listener\OfficePaperAddedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * Files event listener registration.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */
final class FilesEventRegistrar {

	/**
	 * Register the Files event listeners.
	 *
	 * The listener returns at once for anything but an Office file in a
	 * folder named after a decidiq meeting or agenda item, and only queues a
	 * job: the upload never waits for a conversion.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 *
	 * @return void
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: NodeCreatedEvent::class, listener: OfficePaperAddedListener::class);
		$context->registerEventListener(event: NodeWrittenEvent::class, listener: OfficePaperAddedListener::class);
	}//end register()
}//end class
