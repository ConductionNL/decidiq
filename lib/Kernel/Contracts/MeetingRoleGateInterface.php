<?php

/**
 * Contract of MeetingRoleGate
 *
 * The public surface of MeetingRoleGate that other components call, so they depend
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
 * Contract of MeetingRoleGate.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingRoleGateInterface {

	/**
	 * True when the actor is chair or secretary of the meeting, or an NC admin.
	 *
	 * The admin fallback is evaluated first so a system administrator never
	 * triggers a meeting-roster lookup; any other actor must hold one of the
	 * presiding roles on that specific meeting (fails closed).
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId Nextcloud UID of the actor
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return bool True when the actor may perform chair/secretary actions
	 */
	public function isChairOrSecretary(string $meetingId, string $userId): bool;

	/**
	 * Say which presiding roles the user holds on one meeting.
	 *
	 * Reads the same ParticipantResolver::hasRole() and IGroupManager::isAdmin()
	 * that isChairOrSecretary() and AgendaAuthorizationGuard::requireChairOrAdmin()
	 * use, so a page that shows a control from this answer shows it exactly when
	 * the server would accept the call behind it.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId    Nextcloud user id of the caller
	 *
	 * @return array{chair: bool, secretary: bool, admin: bool}
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-amp-001-the-meeting-page-asks-the-server-for-the-callers-meeting-roles
	 */
	public function rolesOf(string $meetingId, string $userId): array;
}//end interface
