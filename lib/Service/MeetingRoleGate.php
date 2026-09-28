<?php

/**
 * Decidiq Meeting Role Gate
 *
 * Answers the single domain question "may this actor act as chair or secretary
 * of this meeting?" by combining the meeting-role lookup (ParticipantResolver)
 * with the Nextcloud-admin fallback. Controllers consume the answer instead of
 * re-deriving it from a group manager plus a role resolver, which keeps the
 * authorization rule in one place and out of the HTTP boundary (ADR-005).
 *
 * Fails CLOSED: when the roles cannot be resolved the resolver reports false and
 * the gate denies.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IGroupManager;

/**
 * Resolves the chair/secretary authorization decision for a meeting.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MeetingRoleGate {

	/**
	 * Meeting roles that count as "presiding" for guarded meeting actions.
	 *
	 * @var string[]
	 */
	private const PRESIDING_ROLES = ['chair', 'secretary'];

	/**
	 * Constructor for MeetingRoleGate.
	 *
	 * @param IGroupManager $groupManager Group manager for the NC-admin fallback
	 * @param ParticipantResolver $participantResolver Meeting-role resolver
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly ParticipantResolver $participantResolver,
	) {
	}//end __construct()

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
	public function isChairOrSecretary(string $meetingId, string $userId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $userId,
			roles: self::PRESIDING_ROLES,
		);

	}//end isChairOrSecretary()

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
	 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-001-the-meeting-page-asks-the-server-for-the-callers-meeting-roles
	 */
	public function rolesOf(string $meetingId, string $userId): array {
		return [
			'chair'     => $this->participantResolver->hasRole(
				meetingId: $meetingId,
				nextcloudUid: $userId,
				roles: ['chair'],
			),
			'secretary' => $this->participantResolver->hasRole(
				meetingId: $meetingId,
				nextcloudUid: $userId,
				roles: ['secretary'],
			),
			'admin'     => $this->groupManager->isAdmin($userId),
		];

	}//end rolesOf()
}//end class
