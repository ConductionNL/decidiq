<?php

/**
 * Decidiq Agenda Item Confidentiality Listener
 *
 * Keeps a non-public agenda item's papers non-public (REQ-ACP-003) and closes
 * an item when a confidentiality restriction on it is imposed (REQ-ACP-004),
 * change agenda-item-confidential-papers. The read rules then hold on each
 * object without a join.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closes papers of a non-public agenda item, and an item whose restriction is imposed.
 *
 * - Before an item is saved: it carries its meeting's governance body, so the read rule
 *   of a non-public item admits the body's members (REQ-ACP-002).
 * - Before a paper is saved: a paper of a non-public item is saved with public false, and
 *   every paper of an item carries the item's governance body and authorised readers.
 * - After an item is saved non-public: every paper of it is closed and carries the item's
 *   body and readers.
 * - After a restriction with scope item reaches imposed: its item is closed, papers too.
 *   Lifting (ratified, dissolved) changes nothing; reopening is the griffier's choice.
 *
 * @implements IEventListener<Event>
 */
class AgendaItemConfidentialityListener implements IEventListener {

	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	public const SCHEMA_DOCUMENT = 'digital-document';

	public const SCHEMA_RESTRICTION = 'confidentiality-restriction';

	public const SCHEMA_MEETING = 'meeting';

	/**
	 * The most papers closed for one item in one pass.
	 *
	 * @var integer
	 */
	private const PAPER_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  Reads and writes the item and its papers.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells which schema an event's object has.
	 * @param LoggerInterface                 $logger         Logs a write that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a save of a paper, an agenda item or a confidentiality restriction.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
	 */
	public function handle(Event $event): void {
		try {
			$this->dispatch(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: agenda item confidentiality not applied', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Route a before-save event to the paper check and an after-save event to the closing.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 */
	private function dispatch(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
			$this->beforeItemSave(event: $event);
			$this->beforePaperSave(event: $event);
			return;
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) {
			$this->afterSave(event: $event);
		}
	}//end dispatch()

	/**
	 * A paper saved onto a non-public item is saved as not public.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private function beforePaperSave(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$entity = self::savedEntity(event: $event);
		if ($entity === null) {
			return;
		}

		$paper = array_merge((array)$entity->getObject(), $event->getModifiedData());
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_DOCUMENT, row: $paper) === false) {
			return;
		}

		$itemId = self::referenceId(value: ($paper['agendaItem'] ?? null));
		if ($itemId === null) {
			return;
		}

		$changes = $this->paperChanges(paper: $paper, itemId: $itemId);
		if ($changes !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $changes));
		}
	}//end beforePaperSave()

	/**
	 * What a paper of an item must change: closed when the item is not public, and the item's body and readers.
	 *
	 * @param array<string,mixed> $paper  The paper with its pending changes.
	 * @param string              $itemId Its agenda item.
	 *
	 * @return array<string,mixed> The fields to set, empty when the paper is right.
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private function paperChanges(array $paper, string $itemId): array {
		// An item that cannot be read closes the paper: a confidential item
		// must not leak a paper because the lookup failed.
		try {
			$item = $this->load(id: $itemId, schema: self::SCHEMA_AGENDA_ITEM);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: agenda item of a paper could not be read; the paper is saved closed', ['exception' => $e->getMessage()]);
			return (new ClosedItemAudience())->closing(paper: $paper);
		}

		$changes = (new ClosedItemAudience())->audience(row: $paper, item: ($item ?? []));
		if ($this->isPublic(item: $item) === false) {
			$changes += (new ClosedItemAudience())->closing(paper: $paper);
		}

		return $changes;
	}//end paperChanges()

	/**
	 * An agenda item carries its meeting's governance body, so its read rule can admit the body's members.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	private function beforeItemSave(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$entity = self::savedEntity(event: $event);
		if ($entity === null) {
			return;
		}

		$item = array_merge((array)$entity->getObject(), $event->getModifiedData());
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_AGENDA_ITEM, row: $item) === false) {
			return;
		}

		$meetingId = self::referenceId(value: ($item['meeting'] ?? null));
		if ($meetingId === null) {
			return;
		}

		$meeting = $this->load(id: $meetingId, schema: self::SCHEMA_MEETING);
		$body = self::referenceId(value: ($meeting['governanceBody'] ?? null));
		if ($body !== null && self::referenceId(value: ($item['governanceBody'] ?? null)) !== $body) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['governanceBody' => $body]));
		}
	}//end beforeItemSave()

	/**
	 * The object a before-save event is about to store.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return object|null The entity, or null when the event carries none.
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private static function savedEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end savedEntity()

	/**
	 * After an item or a restriction is saved, close what must be closed.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The after-save event.
	 *
	 * @return void
	 */
	private function afterSave(ObjectCreatedEvent|ObjectUpdatedEvent $event): void {
		$entity = $event->getObject();
		if ($entity === null) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_AGENDA_ITEM, row: $row) === true) {
			if ($this->isPublic(item: $row) === false) {
				$this->closePapers(itemId: (string)($entity->getUuid() ?? ($row['id'] ?? '')), item: $row);
			}

			return;
		}

		$this->afterRestrictionSave(event: $event, entity: $entity, row: $row);
	}//end afterSave()

	/**
	 * A confidentiality restriction that became imposed closes its agenda item.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event  The after-save event.
	 * @param object                                $entity The saved entity.
	 * @param array<string,mixed>                   $row    Its data as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
	 */
	private function afterRestrictionSave(ObjectCreatedEvent|ObjectUpdatedEvent $event, object $entity, array $row): void {
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_RESTRICTION, row: $row) === false) {
			return;
		}

		if ($this->becameImposed(event: $event, row: $row) === true) {
			$this->closeItem(itemId: self::referenceId(value: ($row['targetAgendaItem'] ?? null)));
		}
	}//end afterRestrictionSave()

	/**
	 * Whether this save brought an item-scoped restriction into the state imposed.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The after-save event.
	 * @param array<string,mixed>                   $row   The restriction as saved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
	 */
	private function becameImposed(ObjectCreatedEvent|ObjectUpdatedEvent $event, array $row): bool {
		if (self::isImposedOnItem(row: $row) === false) {
			return false;
		}

		$old = self::previous(event: $event);
		if ($old === null) {
			return true;
		}

		return self::isImposedOnItem(row: (new EntityPayloadReader())->payloadOf(entity: $old)) === false;
	}//end becameImposed()

	/**
	 * Whether a restriction is item-scoped and imposed.
	 *
	 * @param array<string,mixed> $row The restriction.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
	 */
	private static function isImposedOnItem(array $row): bool {
		return ($row['scope'] ?? null) === 'item' && ($row['lifecycle'] ?? null) === 'imposed';
	}//end isImposedOnItem()

	/**
	 * The object as it was before an update, or null for a create.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The after-save event.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
	 */
	private static function previous(ObjectCreatedEvent|ObjectUpdatedEvent $event): ?object {
		if ($event instanceof ObjectUpdatedEvent) {
			return $event->getOldObject();
		}

		return null;
	}//end previous()

	/**
	 * Set an item's public to false, and close its papers.
	 *
	 * @param string|null $itemId The agenda item id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-geheimhouding-imposed-by-the-college
	 */
	private function closeItem(?string $itemId): void {
		if ($itemId === null) {
			return;
		}

		$item = $this->load(id: $itemId, schema: self::SCHEMA_AGENDA_ITEM);
		if ($item === null) {
			return;
		}

		if ($this->isPublic(item: $item) === true) {
			$this->save(object: array_merge($item, ['public' => false]), schema: self::SCHEMA_AGENDA_ITEM, uuid: $itemId);
		}

		// Directly as well, so the papers close even when the item's own save event is not delivered.
		$this->closePapers(itemId: $itemId, item: $item);
	}//end closeItem()

	/**
	 * Close every paper of a non-public item and give it the item's body and readers.
	 *
	 * @param string              $itemId The agenda item id.
	 * @param array<string,mixed> $item   The agenda item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	private function closePapers(string $itemId, array $item): void {
		if ($itemId === '') {
			return;
		}

		$papers = $this->objectService->findAll(
			config: [
				'filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA_DOCUMENT, 'agendaItem' => $itemId],
				'limit'   => self::PAPER_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($papers as $paper) {
			$data = (new EntityPayloadReader())->payloadOf(entity: $paper);
			$changes = (new ClosedItemAudience())->closing(paper: $data) + (new ClosedItemAudience())->audience(row: $data, item: $item);
			if ($changes === []) {
				continue;
			}

			$uuid = (string)($paper->getUuid() ?? ($data['id'] ?? ''));
			$this->save(object: array_merge($data, $changes), schema: self::SCHEMA_DOCUMENT, uuid: $uuid);
		}
	}//end closePapers()

	/**
	 * Save an object as the system: closing is an invariant, not the saver's choice.
	 *
	 * @param array<string,mixed> $object The object.
	 * @param string              $schema The schema slug.
	 * @param string              $uuid   The object id.
	 *
	 * @return void
	 */
	private function save(array $object, string $schema, string $uuid): void {
		$this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: 'decidiq', schema: $schema),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Load an object's data, or null when it does not exist.
	 *
	 * @param string $id     The object id.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function load(string $id, string $schema): ?array {
		$entity = $this->objectService->find(
			id: $id,
			options: new ObjectFindOptions(register: 'decidiq', schema: $schema),
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end load()

	/**
	 * Whether an item is public: true unless its public field is false. A missing item counts as public.
	 *
	 * @param array<string,mixed>|null $item The item.
	 *
	 * @return bool
	 */
	private function isPublic(?array $item): bool {
		return $item === null || ($item['public'] ?? true) !== false;
	}//end isPublic()

	/**
	 * The id of a reference, which is a string or an object with id or uuid.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null
	 */
	private static function referenceId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end referenceId()
}//end class
