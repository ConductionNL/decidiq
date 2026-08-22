<?php

/**
 * Decidiq Publication Staff Guard
 *
 * Answers the one authorisation question every publication endpoint asks: does
 * the acting user hold chair/secretary authority on the meeting a publication
 * source object belongs to?
 *
 * Extracted from PublicationController so the OpenRegister lookup and the
 * role-resolution collaborators live with the policy they implement rather than
 * with HTTP response building. The controller keeps only the mapping from a
 * denial to its HTTP status.
 *
 * Fails CLOSED: an unresolvable meeting is never authorised.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Per-object staff authority for the publication endpoints. Fail-closed.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationStaffGuard {
	/**
	 * Outcome: the caller holds the required authority.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Outcome: the caller does not hold the required authority.
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * Outcome: the PublicationRecord the check was keyed by does not exist.
	 */
	public const RECORD_NOT_FOUND = 'record-not-found';

	/**
	 * Constructor for PublicationStaffGuard.
	 *
	 * @param ObjectServiceInterface $objectService OR object service
	 * @param ParticipantResolver $participantResolver Role resolution
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Resolve the acting user's UID.
	 *
	 * The publication actions raise their own 401 before asking either check
	 * below, which is why those may treat the session user as present.
	 *
	 * @return string|null The UID, or null when no user is signed in
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end currentUid()

	/**
	 * Per-object staff check keyed by a publication source object.
	 *
	 * Admin passes. Otherwise the caller must hold a chair/secretary role on the
	 * meeting linked to the source object (decision/agenda/minutes).
	 *
	 * @param string $sourceType One of decision|agenda|minutes
	 * @param string $sourceId UUID of the source object
	 *
	 * @return string One of self::ALLOWED or self::FORBIDDEN
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function checkSource(string $sourceType, string $sourceId): string {
		$userId = $this->userSession->getUser()->getUID();
		if ($this->groupManager->isAdmin($userId) === true) {
			return self::ALLOWED;
		}

		$meetingId = $this->resolveMeetingId(sourceType: $sourceType, sourceId: $sourceId);
		if ($this->holdsStaffRole(meetingId: $meetingId, userId: $userId) === true) {
			return self::ALLOWED;
		}

		return self::FORBIDDEN;
	}//end checkSource()

	/**
	 * Per-object staff check keyed by an existing PublicationRecord.
	 *
	 * @param string $recordId UUID of the PublicationRecord
	 *
	 * @return string One of self::ALLOWED, self::FORBIDDEN or self::RECORD_NOT_FOUND
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function checkRecord(string $recordId): string {
		$userId = $this->userSession->getUser()->getUID();
		if ($this->groupManager->isAdmin($userId) === true) {
			return self::ALLOWED;
		}

		$record = $this->objectService->find(id: $recordId, register: 'decidesk', schema: 'publication-record');
		if ($record === null) {
			return self::RECORD_NOT_FOUND;
		}

		$data = $record->jsonSerialize();
		$sourceType = (string)($data['sourceType'] ?? '');
		$sourceId = (string)($data['sourceObject'] ?? '');

		$meetingId = $this->resolveMeetingId(sourceType: $sourceType, sourceId: $sourceId);
		if ($this->holdsStaffRole(meetingId: $meetingId, userId: $userId) === true) {
			return self::ALLOWED;
		}

		return self::FORBIDDEN;
	}//end checkRecord()

	/**
	 * Decide whether a user holds chair/secretary authority on a meeting.
	 *
	 * @param string|null $meetingId The resolved meeting UUID, or null
	 * @param string $userId Nextcloud UID of the acting user
	 *
	 * @return bool True when the user holds the role; false when it cannot be established
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function holdsStaffRole(?string $meetingId, string $userId): bool {
		if ($meetingId === null) {
			return false;
		}

		return $this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $userId,
			roles: ['chair', 'secretary']
		);

	}//end holdsStaffRole()

	/**
	 * Resolve the meeting UUID a source object is associated with.
	 *
	 * For agenda the source IS a meeting; for minutes/decision it is resolved
	 * from the relations map.
	 *
	 * @param string $sourceType One of decision|agenda|minutes
	 * @param string $sourceId UUID of the source object
	 *
	 * @return string|null The meeting UUID, or null when it cannot be resolved
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function resolveMeetingId(string $sourceType, string $sourceId): ?string {
		if ($sourceType === 'agenda') {
			return $sourceId;
		}

		$schema = 'decision';
		if ($sourceType === 'minutes') {
			$schema = 'minutes';
		}

		$entity = $this->objectService->find(id: $sourceId, register: 'decidesk', schema: $schema);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		$meetingRelation = ($data['relations']['meeting'] ?? $data['relations']['Meeting'] ?? $data['meeting'] ?? null);
		if (is_array($meetingRelation) === true) {
			$meetingRelation = ($meetingRelation['id'] ?? ($meetingRelation[0] ?? null));
		}

		if (is_string($meetingRelation) === true && $meetingRelation !== '') {
			return $meetingRelation;
		}

		return null;
	}//end resolveMeetingId()
}//end class
