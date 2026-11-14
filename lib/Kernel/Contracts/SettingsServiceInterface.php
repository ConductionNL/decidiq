<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
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
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * The Minutes, Decision, and ActionItem schema/register slugs are registered
	 * directly in src/store/store.js::OBJECT_TYPES (alongside all other entity types)
	 * and do not require additional settings keys — the frontend resolves them from the
	 * static OBJECT_TYPES map after confirming OpenRegister is available.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-3.1
	 *
	 * @return array<string,mixed>
	 */
	public function getSettings(): array;

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool;

	/**
	 * Load configuration from decidesk_register.json via OpenRegister.
	 *
	 * Import is skipped when the register is already configured at the same
	 * version/fragment signature. Use {@see reloadConfiguration()} to force a
	 * re-import.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function loadConfiguration(): array;

	/**
	 * The register as the import sends it: base, fragments and the role
	 * mapping applied. Empty when the base file cannot be read.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 *
	 * @return array<string,mixed>
	 */
	public function mergedRegisterConfig(): array;

	/**
	 * Re-import configuration from decidesk_register.json, ignoring the
	 * already-configured check.
	 *
	 * The forcing counterpart of {@see loadConfiguration()}; both delegate to
	 * the same import routine.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function reloadConfiguration(): array;

	/**
	 * The groups an administrator added to each decidiq role.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 *
	 * @return array<string, list<string>> Role => group ids, every role present.
	 */
	public function roleGroupMapping(): array;

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.2
	 *
	 * @return array<string,mixed> The updated settings
	 */
	public function updateSettings(array $data): array;
}//end interface
