<?php

/**
 * Decidesk Agenda Authorization Guard
 *
 * Answers the two authorization questions the agenda endpoints ask before they
 * do any work: "is there an authenticated user?" and "may this user act as
 * chair/secretary on this meeting (or is an instance admin)?".
 *
 * Extracted from AgendaController so the controller stays a thin REST surface:
 * the session, group-manager, participant-resolver and OpenRegister
 * object-service dependencies live here instead, keeping the controller inside
 * the PHPMD CouplingBetweenObjects budget. Behaviour, response bodies and
 * status codes are unchanged.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Authentication + chair/secretary authorization for the agenda endpoints.
 *
 * Every method returns null when the caller is allowed to proceed and a ready
 * JSONResponse when it is not, so callers can `if ($denied !== null) return
 * $denied;` — fail-closed by construction.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */
class AgendaAuthorizationGuard {
	/**
	 * Construct the AgendaAuthorizationGuard.
	 *
	 * @param ObjectService $objectService OpenRegister object service
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ParticipantResolver $participantResolver,
	) {
	}//end __construct()

	/**
	 * Verify a user is authenticated.
	 *
	 * @return JSONResponse|null Null if authenticated, 401 JSONResponse if not.
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * Verify the current user is an admin or holds a chair/secretary role for a meeting.
	 *
	 * @param string $meetingId UUID of the meeting to check
	 *
	 * @return JSONResponse|null Null if authorised, 401/403 JSONResponse if not.
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireChairOrAdmin(string $meetingId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		if ($this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $userId,
			roles: ['chair', 'secretary'],
		) === true
		) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Chair or secretary role required for this meeting'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireChairOrAdmin()

	/**
	 * Resolve an agenda item's meeting and authorise the caller against it.
	 *
	 * Returns a 404 when the item does not exist and a 403 when its meeting
	 * cannot be resolved, mirroring the behaviour the agenda endpoints had when
	 * this lived inline in the controller.
	 *
	 * @param string $agendaItemId UUID of the AgendaItem
	 *
	 * @return JSONResponse|null Null if authorised, 401/403/404 JSONResponse if not.
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
	 */
	public function requireChairOrAdminForAgendaItem(string $agendaItemId): ?JSONResponse {
		// Resolve the meeting for authorization; 404 if item does not exist.
		$item = $this->objectService->find($agendaItemId);
		if ($item === null) {
			return new JSONResponse(['message' => 'Agenda item not found.'], Http::STATUS_NOT_FOUND);
		}

		$itemData = (array)$item;
		$meetingId = $itemData['@self']['relations']['meeting'] ?? null;

		if ($meetingId === null) {
			return new JSONResponse(
				['message' => 'Could not resolve meeting for authorization.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $this->requireChairOrAdmin(meetingId: (string)$meetingId);
	}//end requireChairOrAdminForAgendaItem()
}//end class
