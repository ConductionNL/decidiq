<?php

/**
 * Contract of RoleGroupMapping
 *
 * The public surface of RoleGroupMapping that other components call, so they depend
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
 * Contract of RoleGroupMapping.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoleGroupMappingInterface {

	/**
	 * App config key holding the mapping as JSON: {role: [group, ...]}.
	 */
	public const CONFIG_KEY = 'role_group_mapping';

	/**
	 * The roles the register's rules name, with the groups that stand for
	 * each (decidesk-administrators is the pre-rename name, kept in the rules).
	 */
	public const ROLES = [
		'administrators'   => ['decidiq-administrators', 'decidesk-administrators'],
		'secretariat'      => ['decidiq-secretariat'],
		'publication-flow' => ['decidiq-publication-flow'],
	];

	/**
	 * Keep only known roles and non-empty, distinct group names.
	 *
	 * @param array<mixed> $mapping The mapping as stored or posted.
	 *
	 * @return array<string, list<string>> The clean mapping, every role present.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function normalise(array $mapping): array;

	/**
	 * Per record type, who may read, create, change and delete it: the
	 * schema's own rules, or the register's baseline when it has none (the
	 * way OpenRegister applies them).
	 *
	 * @param array<string,mixed> $config The merged, rewritten configuration.
	 *
	 * @return list<array{slug: string, title: string, inherited: bool, rules: array<string, list<array{group: string, conditional: bool}>>}>
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function overview(array $config): array;
}//end interface
