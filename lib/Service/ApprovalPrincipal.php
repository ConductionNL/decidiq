<?php

/**
 * Approval Principal
 *
 * Who is asking for a route, as far as the route rules care.
 *
 * This is an enum and not a `bool $isAdministrator` on purpose. A boolean flag
 * parameter reads as `instantiate($route, $subject, $schema, false)` at the call
 * site, where `false` says nothing about what it denies, and phpmd flags it
 * (BooleanArgumentFlag) for exactly that reason. `ApprovalPrincipal::Ordinary`
 * says it.
 *
 * It is deliberately NOT a user object. The engine needs one fact, which is
 * whether this principal may declare that a step's silence approves, and giving
 * it a session would let a rule grow that depends on who somebody is rather
 * than on what they may do.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Who is asking for a route.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
 */
enum ApprovalPrincipal: string {
	// Anybody else, including an ordinary signed-in user. The default
	// everywhere, because the fail-closed answer is the one that denies.
	case Ordinary = 'ordinary';

	// An instance administrator, the only principal that may declare that a
	// step's silence approves (REQ-AR-015).
	case Administrator = 'administrator';

	/**
	 * Whether this principal is an administrator.
	 *
	 * @return bool True for an administrator.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function isAdministrator(): bool {
		return ($this === self::Administrator);
	}//end isAdministrator()

}
