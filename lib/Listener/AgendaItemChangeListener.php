<?php

/**
 * Decidiq Agenda Item Change Listener
 *
 * Tells members when the agenda of a published meeting changes: an agenda
 * item created, withdrawn, renamed, moved or re-described on a meeting whose
 * agenda was published hands the meeting to AgendaService::notifyAgendaChanged(),
 * which records a new agenda version and notifies the active participants
 * (#1396). Progress during the meeting (durations, the BOB phase) is not an
 * agenda change and is ignored. Fail-soft: a notice never breaks the write.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Reports agenda item changes on a published agenda to AgendaService.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */
class AgendaItemChangeListener implements IEventListener {

	/**
	 * Schema slug of an agenda item.
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * The fields members read an agenda by. A change to any other field is
	 * progress, not an agenda change.
	 *
	 * @var string[]
	 */
	private const AGENDA_FIELDS = ['title', 'description', 'orderNumber', 'meeting', 'parentItem', 'itemType', 'type'];

	/**
	 * Constructor.
	 *
	 * @param AgendaService          $agendaService  Records the version and notifies
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug
	 * @param LoggerInterface        $logger         Logger
	 */
	public function __construct(
		private readonly AgendaService $agendaService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an agenda item create, update or delete.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
			&& ($event instanceof ObjectDeletedEvent) === false
		) {
			return;
		}

		try {
			$meetingId = $this->changedMeeting(event: $event);
			if ($meetingId !== '') {
				$this->agendaService->notifyAgendaChanged(meetingId: $meetingId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: agenda change notice failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The meeting whose agenda this event changed, or '' when it changed none.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent $event The event
	 *
	 * @return string
	 */
	private function changedMeeting(ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent $event): string {
		$entity = $event->getObject();
		if ($entity === null) {
			return '';
		}

		$row = $this->extractRow(entity: $entity);
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_AGENDA_ITEM, row: $row) === false
			|| $this->agendaService->isSuppressingItemNotices() === true
		) {
			return '';
		}

		if ($event instanceof ObjectUpdatedEvent && $this->changesTheAgenda(event: $event, row: $row) === false) {
			return '';
		}

		return (string)($row['meeting'] ?? '');
	}//end changedMeeting()

	/**
	 * Whether an update touched a field members read the agenda by.
	 *
	 * @param ObjectUpdatedEvent   $event The update
	 * @param array<string, mixed> $row   The new payload
	 *
	 * @return boolean
	 */
	private function changesTheAgenda(ObjectUpdatedEvent $event, array $row): bool {
		$old = $event->getOldObject();
		if ($old === null) {
			return true;
		}

		$oldRow = $this->extractRow(entity: $old);
		foreach (self::AGENDA_FIELDS as $field) {
			if (json_encode($oldRow[$field] ?? null) !== json_encode($row[$field] ?? null)) {
				return true;
			}
		}

		return false;
	}//end changesTheAgenda()

	/**
	 * Extract the stored payload from an OR object entity.
	 *
	 * @param object $entity OR object entity
	 *
	 * @return array<string, mixed>
	 */
	private function extractRow(object $entity): array {
		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
			$row = (array)$entity->jsonSerialize();
		}

		return $row;
	}//end extractRow()
}//end class
