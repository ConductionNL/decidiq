<?php

/**
 * What a save guard reads from, and writes back onto, an OpenRegister save event.
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
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * The entity about to be saved, the schema check on its record and the refusal.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */
final class SaveGuardEvent {

	/**
	 * The entity a creating or updating event is about to save.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null The new entity, or null for any other event.
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public static function entityOf(Event $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return null;
	}//end entityOf()

	/**
	 * Whether a record names the schema, judged on the first schema key it carries.
	 *
	 * @param array<string, mixed> $row    The record.
	 * @param string               $schema The schema slug.
	 *
	 * @return bool|null True or false when the record names a schema, null when it names none.
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public static function namesSchema(array $row, string $schema): ?bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			if (is_string($row[$key] ?? null) === true && $row[$key] !== '') {
				return strtolower($row[$key]) === $schema;
			}
		}

		return null;
	}//end namesSchema()

	/**
	 * Refuse the save with a message and stop the other listeners.
	 *
	 * @param Event  $event   The event; only a creating or updating event is refused.
	 * @param string $message Why the save is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public static function refuse(Event $event, string $message): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()
}//end class
