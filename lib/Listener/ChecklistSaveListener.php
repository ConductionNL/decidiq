<?php

/**
 * Guards and stamps a checklist record (an onboarding or an offboarding) as it is saved.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ChecklistProgress;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Before an onboarding or offboarding is stored:
 *
 * - it is refused when it would reach `completed` with a mandatory step still
 *   open (the message names the steps), or leave `completed` or `lapsed`;
 * - otherwise it is stamped with the open step that is due first
 *   (`nextStepDueDate`, `nextStepTitle`), which the declared due-soon and
 *   overdue reminders read, since a notification filter cannot look inside
 *   the steps list.
 *
 * This holds for every write, the record's own edit form included, not only
 * for the checklist routes.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
 */
class ChecklistSaveListener implements IEventListener {

	/**
	 * The schemas whose records carry a step checklist.
	 *
	 * @var list<string>
	 */
	public const SCHEMAS = ['member-onboarding', 'member-offboarding'];

	/**
	 * Reads where a checklist stands.
	 *
	 * @var ChecklistProgress
	 */
	private readonly ChecklistProgress $progress;

	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
	 */
	public function __construct() {
		$this->progress = new ChecklistProgress();
	}//end __construct()

	/**
	 * Refuse or stamp the record being saved.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-010-declarative-step-reminders
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, record: (array)($event->getObject()?->getObject() ?? []), stored: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$stored = $event->getOldObject()?->getObject();
			$this->check(event: $event, record: (array)$event->getNewObject()->getObject(), stored: ($stored === null ? null : (array)$stored));
		}
	}//end handle()

	/**
	 * Refuse the save, or add the next due step to what is stored.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The before-save event.
	 * @param array<string, mixed>                    $record The record being saved.
	 * @param array<string, mixed>|null               $stored The record before this save.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $record, ?array $stored): void {
		$refusal = $this->progress->refusal(record: $record, stored: $stored);
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->progress->nextDue(record: $record)));
	}//end check()
}//end class
