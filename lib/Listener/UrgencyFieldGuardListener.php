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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
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
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = [];
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			if ($event->getOldObject() !== null) {
				$old = (new EntityPayloadReader())->payloadOf(entity: $event->getOldObject());
			}
		} else {
			$entity = $event->getObject();
		}

		$new = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->grant->isGranted(decisionId: $this->uuidOf(entity: $entity, row: $new)) === true || $this->userSession->getUser() === null) {
			return;
		}

		$carry = [];
		foreach (UrgencyDeclarationService::URGENCY_FIELDS as $field) {
			if (array_key_exists($field, $new) === false) {
				if (array_key_exists($field, $old) === true) {
					$carry[$field] = $old[$field];
				}

				continue;
			}

			if (self::isEmpty(value: $new[$field]) === true && self::isEmpty(value: ($old[$field] ?? null)) === true) {
				continue;
			}

			if ($new[$field] !== ($old[$field] ?? null)) {
				$event->setErrors(['message' => self::REFUSAL]);
				$event->stopPropagation();
				return;
			}
		}//end foreach

		if ($carry !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $carry));
		}
	}//end handle()

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
