<?php

/**
 * Decidesk Conflict-of-Interest Authorization Guard
 *
 * Answers the two authorization questions the conflict-of-interest endpoints
 * ask: does the caller identify as the Membership a declaration is about, and
 * does the caller hold chair/secretary authority on the relevant meeting's
 * GovernanceBody. Extracted from `ConflictOfInterestService` (which had grown
 * past the class-complexity budget) so the OpenRegister lookups and the
 * role-resolution collaborators live with the policy they implement rather
 * than with declaration persistence — the same split already used for
 * `PublicationStaffGuard` / `MinutesAccessGuard` / `TranscriptionStaffGuard`.
 *
 * Fails CLOSED: an unresolvable meeting or participant is never authorized.
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
 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-object authorization guard for the conflict-of-interest endpoints.
 * Fail-closed.
 *
 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md
 */
class ConflictOfInterestAuthorizationGuard {

	/**
	 * Constructor for ConflictOfInterestAuthorizationGuard.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ParticipantResolver $participantResolver Resolves chair/secretary role membership
	 * @param ParticipantToPersonMembershipResolver $participantCrosswalk Resolves a Participant UUID to its Person/Membership pair
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly ParticipantToPersonMembershipResolver $participantCrosswalk,
	) {
	}//end __construct()

	/**
	 * Authorize access to one member's conflict-of-interest declarations:
	 * the caller must be the declaring Membership themselves, or a chair/
	 * secretary of the relevant meeting's GovernanceBody. Used by both
	 * `ConflictOfInterestService::declare()` (recording a new declaration
	 * about oneself) and the `forMember()` read endpoint — the two share the
	 * same rule.
	 *
	 * @param string $membershipId UUID of the Membership the declaration is about
	 * @param string $agendaItemId UUID of the agenda item the declaration concerns
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return bool
	 */
	public function isAuthorizedForMember(string $membershipId, string $agendaItemId, string $callerUid): bool {
		if ($this->isCallerThisMember(membershipId: $membershipId, callerUid: $callerUid) === true) {
			return true;
		}

		$meetingId = $this->resolveMeetingIdFromAgendaItem(agendaItemId: $agendaItemId);
		if ($meetingId === null) {
			return false;
		}

		return $this->isChairOrSecretary(meetingId: $meetingId, uid: $callerUid);
	}//end isAuthorizedForMember()

	/**
	 * Authorize recording the action-taken on an existing declaration: chair
	 * or secretary of the relevant meeting's GovernanceBody only (an admin
	 * bypasses this entirely via a null `$callerUid` at the call site).
	 * Recording the mitigating action (e.g. recusal) is a presiding-officer
	 * act, so — unlike `isAuthorizedForMember()` — the declaring member
	 * themselves does NOT pass this check for their own declaration.
	 *
	 * @param array<string, mixed> $declaration Serialised conflict-of-interest row
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return bool
	 */
	public function isAuthorizedToRecordAction(array $declaration, string $callerUid): bool {
		$agendaItemId = (string)($declaration['agendaItem'] ?? '');
		if ($agendaItemId === '') {
			// Fail closed: no agenda item on the row means no GovernanceBody can
			// be established, so chair/secretary authority cannot be verified.
			return false;
		}

		$meetingId = $this->resolveMeetingIdFromAgendaItem(agendaItemId: $agendaItemId);
		if ($meetingId === null) {
			return false;
		}

		return $this->isChairOrSecretary(meetingId: $meetingId, uid: $callerUid);
	}//end isAuthorizedToRecordAction()

	/**
	 * Whether a Nextcloud UID holds a chair or secretary role on the given
	 * meeting's GovernanceBody. Reuses `ParticipantResolver::hasRole()` rather
	 * than duplicating role-resolution logic (same convention used by
	 * `ProxyVoteService::isChairOrClerk()`).
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $uid Nextcloud UID to check
	 *
	 * @return bool
	 */
	private function isChairOrSecretary(string $meetingId, string $uid): bool {
		return $this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $uid,
			roles: ['chair', 'secretary']
		);
	}//end isChairOrSecretary()

	/**
	 * Whether the caller IS the Membership the declaration is about. Resolves
	 * the caller's own identity (Nextcloud UID -> Participant -> Person/
	 * Membership, via the same crosswalk `ProxyVoteService` uses) and compares
	 * the resulting Membership UUID against `$membershipId` — `boardMember`
	 * on the ConflictOfInterest schema is a Membership reference as of
	 * model-debt-cleanup-schema (REQ-SDM-023), not the deprecated Participant.
	 *
	 * @param string $membershipId UUID of the Membership to compare against
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool
	 */
	private function isCallerThisMember(string $membershipId, string $callerUid): bool {
		$callerParticipant = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
		if ($callerParticipant === null) {
			return false;
		}

		$resolution = $this->participantCrosswalk->resolve(participantId: $callerParticipant);
		if ($resolution === null) {
			return false;
		}

		return ($resolution['membership'] === $membershipId);
	}//end isCallerThisMember()

	/**
	 * Resolve the OpenRegister participant UUID linked to a Nextcloud user ID.
	 *
	 * Returns null when no participant record is linked to this user (mirrors
	 * `ProxyVoteService::resolveParticipantUuid()` / `MotionCoauthorService`).
	 *
	 * @param string $nextcloudUid Nextcloud UID
	 *
	 * @return string|null
	 */
	private function resolveParticipantUuid(string $nextcloudUid): ?string {
		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('participant');
		$entities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

		foreach ($entities as $participantEntity) {
			$participant = $participantEntity->jsonSerialize();
			return ($participant['uuid'] ?? $participant['id'] ?? null);
		}

		return null;
	}//end resolveParticipantUuid()

	/**
	 * Resolve the meeting UUID an agenda item belongs to, reading its flat
	 * `meeting` property (canonical shape) with a structured-relations
	 * fallback — mirrors `MotionLinkResolver::readMeetingLink()`'s shape
	 * tolerance for the same "meeting reference on a serialised object"
	 * question. Fails closed: any lookup failure returns null, and every
	 * caller of this method treats null as "cannot authorize."
	 *
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @return string|null The meeting UUID, or null when it cannot be resolved
	 */
	private function resolveMeetingIdFromAgendaItem(string $agendaItemId): ?string {
		if ($agendaItemId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $agendaItemId, register: 'decidesk', schema: 'agenda-item');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidesk: ConflictOfInterestAuthorizationGuard could not resolve the agenda item\'s meeting',
				['agendaItemId' => $agendaItemId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->readMeetingLink(agendaItem: (array)$entity->jsonSerialize());
	}//end resolveMeetingIdFromAgendaItem()

	/**
	 * Read the meeting reference out of a serialised agenda item's flat
	 * `meeting` property (canonical shape), falling back to
	 * `meetingIdFromRelations()` for the structured-relations shape.
	 *
	 * @param array<string, mixed> $agendaItem The serialised agenda item
	 *
	 * @return string|null The meeting UUID, or null when not linked
	 */
	private function readMeetingLink(array $agendaItem): ?string {
		$meetingRef = ($agendaItem['meeting'] ?? null);
		if (is_string($meetingRef) === true && $meetingRef !== '') {
			return $meetingRef;
		}

		if (is_array($meetingRef) === true) {
			$id = ($meetingRef['id'] ?? $meetingRef['uuid'] ?? null);
			if (is_string($id) === true && $id !== '') {
				return $id;
			}
		}

		return $this->meetingIdFromRelations(agendaItem: $agendaItem);
	}//end readMeetingLink()

	/**
	 * Read the meeting reference out of a serialised agenda item's
	 * structured `relations` shape (schema-tagged list entry, or a flat
	 * field-keyed map entry).
	 *
	 * @param array<string, mixed> $agendaItem The serialised agenda item
	 *
	 * @return string|null The meeting UUID, or null when not linked
	 */
	private function meetingIdFromRelations(array $agendaItem): ?string {
		$relations = ($agendaItem['@self']['relations'] ?? ($agendaItem['relations'] ?? []));
		if (is_array($relations) === false) {
			return null;
		}

		foreach ($relations as $key => $relation) {
			if (is_array($relation) === true && ($relation['schema'] ?? '') === 'meeting') {
				return ($relation['id'] ?? null);
			}

			if (is_string($relation) === true && $key === 'meeting') {
				return $relation;
			}
		}

		return null;
	}//end meetingIdFromRelations()
}//end class
