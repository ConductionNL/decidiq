<?php

/**
 * Refuses a confidentiality restriction whose planned end lies before it was imposed.
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

use DateTimeImmutable;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A `confidentiality-restriction` is saved only when its `liftBy` is not before
 * the day it was imposed.
 *
 * Two fields of one record: a schema `allOf` could say it, but OpenRegister
 * does not evaluate a cross-field rule, so this guard makes it hold for the
 * form and the API alike. A date that cannot be read is left to the schema's
 * own format check.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */
class ConfidentialityTermGuardListener implements IEventListener {

	/**
	 * The schema slug of a confidentiality restriction.
	 *
	 * @var string
	 */
	public const SCHEMA = 'confidentiality-restriction';

	/**
	 * Refuse a restriction whose planned end lies before it was imposed.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public function handle(Event $event): void {
		$entity = SaveGuardEvent::entityOf(event: $event);
		if ($entity === null) {
			return;
		}

		$row = $this->row(entity: $entity);
		if ($this->isRestriction(row: $row) === false) {
			return;
		}

		$message = $this->refusal(row: $row);
		if ($message !== null) {
			SaveGuardEvent::refuse(event: $event, message: $message);
		}
	}//end handle()

	/**
	 * Why the restriction may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public function refusal(array $row): ?string {
		$liftBy  = $this->day(value: ($row['liftBy'] ?? null));
		$imposed = $this->day(value: ($row['imposedAt'] ?? null));
		if ($liftBy === null || $imposed === null || $liftBy >= $imposed) {
			return null;
		}

		return sprintf(
			'The confidentiality was not saved: the lift-by date %s is before the date it was imposed, %s.',
			$liftBy,
			$imposed
		);
	}//end refusal()

	/**
	 * The calendar day of a date or date-time, as written in its own offset.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The day as Y-m-d, or null when there is none or it cannot be read.
	 */
	private function day(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable(trim($value)))->format('Y-m-d');
		} catch (\Exception) {
			return null;
		}
	}//end day()

	/**
	 * The record of an entity.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return array<string, mixed>
	 */
	private function row(object $entity): array {
		if (method_exists($entity, 'getObject') === false) {
			return [];
		}

		return (array)$entity->getObject();
	}//end row()

	/**
	 * Whether the record is a confidentiality restriction. The subscription
	 * already narrows to the schema; without it only a record that carries both
	 * dates and a ground is judged.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return bool
	 */
	private function isRestriction(array $row): bool {
		return SaveGuardEvent::namesSchema(row: $row, schema: self::SCHEMA)
			?? (array_key_exists('ground', $row) === true && array_key_exists('liftBy', $row) === true);
	}//end isRestriction()
}//end class
