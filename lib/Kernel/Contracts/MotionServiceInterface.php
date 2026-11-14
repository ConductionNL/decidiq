<?php

/**
 * Contract of MotionService
 *
 * The public surface of MotionService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of MotionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MotionServiceInterface {

	/**
	 * Append a co-signer display name to a Motion's coSigners array.
	 *
	 * Idempotent: if the name is already present, no duplicate is added.
	 * Saves the updated Motion via ObjectService.
	 *
	 * @param string $motionId UUID of the Motion
	 * @param string $coSignerName Display name of the confirming co-signer
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
	 *
	 * @return void
	 */
	public function addCoSigner(string $motionId, string $coSignerName): void;

	/**
	 * Forward a motion to a target governance body with optional approval workflow.
	 *
	 * Checks the actor's role against the motion_forwarding_roles config. Creates a new
	 * Motion in the target body and stores a relation between the forwarded and source
	 * motions. If approval is required, the forwarded Motion is created with lifecycle
	 * 'proposed' (ADR-005 Decision vocabulary) and a notification is sent to the target chair.
	 *
	 * @param string $motionId The motion UUID to forward
	 * @param string $targetBodyId The target governance body UUID
	 * @param string $actorId The Nextcloud user ID of the person forwarding
	 * @param string $justification The reason for forwarding
	 *
	 * @return array<string,mixed> The created forwarded Motion object
	 *
	 * @throws RuntimeException When role is not authorized or motion is not found
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting-core-t2/tasks.md#task-3
	 */
	public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array;

	/**
	 * Check whether a Nextcloud user was invited to co-sign a Motion.
	 *
	 * Returns true when the user's UID appears in the motion's pendingCoSignerUids list.
	 *
	 * @param string $motionId UUID of the Motion
	 * @param string $nextcloudUid The Nextcloud user ID to verify
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
	 *
	 * @return bool True when the user was invited
	 */
	public function isPendingCoSigner(string $motionId, string $nextcloudUid): bool;

	/**
	 * Send co-signature request notifications to listed Participants.
	 *
	 * For each participant ID, fetches the Participant object and sends a
	 * Nextcloud notification with the motion title and a deep link.
	 *
	 * @param string $motionId UUID of the Motion
	 * @param array<string> $participantIds Array of Participant UUIDs to notify
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
	 *
	 * @return void
	 */
	public function requestCoSignature(string $motionId, array $participantIds): void;

	/**
	 * Resolve the meeting UUID linked to a motion.
	 *
	 * Honours BOTH link shapes: the flat `meeting` property (what the UI and
	 * the Newman fixtures write) and the structured `relations` entry.
	 * Returns null when the motion, or any meeting link on it, cannot be
	 * resolved — callers treat that as "no meeting context".
	 *
	 * @param string $motionId The motion UUID
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return string|null The meeting UUID or null if not found
	 */
	public function resolveMeetingId(string $motionId): ?string;

	/**
	 * Create or update a structured "Budget impact" note on a Motion.
	 *
	 * Stores budget line reference, amount delta, and rationale as a JSON
	 * body in a note with title "Budget impact" using the OpenRegister
	 * built-in notes mechanism.
	 *
	 * @param string $motionId UUID of the Motion
	 * @param string $budgetLine Budget line reference (e.g. "Programma 4 - Jeugdzorg")
	 * @param float $amountDelta Amount change in EUR (positive = increase, negative = decrease)
	 * @param string $rationale Policy rationale for the budget change
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
	 *
	 * @return void
	 */
	public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void;

	/**
	 * Stamp the parliamentary voting order on a motion's amendments.
	 *
	 * @param string $motionId UUID of the parent Motion
	 * @param array<string> $orderedAmendmentIds The amendment UUIDs in voting order
	 * @param string $actorId The Nextcloud user ID of the chair
	 *
	 * @return array<int, array<string, mixed>> The reordered amendments
	 *
	 * @throws InvalidArgumentException When an id does not belong to the motion
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function setAmendmentVotingOrder(string $motionId, array $orderedAmendmentIds, string $actorId): array;

	/**
	 * Transition a motion- or amendment-typed Decision to a new lifecycle state.
	 *
	 * Delegates to MotionLifecycleTransitioner, which owns the transition
	 * tables, the co-signer gate, the ADR-005 outcome axis and the
	 * terminal-completeness check. The seam stays here because every caller and
	 * every test addresses the state machine through MotionService; only the
	 * implementation moved.
	 *
	 * Controllers that expose this via HTTP must call their own
	 * requireChairOrSecretary() guard BEFORE invoking this method and pass the
	 * authenticated UID as actorId.
	 *
	 * ADR-005: `$outcome` carries the vote result — `adopted` or `rejected` —
	 * which is a value of `Decision.outcome`, NOT a lifecycle state. It is
	 * required when, and only when, the transition enters a terminal outcome
	 * state (`decided | enacted | archived`); passing it on an in-flight
	 * transition is a caller error and is refused, because an in-flight motion
	 * has no result yet and recording one would be a lie about what happened.
	 *
	 * @param string $objectId UUID of the motion- or amendment-typed Decision
	 * @param string $objectType ADR-005 decisionType discriminator: 'motion' or 'amendment'
	 * @param string $newState Target lifecycle state (Decision.lifecycle vocabulary)
	 * @param string $actorId Nextcloud user UID performing the transition, or 'system' for internal calls
	 * @param string|null $outcome Vote result ('adopted'|'rejected'), terminal states only
	 *
	 * @throws InvalidArgumentException When the transition, actor, co-signer count or outcome is refused
	 * @throws RuntimeException When the object cannot be found or saved
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function transitionLifecycle(
		string $objectId,
		string $objectType,
		string $newState,
		string $actorId,
		?string $outcome = null,
	): void;
}//end interface
