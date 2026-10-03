<?php

/**
 * Decidiq Save Guard Subscriptions
 *
 * The before-save (creating/updating) object listeners Decidiq subscribes,
 * with the schema each one declares interest in. Kept apart from
 * {@see ObjectListenerRegistrar} so the registrar does not accumulate a class
 * reference for every guard it subscribes (PHPMD CouplingBetweenObjects).
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener;
use OCA\Decidiq\Listener\MeetingDefaultsListener;
use OCA\Decidiq\Listener\SubmissionDeadlineListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * The before-save listener subscriptions.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
final class SaveGuardSubscriptions {

	/**
	 * Every before-save subscription as event, listener and schema slugs.
	 *
	 * Each declared schema is the handler's own literal schema guard, so the
	 * declaration can never be narrower than the guard it fronts.
	 *
	 * @var list<array{event: string, listener: string, schemas: list<string>}>
	 */
	public const ALL = [
		// Submission deadline gate (motion-amendment spec). ADR-005 retired
		// the `motion` and `amendment` schemas into `decision`; the
		// motion/amendment narrowing happens inside the handler on the
		// `decisionType` discriminator, which no subscription can express.
		['event' => ObjectCreatingEvent::class, 'listener' => SubmissionDeadlineListener::class, 'schemas' => ['decision']],
		// Meeting defaults (meeting-rules-from-body-and-type, REQ-MRB-001):
		// a new meeting takes the empty fields from its type, then its body.
		['event' => ObjectCreatingEvent::class, 'listener' => MeetingDefaultsListener::class, 'schemas' => ['meeting']],
		// Submission window sanity (motions-submission-window,
		// REQ-SUBW-003): a meeting whose window opens after it closes is
		// refused, on create and on update.
		['event' => ObjectCreatingEvent::class, 'listener' => SubmissionDeadlineListener::class, 'schemas' => ['meeting']],
		['event' => ObjectUpdatingEvent::class, 'listener' => SubmissionDeadlineListener::class, 'schemas' => ['meeting']],
		// Document details (platform-document-metadata-fields, REQ-DMF-002
		// and REQ-DMF-004): a record keeps its type's required fields and
		// one record describes one file, on create and on update.
		['event' => ObjectCreatingEvent::class, 'listener' => DocumentTypeFieldsGuardListener::class, 'schemas' => ['digital-document']],
		['event' => ObjectUpdatingEvent::class, 'listener' => DocumentTypeFieldsGuardListener::class, 'schemas' => ['digital-document']],
	];
}//end class
