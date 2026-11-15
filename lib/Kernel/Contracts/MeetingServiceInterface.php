<?php

/**
 * Contract of MeetingService
 *
 * The public surface of MeetingService that other components call, so they depend
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
 * Contract of MeetingService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingServiceInterface {

	/**
	 * Tell a caller the meeting's stage and the steps they may take from it.
	 *
	 * Runs the same state machine, domain rules and chair-only gate that
	 * transition() runs, so a stage button shows exactly when the server would
	 * accept the step. Quorum is left to the step itself: it can change between
	 * reading the page and pressing the button, and its refusal says why.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId    Nextcloud UID of the caller
	 *
	 * @return array{lifecycle: string, actions: string[]}|null Null when the caller cannot read the meeting
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function availableActionsFor(string $meetingId, string $userId): ?array;

	/**
	 * Apply a lifecycle transition to a meeting object.
	 *
	 * Validates that `$action` is a known transition, enforces domain-specific
	 * workflow rules (WorkflowService), chair-only authorization (OWASP A01:2021),
	 * and quorum requirements (MeetingTransitionGuard) before patching the object via
	 * OpenRegister's ObjectService.
	 *
	 * @param string $meetingId UUID of the meeting to transition
	 * @param string $action Transition action: schedule|open|pause|resume|adjourn|close
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (used for chair-only gates)
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-meeting-management/tasks.md#task-1.1
	 * @spec openspec/changes/archive/2026-05-11-p2-meeting-management-core-t1/tasks.md#task-2.2
	 * @spec openspec/changes/archive/2026-05-11-p2-meeting-management-core-t1/tasks.md#task-3.3
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{success: bool, meeting: array|null, message: string}
	 */
	public function transition(string $meetingId, string $action, ?string $currentUserId = null): array;
}//end interface
