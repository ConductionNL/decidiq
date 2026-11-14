<?php

/**
 * Decidiq TeamsMeetingServiceInterface
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Exception\TeamsMeetingRefusedException;

/**
 * Contract of TeamsMeetingService.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamsMeetingServiceInterface {

	/**
	 * Whether Microsoft 365 is connected, and the meeting's Teams meeting.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting does not exist (404).
	 *
	 * @return array{connected: bool, teamsMeeting: array<string, mixed>|null}
	 */
	public function status(string $meetingId): array;

	/**
	 * Create the Teams meeting for a digital or hybrid meeting and store it on the meeting.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting cannot carry one (404, 409) or the service refuses (502).
	 *
	 * @return array<string, mixed> The stored teamsMeeting.
	 */
	public function create(string $meetingId): array;
}//end interface
