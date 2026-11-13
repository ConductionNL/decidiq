<?php

/**
 * Contract of GovernanceRoleScopeProjector
 *
 * The public surface of GovernanceRoleScopeProjector that other components call, so they depend
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
 * Contract of GovernanceRoleScopeProjector.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernanceRoleScopeProjectorInterface {

	/**
	 * Reconcile every GovernanceBody (backfill). Idempotent.
	 *
	 * @return int Number of bodies reconciled
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function reconcileAll(): int;

	/**
	 * Reconcile the body referenced by a Participant/Membership row.
	 *
	 * @param array<string, mixed> $row Serialised Participant/Membership object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function reconcileFromMemberRow(array $row): void;

	/**
	 * Remove both scope groups of a body that no longer exists.
	 *
	 * A deleted body has no roster, so it has no scope; its groups would
	 * otherwise outlive it (an example set's remove left four behind).
	 *
	 * @param string $bodyId GovernanceBody UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-a-body-scope-group-exists-only-while-it-has-members-req-rbac-009
	 */
	public function removeBody(string $bodyId): void;
}//end interface
