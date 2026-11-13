<?php

/**
 * Contract of ActionItemWriter
 *
 * The public surface of ActionItemWriter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Contract of ActionItemWriter.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActionItemWriterInterface {

	/**
	 * Create an action-item VTODO from an action-item payload.
	 *
	 * @param array<string, mixed> $item The action-item fields (title, assignee,
	 *                                   dueDate, taskStatus, description, plus any
	 *                                   source relations to round-trip).
	 *
	 * @return array<string, mixed>|null The created VTODO task array, or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-06-23-action-items-vtodo-deck-reconcile/tasks.md#task-1.1
	 */
	public function create(array $item): ?array;

	/**
	 * Delete an action-item VTODO (located by its VTODO UID).
	 *
	 * @param string $uid The VTODO UID of the action item.
	 *
	 * @return bool True when deleted, false when not found/failed.
	 *
	 * @spec openspec/changes/archive/2026-06-23-action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function delete(string $uid): bool;

	/**
	 * Update an action-item VTODO (located by its VTODO UID).
	 *
	 * @param string $uid The VTODO UID of the action item.
	 * @param array<string, mixed> $changes The fields to change.
	 *
	 * @return array<string, mixed>|null The updated VTODO task array, or null when not found/failed.
	 *
	 * @spec openspec/changes/archive/2026-06-23-action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	public function update(string $uid, array $changes): ?array;
}//end interface
