<?php

/**
 * Decidiq Decision Term Listener
 *
 * Writes `termEndsAt` on a decision whose type declares a term, on every
 * create and update: the type's term, counted from the moment the type
 * names (DecisionTermCalculator). A decision whose type declares none, or
 * whose moment is not known yet, is saved with the field empty.
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
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\DecisionTermCalculator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the day a decision has to be taken by.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */
class DecisionTermListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the decision type and the contested decision.
	 * @param LoggerInterface        $logger        The log.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the decision's `termEndsAt` from its type.
	 *
	 * A failure to read the type or the contested decision leaves the save
	 * alone: the term is information for the clerk, not a guard.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		try {
			$decision = array_merge($this->payloadOf(event: $event), $event->getModifiedData());
			$type = $this->load(id: self::reference(value: ($decision['type'] ?? null)), schema: 'decision-template');
			if ($type === null) {
				return;
			}

			$contested = $this->load(id: self::reference(value: ($decision['contests'] ?? null)), schema: 'decision');
			$termEnd = (new DecisionTermCalculator())->termEnd(decision: $decision, type: $type, contested: $contested);
			$event->setModifiedData(array_merge($event->getModifiedData(), ['termEndsAt' => $termEnd]));
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the decision term was not worked out', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The decision as the event carries it: the new object on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function payloadOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent) {
			return (new EntityPayloadReader())->payloadOf(entity: $event->getNewObject());
		}

		return (new EntityPayloadReader())->payloadOf(entity: $event->getObject());
	}//end payloadOf()

	/**
	 * One object of the register by id, or null when it is not there or cannot be read.
	 *
	 * @param string $id     The uuid or slug; '' reads nothing.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false);
		} catch (Throwable) {
			// Not there, or not readable: the term counts as not known.
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end load()

	/**
	 * The id a reference holds: a uuid or slug string, or an expanded object.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()
}//end class
