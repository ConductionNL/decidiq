<?php

/**
 * Contract of VotingRoundGuard
 *
 * The public surface of VotingRoundGuard that other components call, so they depend
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
 * Contract of VotingRoundGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingRoundGuardInterface {

	/**
	 * Require the current user to hold the CHAIR role (not secretary) for a meeting.
	 *
	 * Used for the chair's casting vote on a tied round (voting-system spec):
	 * a casting vote is the chair's personal prerogative, so the secretary does
	 * not suffice. Per-meeting check via ParticipantResolver::hasRole(); when the
	 * meeting cannot be resolved, falls back to the existing global
	 * chair_group/admin check. Fail closed: any failure yields a 403.
	 *
	 * @param string|null $meetingId UUID of the meeting to scope the role check (optional)
	 *
	 * @return JSONResponse|null A 401/403 response on failure, null when authorised
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function requireChair(?string $meetingId = null): ?JSONResponse;

	/**
	 * Require the current user to hold the chair/secretary role for a specific meeting.
	 *
	 * When $meetingId is provided, checks via ParticipantResolver::hasRole() that
	 * the caller holds a 'chair' or 'secretary' Participant role in that meeting's
	 * governance body — preventing cross-body privilege escalation in multi-council
	 * deployments.
	 *
	 * Falls back to the global chair_group / admin check when $meetingId is null.
	 *
	 * @param string|null $meetingId UUID of the meeting to scope the role check (optional)
	 *
	 * @return JSONResponse|null A 401/403 response on failure, null when authorised
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.2
	 */
	public function requireChairOrSecretary(?string $meetingId = null): ?JSONResponse;

	/**
	 * Refuse opening a round on a subject outside the request's meeting.
	 *
	 * The chair/secretary check runs on the body's meetingId, so the motion (or
	 * the amendment's parent motion) must belong to that meeting, and a revote
	 * must reference a round on the same subject. Requests the open-round parser
	 * rejects (missing ids, unknown subjectType) and an unknown revote round are
	 * left to their 400. Fail closed: a subject without that meeting, or any
	 * lookup failure, is a 403.
	 *
	 * @param array<string,mixed> $params The open-round request parameters
	 *
	 * @return JSONResponse|null A 403 response when the subject lies outside the meeting, null otherwise
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function requireSubjectInMeeting(array $params): ?JSONResponse;

	/**
	 * Resolve the meeting UUID linked to a voting round via motion relations.
	 *
	 * Handles motion rounds (round → motion → meeting) and amendment rounds
	 * (round → amendment → parent motion → meeting), honouring both the flat
	 * `meeting` property and structured relation entries on the motion.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return string|null The meeting UUID or null if not found
	 */
	public function resolveMeetingIdFromVotingRound(string $votingRoundId): ?string;
}//end interface
