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
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closes papers of a non-public agenda item, and an item whose restriction is imposed.
 *
 * - Before a paper is saved: a paper of a non-public item is saved with public false.
 * - After an item is saved non-public: every paper of it that is still public is closed.
 * - After a restriction with scope item reaches imposed: its item is closed, papers too.
 *   Lifting (ratified, dissolved) changes nothing; reopening is the griffier's choice.
 *
 * @implements IEventListener<Event>
 */
class AgendaItemConfidentialityListener implements IEventListener {

	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	public const SCHEMA_DOCUMENT = 'digital-document';

	public const SCHEMA_RESTRICTION = 'confidentiality-restriction';

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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 */
	private function dispatch(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private function beforePaperSave(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$entity = self::savedEntity(event: $event);
		if ($entity === null) {
			return;
		}

		$paper = array_merge((array)$entity->getObject(), $event->getModifiedData());
		if ($this->isPublicPaper(entity: $entity, paper: $paper) === false) {
			return;
		}

		if ($this->onClosedItem(paper: $paper) === false) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['public' => false]));
	}//end beforePaperSave()

	/**
	 * The object a before-save event is about to store.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return object|null The entity, or null when the event carries none.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private static function savedEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end savedEntity()

	/**
	 * Whether the saved object is a paper that is still marked public.
	 *
	 * @param object              $entity The entity being saved.
	 * @param array<string,mixed> $paper  Its data with the pending changes.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private function isPublicPaper(object $entity, array $paper): bool {
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_DOCUMENT, row: $paper) === false) {
			return false;
		}

		return ($paper['public'] ?? true) !== false;
	}//end isPublicPaper()

	/**
	 * Whether the paper is on an agenda item that is not public.
	 *
	 * @param array<string,mixed> $paper The paper's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-a-paper-added-later
	 */
	private function onClosedItem(array $paper): bool {
		$itemId = self::referenceId(value: ($paper['agendaItem'] ?? null));
		if ($itemId === null) {
			return false;
		}

		return $this->isPublic(item: $this->load(id: $itemId, schema: self::SCHEMA_AGENDA_ITEM)) === false;
	}//end onClosedItem()

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
				$this->closePapers(itemId: (string)($entity->getUuid() ?? ($row['id'] ?? '')));
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
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
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-geheimhouding-imposed-by-the-college
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
		$this->closePapers(itemId: $itemId);
	}//end closeItem()

	/**
	 * Set public to false on every paper of the item that is still public.
	 *
	 * @param string $itemId The agenda item id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 */
	private function closePapers(string $itemId): void {
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
			if (($data['public'] ?? true) === false) {
				continue;
			}

			$uuid = (string)($paper->getUuid() ?? ($data['id'] ?? ''));
			$this->save(object: array_merge($data, ['public' => false]), schema: self::SCHEMA_DOCUMENT, uuid: $uuid);
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
			register: 'decidiq',
			schema: $schema,
			uuid: $uuid,
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
		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
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
