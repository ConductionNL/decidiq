<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\TaskService.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399): every signature below is copied
 * from production, ConductionNL/openregister@development (commit 70e5570),
 * lib/Service/TaskService.php:
 *
 *   getAllUserTasks() line 174
 *   createTask()      line 424
 *   updateTask()      line 463
 *   deleteTask()      line 545
 *
 * Only the four methods decidiq calls are declared. This file is loaded by
 * tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stand-in for OpenRegister's CalDAV task service; tests mock it.
 */
class TaskService {
	/**
	 * The acting user's tasks.
	 *
	 * @param string|null $status A status filter.
	 * @param int         $limit  At most this many.
	 * @param int         $offset Skip this many.
	 *
	 * @return array<string, mixed>
	 */
	public function getAllUserTasks(
		?string $status = null,
		int $limit = 50,
		int $offset = 0,
	): array {
		return ['results' => [], 'total' => 0];
	}

	/**
	 * Create a VTODO linked to an object.
	 *
	 * @param int                  $registerId  The register.
	 * @param int                  $schemaId    The schema.
	 * @param string               $objectUuid  The object.
	 * @param string               $objectTitle Its title.
	 * @param array<string, mixed> $data        The task data.
	 *
	 * @return array<string, mixed>|null
	 */
	public function createTask(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): ?array {
		return null;
	}

	/**
	 * Update a VTODO.
	 *
	 * @param string               $calendarId The calendar.
	 * @param string               $taskUri    The task.
	 * @param array<string, mixed> $data       The changes.
	 *
	 * @return array<string, mixed>|null
	 */
	public function updateTask(string $calendarId, string $taskUri, array $data): ?array {
		return null;
	}

	/**
	 * Delete a VTODO.
	 *
	 * @param string $calendarId The calendar.
	 * @param string $taskUri    The task.
	 *
	 * @return void
	 */
	public function deleteTask(string $calendarId, string $taskUri): void {
	}
}
