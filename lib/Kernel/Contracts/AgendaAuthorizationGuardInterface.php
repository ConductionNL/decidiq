<?php

/**
 * Contract of AgendaAuthorizationGuard
 *
 * The public surface of AgendaAuthorizationGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of AgendaAuthorizationGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgendaAuthorizationGuardInterface {

	/**
	 * Verify the current user is an admin or holds a chair/secretary role for a meeting.
	 *
	 * @param string $meetingId UUID of the meeting to check
	 *
	 * @return JSONResponse|null Null if authorised, 401/403 JSONResponse if not.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireChairOrAdmin(string $meetingId): ?JSONResponse;

	/**
	 * Resolve an agenda item's meeting and authorise the caller against it.
	 *
	 * Returns a 404 when the item does not exist and a 403 when its meeting
	 * cannot be resolved, mirroring the behaviour the agenda endpoints had when
	 * this lived inline in the controller.
	 *
	 * @param string $agendaItemId UUID of the AgendaItem
	 *
	 * @return JSONResponse|null Null if authorised, 401/403/404 JSONResponse if not.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireChairOrAdminForAgendaItem(string $agendaItemId): ?JSONResponse;

	/**
	 * Verify a user is authenticated.
	 *
	 * @return JSONResponse|null Null if authenticated, 401 JSONResponse if not.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireUser(): ?JSONResponse;
}//end interface
