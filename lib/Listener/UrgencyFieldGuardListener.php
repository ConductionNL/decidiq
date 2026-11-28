<?php

/**
 * Urgency Field Guard Listener
 *
 * The urgency fields of a decision are written only by the guarded urgency
 * declaration. A create or update that sets or changes them any other way is
 * refused; an update that leaves them out keeps the stored values, because
 * OpenRegister's save replaces the whole object and an unrelated edit must
 * not silently drop a declared urgency.
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
 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\UrgencyDeclarationService;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Refuses urgency writes outside the urgency flow and carries the fields forward.
 *
 * A save without a user (a seed import, a background job) is not a client
 * write and passes; every write a person makes goes through the guard.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */
class UrgencyFieldGuardListener implements IEventListener {

	/**
	 * The refusal a direct urgency write gets.
	 *
	 * @var string
	 */
	public const REFUSAL = 'The decision was not saved: urgency is declared through the urgency procedure, not by editing the decision.';

	/**
	 * Constructor.
	 *
	 * @param UrgencyWriteGrant $grant       The decisions the urgency flow is saving.
	 * @param IUserSession      $userSession The caller.
	 */
	public function __construct(
		private readonly UrgencyWriteGrant $grant,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse a direct urgency write, or carry the stored urgency forward.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		[$entity, $old] = $this->entityAndOld(event: $event);
		$new = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->grant->isGranted(decisionId: $this->uuidOf(entity: $entity, row: $new)) === true || $this->userSession->getUser() === null) {
			return;
		}

		$carry = $this->carried(new: $new, old: $old);
		if ($carry === null) {
			$event->setErrors(['message' => self::REFUSAL]);
			$event->stopPropagation();
			return;
		}

		if ($carry !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $carry));
		}
	}//end handle()

	/**
	 * The object being saved, and the stored payload it replaces (empty on create).
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The save event
	 *
	 * @return array{0: object, 1: array<string, mixed>}
	 *
	 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	private function entityAndOld(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectCreatingEvent) {
			return [$event->getObject(), []];
		}

		$old = [];
		if ($event->getOldObject() !== null) {
			$old = (new EntityPayloadReader())->payloadOf(entity: $event->getOldObject());
		}

		return [$event->getNewObject(), $old];
	}//end entityAndOld()

	/**
	 * The urgency fields to carry over from the stored object, or null when the
	 * save changes one of them outside the urgency procedure.
	 *
	 * An urgency field the save leaves out keeps its stored value; one it
	 * sends must be unchanged (two empty values count as unchanged).
	 *
	 * @param array<string, mixed> $new The payload being saved
	 * @param array<string, mixed> $old The stored payload
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	private function carried(array $new, array $old): ?array {
		$carry = [];
		foreach (UrgencyDeclarationService::URGENCY_FIELDS as $field) {
			if (array_key_exists($field, $new) === false) {
				if (array_key_exists($field, $old) === true) {
					$carry[$field] = $old[$field];
				}

				continue;
			}

			$unchanged = $new[$field] === ($old[$field] ?? null)
				|| (self::isEmpty(value: $new[$field]) === true && self::isEmpty(value: ($old[$field] ?? null)) === true);
			if ($unchanged === false) {
				return null;
			}
		}

		return $carry;
	}//end carried()

	/**
	 * Whether a field value says nothing: absent, null, false or an empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isEmpty(mixed $value): bool {
		return $value === null || $value === false || $value === '';
	}//end isEmpty()

	/**
	 * The uuid of the entity being saved.
	 *
	 * @param object               $entity The entity.
	 * @param array<string, mixed> $row    Its payload.
	 *
	 * @return string
	 */
	private function uuidOf(object $entity, array $row): string {
		if (method_exists($entity, 'getUuid') === true && is_string($entity->getUuid()) === true) {
			return (string)$entity->getUuid();
		}

		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end uuidOf()
}//end class
