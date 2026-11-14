<?php

/**
 * Contract of ALVMinutesService
 *
 * The public surface of ALVMinutesService that other components call, so they depend
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

use Exception;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of ALVMinutesService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ALVMinutesServiceInterface {

	/**
	 * Send approved minutes to the members of the meeting's body.
	 *
	 * The minutes must be approved, signed or published. Every current member
	 * (no leftAt) with a Nextcloud account gets the notice "The minutes of
	 * <meeting> are available", linking the minutes, through their own
	 * notification preferences (bell, email or both; event decisionPublished).
	 * The members come from ParticipantResolver, which reads the body relation
	 * in both shapes OpenRegister stores it (minutes-draft-and-send, min-07).
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return int The count of notices delivered
	 *
	 * @throws MissingObjectException If Minutes not found
	 * @throws Exception If lifecycle is not approved, signed or published (403)
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members
	 */
	public function distribute(string $minutesId): int;

	/**
	 * Generate an ALV draft based on the Minutes and linked Meeting.
	 *
	 * Fetches the Minutes and linked Meeting, validates that meetingType
	 * contains 'alv', fetches AgendaItems and active Participants of the
	 * linked GovernanceBody, and renders the ALV Dutch template.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array{
	 *   content: string,
	 *   recipientCount: int
	 * } Generated content and recipient count
	 *
	 * @throws MissingObjectException If Minutes or Meeting not found
	 * @throws Exception If meeting is not ALV type
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function generateALVDraft(string $minutesId): array;
}//end interface
