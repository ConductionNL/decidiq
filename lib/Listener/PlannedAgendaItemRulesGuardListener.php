<?php

/**
 * Refuses a planned-agenda item that breaks one of its value-dependent rules,
 * before it is saved.
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
 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A planned-agenda item is saved only when a portfolio holder as owner type
 * names the person who holds the portfolio, and when an item entering
 * `lapsed` carries the reason it lapsed.
 *
 * Both rules depend on another field's value, which a schema `required` list
 * cannot express. The dialogs check them too; this guard makes them hold for
 * every write path, the API included.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
 */
class PlannedAgendaItemRulesGuardListener implements IEventListener {

	/**
	 * The schema slugs this guard fronts: the current one and the superseded
	 * one whose rows are kept.
	 *
	 * @var list<string>
	 */
	public const SCHEMAS = ['planned-agenda-item', 'termijnagenda-item'];

	/**
	 * The owner type that needs a person as owner.
	 *
	 * @var string
	 */
	private const PERSON_OWNER_TYPE = 'portfolio-holder';

	/**
	 * The lifecycle state that needs a reason.
	 *
	 * @var string
	 */
	private const LAPSED = 'lapsed';

	/**
	 * Stop the save of an item that breaks a rule.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = [];
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$before = $event->getOldObject();
			if (is_object($before) === true) {
				$old = (new EntityPayloadReader())->payloadOf(entity: $before);
			}
		} else {
			$entity = $event->getObject();
		}

		if (is_object($entity) === false) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->isAnotherSchema(row: $row) === true) {
			return;
		}

		$message = $this->refusal(row: $row, old: $old);
		if ($message === null) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Why the item may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $row The item as it would be saved.
	 * @param array<string, mixed> $old The item as stored, or [] on create.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
	 */
	public function refusal(array $row, array $old=[]): ?string {
		if (($row['ownerType'] ?? null) === self::PERSON_OWNER_TYPE && $this->isEmpty(value: ($row['owner'] ?? null)) === true) {
			return 'The item was not saved: a portfolio holder as owner needs the person who holds the portfolio.';
		}

		// Only entering the state needs the reason: a stored lapsed row is
		// final, and its later edits are the transition map's to refuse.
		$entering = ($row['lifecycle'] ?? null) === self::LAPSED && ($old['lifecycle'] ?? null) !== self::LAPSED;
		if ($entering === true && $this->isEmpty(value: ($row['reasonExpired'] ?? null)) === true) {
			return 'The item was not marked as lapsed: give the reason it lapsed.';
		}

		return null;
	}//end refusal()

	/**
	 * Whether a value counts as missing: absent, null, or blank text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-003-lifecycle-is-declarative-with-shift-and-settle-states
	 */
	private function isEmpty(mixed $value): bool {
		if ($value === null || $value === []) {
			return true;
		}

		return is_string($value) === true && trim($value) === '';
	}//end isEmpty()

	/**
	 * Whether the row names a schema other than a planned-agenda item.
	 *
	 * The subscription routes only planned-agenda saves here; a row whose
	 * schema cannot be read is treated as the item it was routed as.
	 *
	 * @param array<string, mixed> $row The item.
	 *
	 * @return bool True when it is clearly another schema.
	 *
	 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
	 */
	private function isAnotherSchema(array $row): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return in_array(strtolower($candidate), self::SCHEMAS, true) === false;
			}
		}

		return false;
	}//end isAnotherSchema()
}//end class
