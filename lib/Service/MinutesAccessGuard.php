<?php

/**
 * Decidesk Minutes Access Guard
 *
 * The two per-object authorisation questions every Minutes endpoint asks:
 * "may this caller act as chair/secretary on the linked meeting?" and "is this
 * caller a participant of it at all?".
 *
 * Extracted from MinutesController so the correction endpoints — which live in
 * MinutesCorrectionController — enforce the SAME guard implementation rather
 * than a copy. A duplicated authorisation check is a check that can silently
 * drift, so there is exactly one here.
 *
 * Fails CLOSED: an unresolvable meeting is 403 for every non-admin.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Per-object authorisation for the Minutes endpoints. Fail-closed.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesAccessGuard {
	/**
	 * Constructor for MinutesAccessGuard.
	 *
	 * @param ObjectServiceInterface $objectService OR object service
	 * @param ParticipantResolver $participantResolver Role resolution
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Require chair, secretary, or NC admin authority on a Minutes record.
	 *
	 * Resolves the associated meeting via the minutes relations map and checks
	 * participant records for a chair or secretary role, mirroring
	 * AgendaController.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function requireChairOrAdmin(string $minutesId): ?JSONResponse {
		$userId = $this->currentUid();
		if ($userId === null) {
			return new JSONResponse(
				['message' => 'Unauthenticated.'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		// A missing Minutes object is the caller's 404 to raise, not this
		// guard's 403 — return null so the action can produce it.
		$minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
		if ($minutesEntity === null) {
			return null;
		}

		$meetingId = $this->meetingIdFrom(minutes: $minutesEntity->jsonSerialize());
		if ($meetingId === null) {
			return new JSONResponse(
				['message' => 'Forbidden: could not resolve meeting for authorisation.'],
				Http::STATUS_FORBIDDEN
			);
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
			['message' => 'Forbidden: chair or secretary role required for this minutes record.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireChairOrAdmin()

	/**
	 * Require that the caller is a participant of the meeting linked to the
	 * Minutes record (any role), a chair/secretary, or an NC admin.
	 *
	 * Used by the correction-suggestion endpoint: per the resolution-minutes
	 * spec, every meeting participant may suggest corrections during review,
	 * while resolution of suggestions stays chair/secretary-gated. Fails
	 * CLOSED: an unresolvable meeting yields 403 for non-admins.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function requireParticipant(string $minutesId): ?JSONResponse {
		$userId = $this->currentUid();
		if ($userId === null) {
			return new JSONResponse(
				['message' => 'Unauthenticated.'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		$meetingId = $this->resolveMeetingId(minutesId: $minutesId);
		if ($meetingId === null) {
			return new JSONResponse(
				['message' => 'Forbidden: could not resolve meeting for authorisation.'],
				Http::STATUS_FORBIDDEN
			);
		}

		if ($this->participantResolver->isParticipant(meetingId: $meetingId, nextcloudUid: $userId) === true) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Forbidden: meeting participation required to suggest corrections.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireParticipant()

	/**
	 * Resolve the linked meeting UUID from a Minutes record.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return string|null The meeting UUID, or null when not resolvable
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function resolveMeetingId(string $minutesId): ?string {
		$minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
		if ($minutesEntity === null) {
			return null;
		}

		return $this->meetingIdFrom(minutes: $minutesEntity->jsonSerialize());
	}//end resolveMeetingId()

	/**
	 * Resolve the acting user's UID.
	 *
	 * @return string|null The UID, or null when no user is signed in
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end currentUid()

	/**
	 * Read the linked meeting UUID out of a serialised Minutes object.
	 *
	 * @param array<string,mixed> $minutes The serialised Minutes object
	 *
	 * @return string|null The meeting UUID, or null when not resolvable
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function meetingIdFrom(array $minutes): ?string {
		$meetingRelation = ($minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null);
		if ($meetingRelation === null) {
			return null;
		}

		$meetingId = $meetingRelation;
		if (is_array($meetingRelation) === true) {
			$meetingId = ($meetingRelation['id'] ?? null);
		}

		if (is_string($meetingId) === false || $meetingId === '') {
			return null;
		}

		return $meetingId;
	}//end meetingIdFrom()
}//end class
