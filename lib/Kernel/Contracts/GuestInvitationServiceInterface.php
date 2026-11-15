<?php

/**
 * Contract of GuestInvitationService
 *
 * The public surface of GuestInvitationService that other components call, so they depend
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

use OCA\Decidiq\Exception\GuestInvitationRefusedException;

/**
 * Contract of GuestInvitationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuestInvitationServiceInterface {

	/**
	 * Invite a guest: add them to the meeting and send one invitation mail.
	 *
	 * @param string $meetingId The meeting
	 * @param string $name      The guest's name (the email when empty)
	 * @param string $email     The guest's email
	 * @param string $userId    The caller
	 *
	 * @return array{participant: string, attendance: string, mailed: bool}
	 *
	 * @throws GuestInvitationRefusedException When the invitation cannot be sent as asked
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function invite(string $meetingId, string $name, string $email, string $userId): array;

	/**
	 * Refuse anyone but the meeting's organiser or a decidiq administrator.
	 *
	 * The meeting is read with the caller's own rights, so a meeting the
	 * caller cannot see answers 404 before any role is checked.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The caller
	 *
	 * @return array<string, mixed> The meeting
	 *
	 * @throws GuestInvitationRefusedException 404 when absent, 403 when not the organiser
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function requireOrganiserOf(string $meetingId, string $userId): array;
}//end interface
