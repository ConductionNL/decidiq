<?php

/**
 * Contract of MandateSubstitutionService
 *
 * The public surface of MandateSubstitutionService that other components call, so they depend
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

use OCA\Decidiq\Exception\SubstitutionRefusedException;

/**
 * Contract of MandateSubstitutionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandateSubstitutionServiceInterface {

	/**
	 * End a substitution: the seat goes back to the member, and the record stays.
	 *
	 * @param string $meetingId      The meeting
	 * @param string $substitutionId The substitution
	 * @param string $userId         The caller
	 *
	 * @return array<string, mixed> The stored substitution, with its id
	 *
	 * @throws SubstitutionRefusedException 403 not presiding, 404 no such substitution in the meeting, 409 a vote is open or it has ended
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
	 */
	public function end(string $meetingId, string $substitutionId, string $userId): array;

	/**
	 * The meeting's participants with their seats, every substitution of the
	 * meeting, and whether the caller may swap.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The caller
	 *
	 * @return array{participants: list<array<string, mixed>>, substitutions: list<array<string, mixed>>, canSubstitute: bool}
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
	 */
	public function seats(string $meetingId, string $userId): array;

	/**
	 * Swap a member for a substitute.
	 *
	 * @param string $meetingId     The meeting
	 * @param string $outgoingId    The member who leaves
	 * @param string $incomingId    The substitute who takes the seat
	 * @param string $reason        Why the member leaves
	 * @param string $userId        The caller, recorded as `recordedBy`
	 *
	 * @return array<string, mixed> The stored substitution, with its id
	 *
	 * @throws SubstitutionRefusedException 403 not presiding, 409 a vote is open or a seat is taken, 400 the seat plan does not allow it
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	public function start(string $meetingId, string $outgoingId, string $incomingId, string $reason, string $userId): array;
}//end interface
