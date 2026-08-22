<?php

/**
 * Decidiq RequiresOrAdmin trait
 *
 * The single shared admin guard for Decidiq's admin-gated controllers,
 * replacing the four copy-pasted private `requireAdmin()` methods
 * (GovernanceReportController, MultilingualReconciliationController,
 * AuditLogController, RegulatorExportController). It consumes the SAME admin
 * determination OpenRegister uses in `PropertyRbacHandler::isAdmin()` —
 * membership of the Nextcloud `admin` group, resolved here via
 * `IGroupManager::isAdmin()` — so the decision is centralised on one
 * implementation rather than duplicated per controller
 * (consume-or-rbac-authorization, REQ-RBAC-004). Fail-closed: an
 * unauthenticated caller gets 401, a non-admin gets 403.
 *
 * Consuming controllers MUST expose `$this->userSession` (IUserSession) and
 * `$this->groupManager` (IGroupManager) — every one of the four already does.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-004-the-duplicated-admin-guards-consume-openregister-s-admin-determination
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Shared admin guard consuming OpenRegister's admin determination.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-004-the-duplicated-admin-guards-consume-openregister-s-admin-determination
 */
trait RequiresOrAdmin {
	/**
	 * Deny the request unless the caller is a Nextcloud admin.
	 *
	 * Returns a 401 JSONResponse when unauthenticated, a 403 when authenticated
	 * but not an admin, or null when the caller is an admin (proceed).
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-004-the-duplicated-admin-guards-consume-openregister-s-admin-determination
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['message' => 'Administrator role required.'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireAdmin()
}//end trait
