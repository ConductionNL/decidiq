<?php

/**
 * ActionItemWriter — the single write path for Decidesk action items.
 *
 * Action items are CalDAV VTODOs (ADR-002): the VTODO is the authoritative
 * record and the `action-item` schema is a read-only OpenRegister projection
 * over those VTODOs (x-openregister-object-source: caldav-vtodo). Every
 * create/update/delete therefore goes through OpenRegister's TaskService rather
 * than ObjectService::saveObject (which the read-only projection rejects).
 *
 * Each action-item VTODO is X-OPENREGISTER-linked to the `action-item` schema
 * (so the bound-schema scoping in the object-source provider picks it up) and
 * carries its non-core fields (assignee, taskStatus, source relations, …) in
 * the X-OPENREGISTER-DATA field blob so the projection is faithful.
 *
 * NOTE: CalDAV writes require the acting user's calendar, so this service must
 * run in a user-session context (controllers / the MCP tool). Background jobs
 * and repair steps must impersonate a user before calling it.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Write path (create/update/delete) for VTODO-backed action items.
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */
class ActionItemWriter {

	/**
	 * The Decidesk register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'decidesk';

	/**
	 * The action-item schema slug (the read-only VTODO projection).
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'action-item';

	/**
	 * Cached [registerId, schemaId] once resolved.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private ?array $ids = null;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for write failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly TaskService $taskService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Create an action-item VTODO from an action-item payload.
	 *
	 * @param array<string, mixed> $item The action-item fields (title, assignee,
	 *                                   dueDate, taskStatus, description, plus any
	 *                                   source relations to round-trip).
	 *
	 * @return array<string, mixed>|null The created VTODO task array, or null on failure.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	public function create(array $item): ?array {
		try {
			$ids = $this->resolveIds();
			if ($ids === null) {
				return null;
			}

			$title = (string)($item['title'] ?? 'Action item');
			$objectUuid = $this->uuidV4();
			$data = $this->toTaskData(item: $item, title: $title);

			return $this->taskService->createTask(
				registerId: $ids[0],
				schemaId: $ids[1],
				objectUuid: $objectUuid,
				objectTitle: $title,
				data: $data
			);
		} catch (Throwable $e) {
			$this->logger->warning('ActionItemWriter::create failed: ' . $e->getMessage());
			return null;
		}//end try
	}//end create()

	/**
	 * Update an action-item VTODO (located by its VTODO UID).
	 *
	 * @param string $uid The VTODO UID of the action item.
	 * @param array<string, mixed> $changes The fields to change.
	 *
	 * @return array<string, mixed>|null The updated VTODO task array, or null when not found/failed.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function update(string $uid, array $changes): ?array {
		try {
			$located = $this->locate(uid: $uid);
			if ($located === null) {
				return null;
			}

			// Flatten the existing round-tripped non-core fields to the top level
			// so unchanged fields (e.g. assignee) survive, then apply the changes.
			$existingFields = ($located['fields'] ?? []);
			if (is_array($existingFields) === false) {
				$existingFields = [];
			}

			$base = array_merge($located, $existingFields);
			unset($base['fields']);
			$merged = array_merge($base, $changes);
			$data = $this->toTaskData(item: $merged, title: (string)($merged['title'] ?? $merged['summary'] ?? 'Action item'));

			return $this->taskService->updateTask(
				calendarId: (string)$located['calendarId'],
				taskUri: (string)$located['id'],
				data: $data
			);
		} catch (Throwable $e) {
			$this->logger->warning('ActionItemWriter::update failed: ' . $e->getMessage());
			return null;
		}//end try
	}//end update()

	/**
	 * Delete an action-item VTODO (located by its VTODO UID).
	 *
	 * @param string $uid The VTODO UID of the action item.
	 *
	 * @return bool True when deleted, false when not found/failed.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function delete(string $uid): bool {
		try {
			$located = $this->locate(uid: $uid);
			if ($located === null) {
				return false;
			}

			$this->taskService->deleteTask(calendarId: (string)$located['calendarId'], taskUri: (string)$located['id']);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('ActionItemWriter::delete failed: ' . $e->getMessage());
			return false;
		}//end try
	}//end delete()

	/**
	 * Map an action-item payload onto TaskService::createTask `data`.
	 *
	 * Core VTODO fields (summary/status/description/due/priority) map directly;
	 * every other key rides along in the `fields` blob so the projection is
	 * faithful (X-OPENREGISTER-DATA round-trip).
	 *
	 * @param array<string, mixed> $item The action-item payload.
	 * @param string $title The resolved title.
	 *
	 * @return array<string, mixed> The TaskService data array.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	private function toTaskData(array $item, string $title): array {
		$data = [
			'summary' => $title,
			'status' => $this->mapStatus(taskStatus: (string)($item['taskStatus'] ?? 'open')),
		];

		if (empty($item['description']) === false) {
			$data['description'] = (string)$item['description'];
		}

		if (empty($item['dueDate']) === false) {
			$data['due'] = (string)$item['dueDate'];
		}

		// Everything that is not a core VTODO field rides along in the field blob.
		$coreKeys = [
			'title',
			'summary',
			'description',
			'dueDate',
			'due',
			'status',
			'priority',
			'id',
			'uid',
			'calendarId',
			'completed',
			'created',
			'objectUuid',
			'registerId',
			'schemaId',
			'fields',
		];
		$fields = [];
		foreach ($item as $key => $value) {
			if (in_array($key, $coreKeys, true) === false) {
				$fields[$key] = $value;
			}
		}

		if (empty($fields) === false) {
			$data['fields'] = $fields;
		}

		return $data;
	}//end toTaskData()

	/**
	 * Map an action-item taskStatus onto a VTODO STATUS value.
	 *
	 * @param string $taskStatus The action-item status.
	 *
	 * @return string The VTODO STATUS.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	private function mapStatus(string $taskStatus): string {
		$map = [
			'open' => 'NEEDS-ACTION',
			'in-progress' => 'IN-PROCESS',
			'in_progress' => 'IN-PROCESS',
			'completed' => 'COMPLETED',
			'done' => 'COMPLETED',
			'cancelled' => 'CANCELLED',
		];

		return ($map[strtolower($taskStatus)] ?? 'NEEDS-ACTION');
	}//end mapStatus()

	/**
	 * Locate a VTODO task array by its UID among the acting user's tasks.
	 *
	 * @param string $uid The VTODO UID.
	 *
	 * @return array<string, mixed>|null The task array (with calendarId + id/uri), or null.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	private function locate(string $uid): ?array {
		$result = $this->taskService->getAllUserTasks(limit: 1000);
		foreach (($result['results'] ?? []) as $task) {
			if ((string)($task['uid'] ?? '') === $uid || (string)($task['id'] ?? '') === $uid) {
				return $task;
			}
		}

		return null;
	}//end locate()

	/**
	 * Resolve the numeric Decidesk register id and action-item schema id.
	 *
	 * @return array{0: int, 1: int}|null [registerId, schemaId], or null when unresolvable.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	private function resolveIds(): ?array {
		if ($this->ids !== null) {
			return $this->ids;
		}

		try {
			$register = $this->registerMapper->find(self::REGISTER_SLUG);
			$schema = $this->schemaMapper->find(self::SCHEMA_SLUG);

			$this->ids = [(int)$register->getId(), (int)$schema->getId()];
			return $this->ids;
		} catch (Throwable $e) {
			$this->logger->error('ActionItemWriter: could not resolve register/schema ids: ' . $e->getMessage());
			return null;
		}//end try
	}//end resolveIds()

	/**
	 * Generate a random UUID v4 for the VTODO's X-OPENREGISTER-OBJECT link.
	 *
	 * @return string A UUID v4 string.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	private function uuidV4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuidV4()
}//end class
