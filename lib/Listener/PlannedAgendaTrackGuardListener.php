<?php

/**
 * Decidiq PlannedAgendaTrackGuardListener
 *
 * Keeps the tracks of the long-term agenda one level deep.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-002-tracks-are-one-level-deep
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a planned agenda item whose `parentItem` would make tracks more than one level deep.
 *
 * Three refusals, each naming the topic in words: a parent that is itself a
 * track, an item that already has tracks of its own getting a parent, and an
 * item pointing at itself. A schema rule cannot test a property of the
 * referenced object, so this is the save guard seam (ADR-031). When the topic
 * or the item's tracks cannot be read the save is refused rather than let a
 * second level slip in.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-002-tracks-are-one-level-deep
 */
class PlannedAgendaTrackGuardListener implements IEventListener {

	/**
	 * The schema this guard is about.
	 *
	 * @var string
	 */
	public const SCHEMA = 'planned-agenda-item';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the topic and the item's tracks.
	 * @param LoggerInterface        $logger        The log.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop the save of an item that would nest tracks deeper than one level.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-002-tracks-are-one-level-deep
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->eventObject(event: $event);
		if (is_object($entity) === false) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->isAnotherSchema(row: $row) === true) {
			return;
		}

		$parentId = self::reference(value: ($row['parentItem'] ?? null));
		if ($parentId === '') {
			return;
		}

		$ownId = self::reference(value: ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? null))));
		if ($ownId === '' && method_exists($entity, 'getUuid') === true && is_string($entity->getUuid()) === true) {
			$ownId = $entity->getUuid();
		}

		$refusal = $this->refusal(row: $row, ownId: $ownId, parentId: $parentId);
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why the item may not get this parent, or null when it may.
	 *
	 * @param array<string, mixed> $row      The item being saved.
	 * @param string               $ownId    The item's own id, '' on a create without one.
	 * @param string               $parentId The topic it names.
	 *
	 * @return string|null The refusal.
	 */
	private function refusal(array $row, string $ownId, string $parentId): ?string {
		if ($ownId !== '' && $ownId === $parentId) {
			return 'The item was not saved: it cannot be a track of itself.';
		}

		try {
			$parent = $this->parent(parentId: $parentId);
			if ($parent === null) {
				return null;
			}

			$grandParentId = self::reference(value: ($parent['parentItem'] ?? null));
			if ($grandParentId !== '') {
				return sprintf(
					'The item was not saved: "%s" is itself a track, so it cannot have tracks. Add this track to its topic instead.',
					self::subjectOf(item: $parent)
				);
			}

			if ($ownId !== '' && $this->hasTracks(itemId: $ownId) === true) {
				return sprintf(
					'The item was not saved: "%s" has tracks of its own, so it cannot become a track of "%s".',
					self::subjectOf(item: $row),
					self::subjectOf(item: $parent)
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Decidiq: the tracks of a long-term agenda item could not be checked', context: ['error' => $e->getMessage()]);
			return 'The item was not saved: its topic could not be checked. Try again in a moment.';
		}//end try

		return null;
	}//end refusal()

	/**
	 * The topic an item names, or null when it does not exist (the reference check answers that).
	 *
	 * @param string $parentId The topic's id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parent(string $parentId): ?array {
		try {
			$entity = $this->objectService->find(id: $parentId, register: 'decidiq', schema: self::SCHEMA, _rbac: false);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array) $entity->jsonSerialize();
	}//end parent()

	/**
	 * Whether any item names this one as its topic.
	 *
	 * @param string $itemId The item.
	 *
	 * @return bool
	 */
	private function hasTracks(string $itemId): bool {
		$rows = $this->objectService->findAll(
			[
				'filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA, 'parentItem' => $itemId],
				'limit' => 1,
			]
		);

		return $rows !== [];
	}//end hasTracks()

	/**
	 * The object an event carries: the new object on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return object|null
	 */
	private function eventObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end eventObject()

	/**
	 * Whether the row clearly names a schema other than the planned agenda item.
	 *
	 * @param array<string, mixed> $row The row being saved.
	 *
	 * @return bool
	 */
	private function isAnotherSchema(array $row): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return strtolower($candidate) !== self::SCHEMA;
			}
		}

		return false;
	}//end isAnotherSchema()

	/**
	 * An item's subject, for a refusal a person reads.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return string
	 */
	private static function subjectOf(array $item): string {
		$subject = trim((string) ($item['subject'] ?? ''));
		if ($subject === '') {
			return 'this item';
		}

		return $subject;
	}//end subjectOf()

	/**
	 * A reference as a uuid string: a plain id, or an object carrying one.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The id, or '' when there is none.
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
