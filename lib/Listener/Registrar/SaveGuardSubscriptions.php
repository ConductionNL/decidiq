<?php

/**
 * Decidiq Save Guard Subscriptions
 *
 * The before-save (creating/updating) object listeners Decidiq subscribes,
 * with the schema each one declares interest in. Kept apart from
 * {@see ObjectListenerRegistrar} so the registrar does not accumulate a class
 * reference for every guard it subscribes (PHPMD CouplingBetweenObjects).
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener\Registrar
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

namespace OCA\Decidiq\Listener\Registrar;

use OCA\Decidiq\Listener\DecisionCompetenceGuardListener;
use OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener;
use OCA\Decidiq\Listener\ImplementationUpdateListener;
use OCA\Decidiq\Listener\MeetingDefaultsListener;
use OCA\Decidiq\Listener\OndermandaatGuardListener;
use OCA\Decidiq\Listener\ParallelSessionListener;
use OCA\Decidiq\Listener\SubmissionDeadlineListener;
use OCA\Decidiq\Listener\UrgencyFieldGuardListener;
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
		// Parallel sessions (planning-parallel-sessions, REQ-PPS-001): a
		// session stays inside its evening, is never nested, and a new one
		// takes the evening's body, publicity and mode. After the meeting
		// defaults, so a type's body is not overwritten by the evening's.
		['event' => ObjectCreatingEvent::class, 'listener' => ParallelSessionListener::class, 'schemas' => ['meeting']],
		['event' => ObjectUpdatingEvent::class, 'listener' => ParallelSessionListener::class, 'schemas' => ['meeting']],
		// Ondermandaat chains (delegatie-mandaatregister, REQ-DMR-003): a
		// toedeling's parent must exist and permit ondermandaat, and the
		// chain may not loop, on create and on update.
		['event' => ObjectCreatingEvent::class, 'listener' => OndermandaatGuardListener::class, 'schemas' => ['bevoegdheidstoedeling']],
		['event' => ObjectUpdatingEvent::class, 'listener' => OndermandaatGuardListener::class, 'schemas' => ['bevoegdheidstoedeling']],
		// Document details (platform-document-metadata-fields, REQ-DMF-002
		// and REQ-DMF-004): a record keeps its type's required fields and
		// one record describes one file, on create and on update.
		['event' => ObjectCreatingEvent::class, 'listener' => DocumentTypeFieldsGuardListener::class, 'schemas' => ['digital-document']],
		['event' => ObjectUpdatingEvent::class, 'listener' => DocumentTypeFieldsGuardListener::class, 'schemas' => ['digital-document']],
		// Decision competence (configurable-types-domain-model, REQ-CTM-004):
		// a decision of a type is recorded only by the body the type names as
		// competent, on create and on update.
		['event' => ObjectCreatingEvent::class, 'listener' => DecisionCompetenceGuardListener::class, 'schemas' => ['decision']],
		['event' => ObjectUpdatingEvent::class, 'listener' => DecisionCompetenceGuardListener::class, 'schemas' => ['decision']],
		// Urgency fields (urgent-decision-procedure, REQ-001): written only by
		// the guarded urgency declaration, carried forward on other edits.
		['event' => ObjectCreatingEvent::class, 'listener' => UrgencyFieldGuardListener::class, 'schemas' => ['decision']],
		['event' => ObjectUpdatingEvent::class, 'listener' => UrgencyFieldGuardListener::class, 'schemas' => ['decision']],
		// Implementation progress (followup-implementation-progress,
		// REQ-FUP-001): only an adopted decision takes progress updates.
		[
			'event'    => ObjectCreatingEvent::class,
			'listener' => ImplementationUpdateListener::class,
			'schemas'  => [ImplementationUpdateListener::SCHEMA_UPDATE],
		],
	];
}//end class
