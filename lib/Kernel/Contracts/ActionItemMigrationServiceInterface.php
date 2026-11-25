<?php

/**
 * Contract of ActionItemMigrationService
 *
 * The public surface of ActionItemMigrationService that other components call, so they depend
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
 * Contract of ActionItemMigrationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActionItemMigrationServiceInterface {

	/**
	 * Migrate one user's legacy objects; the caller has made that user the acting user.
	 *
	 * @param string $userId The owning user.
	 *
	 * @return array{migrated: int, delegations: int, skipped: int, failed: int}
	 *
	 * @spec openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 * @spec openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-012-delegation-folds-onto-the-vtodo-assignee
	 */
	public function migrateUser(string $userId): array;

	/**
	 * The users who own legacy objects not migrated yet.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	public function pendingOwners(): array;
}//end interface
