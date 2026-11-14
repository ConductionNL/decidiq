<?php

/**
 * Urgency Trigger Guard
 *
 * Who may declare a decision urgent: the resolved meeting chair, or a holder
 * of a role the body's urgency policy allows. Fails closed: no policy, no
 * meeting or an actor whose role cannot be resolved is refused, the same
 * posture as the chair-only transitions of DecisionTransitionGuard.
 *
 * @category Lifecycle
 * @package  OCA\Decidiq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Lifecycle;

use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;

/**
 * Authorises an urgency declaration against the body's urgency policy.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */
class UrgencyTriggerGuard {

	/**
	 * The longest urgency reason accepted, in characters.
	 *
	 * @var int
	 */
	public const MAX_REASON_LENGTH = 4000;

	/**
	 * Constructor.
	 *
	 * @param ParticipantResolverInterface $participants Resolves an actor's role on a meeting.
	 */
	public function __construct(
		private readonly ParticipantResolverInterface $participants,
	) {
	}//end __construct()

	/**
	 * Why the actor may not declare the decision urgent, or null when they may.
	 *
	 * Checked in this order: a configured policy, a resolvable meeting, the
	 * actor's role, then the reason. The role is checked before the reason, so
	 * an unauthorised actor learns nothing about what a valid request holds.
	 *
	 * @param array<string, mixed>|null $policy      The body's urgency policy, null when none is configured.
	 * @param string|null               $meetingId   The decision's meeting, null when it has none.
	 * @param string|null               $chairUserId The resolved chair's user id, null when unresolvable.
	 * @param string                    $userId      The acting user.
	 * @param string                    $reason      The urgency reason given.
	 *
	 * @return array{status: int, message: string}|null The refusal, or null.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function refusal(?array $policy, ?string $meetingId, ?string $chairUserId, string $userId, string $reason): ?array {
		if ($policy === null) {
			return ['status' => 422, 'message' => 'The urgent procedure is not configured for this body.'];
		}

		if ($this->mayTrigger(policy: $policy, meetingId: $meetingId, chairUserId: $chairUserId, userId: $userId) === false) {
			return ['status' => 403, 'message' => 'Only the chair or a role the urgency policy allows may declare this decision urgent.'];
		}

		$reason = trim($reason);
		if ($reason === '') {
			return ['status' => 422, 'message' => 'urgencyReason is required.'];
		}

		if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			return ['status' => 422, 'message' => 'urgencyReason is longer than ' . self::MAX_REASON_LENGTH . ' characters.'];
		}

		return null;
	}//end refusal()

	/**
	 * Whether the actor holds the chair or an allowed role on the decision's meeting.
	 *
	 * @param array<string, mixed> $policy      The body's urgency policy.
	 * @param string|null          $meetingId   The decision's meeting.
	 * @param string|null          $chairUserId The resolved chair's user id.
	 * @param string               $userId      The acting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function mayTrigger(array $policy, ?string $meetingId, ?string $chairUserId, string $userId): bool {
		if ($userId === '' || $meetingId === null || $meetingId === '') {
			return false;
		}

		$roles = array_values(array_filter((array)($policy['allowedTriggerRoles'] ?? []), 'is_string'));
		if ($roles === []) {
			return false;
		}

		if (in_array('chair', $roles, true) === true && $chairUserId !== null && $chairUserId === $userId) {
			return true;
		}

		return $this->participants->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: $roles);
	}//end mayTrigger()
}//end class
