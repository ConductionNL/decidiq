<?php

/**
 * Decidiq Decision Context Resolver
 *
 * Resolves the OpenRegister objects and identities that surround a decision:
 * the decision itself, its linked meeting, its governance domain, its
 * governance body and the Nextcloud UID of the meeting chair. Owning this
 * lookup chain here keeps DecisionLifecycleService focused on the state
 * machine itself.
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
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Read-only context lookups for the guarded decision lifecycle.
 *
 * Every lookup goes through OpenRegister's ObjectService, so per-object RBAC
 * applies unchanged: find() returns null (or throws DoesNotExistException) for
 * objects the session user may not read, which every method here maps to null.
 * Callers MUST treat null as "unavailable" and fail closed.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionContextResolver {
	/**
	 * Constructor for DecisionContextResolver.
	 *
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a decision object as a plain array, or null when missing /
	 * unreadable for the session user (ObjectService RBAC).
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param string $decisionId UUID of the decision
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array<string, mixed>|null
	 */
	public function loadDecision(object $objectService, string $decisionId): ?array {
		try {
			$entity = $objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision');
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end loadDecision()

	/**
	 * Resolve the meeting linked to a decision, if any.
	 *
	 * Decisions reference their meeting either through the `meeting` relation
	 * property or the legacy `relations.Meeting` array written by
	 * LiveDecisionService — both shapes are accepted.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $decision Decision object array
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array<string, mixed>|null Meeting object array or null when not linked / not found
	 */
	public function resolveLinkedMeeting(object $objectService, array $decision): ?array {
		$meetingId = $this->resolveRelationId(value: $this->readMeetingReference(decision: $decision));
		if ($meetingId === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end resolveLinkedMeeting()

	/**
	 * Read the raw meeting reference off a decision (relation property or the
	 * legacy `relations.Meeting` array), without resolving it to an id.
	 *
	 * @param array<string, mixed> $decision Decision object array
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return mixed The raw reference: a UUID string, a relation array, or null
	 */
	public function readMeetingReference(array $decision): mixed {
		return ($decision['meeting'] ?? ($decision['relations']['Meeting'][0] ?? null));
	}//end readMeetingReference()

	/**
	 * Normalise a relation value (a UUID string or a `{id: ...}` array) to a
	 * non-empty UUID string, or null when it carries no usable id.
	 *
	 * @param mixed $value Raw relation value read off an object property
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null
	 */
	public function resolveRelationId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? null);
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end resolveRelationId()

	/**
	 * Resolve the governance domain for policy lookup.
	 *
	 * Resolution chain: decision.domain → linked meeting.domain →
	 * 'operations' — the same chain MeetingService uses. Unknown values are
	 * mapped to the restricted default-deny policy inside the guard.
	 *
	 * @param array<string, mixed> $decision Decision object array
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string
	 */
	public function resolveDomain(array $decision, ?array $meeting): string {
		return ($this->firstNonEmptyString(
			candidates: [($decision['domain'] ?? null), ($meeting['domain'] ?? null)]
		) ?? 'operations');

	}//end resolveDomain()

	/**
	 * Resolve the governance body a decision belongs to (process-configuration).
	 *
	 * Falls back from the decision's own `governanceBody` to the linked
	 * meeting's; returns null when neither carries a usable id, which callers
	 * translate into "no process-template override" (fail-safe).
	 *
	 * @param array<string, mixed> $decision Decision object array
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return string|null
	 */
	public function resolveGovernanceBodyId(array $decision, ?array $meeting): ?string {
		return $this->firstNonEmptyString(
			candidates: [($decision['governanceBody'] ?? null), ($meeting['governanceBody'] ?? null)]
		);

	}//end resolveGovernanceBodyId()

	/**
	 * Resolve the Nextcloud UID of the chair of the linked meeting.
	 *
	 * `meeting.chair` holds a Participant UUID (not an NC UID); the
	 * Participant object carries the `nextcloudUserId` link. Returns null
	 * when no meeting is linked, the meeting has no chair, or the chair
	 * participant cannot be resolved — callers MUST treat null as
	 * "authorization unavailable" and reject (fail closed), never skip.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Nextcloud UID of the chair, or null when unresolvable
	 */
	public function resolveChairUserId(object $objectService, ?array $meeting): ?string {
		$chairId = $this->resolveRelationId(value: ($meeting['chair'] ?? null));
		if ($chairId === null) {
			return null;
		}

		try {
			$chairParticipant = $objectService->find(
				id: $chairId,
				register: 'decidiq',
				schema: 'participant'
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($chairParticipant === null) {
			$this->logger->warning(
				'Decidiq DecisionLifecycleService: chair participant not found',
				['chairParticipantId' => $chairId]
			);
			return null;
		}

		$chairData = (array)$chairParticipant->jsonSerialize();

		return $this->firstNonEmptyString(
			candidates: [($chairData['nextcloudUserId'] ?? ($chairData['owner'] ?? null))]
		);

	}//end resolveChairUserId()

	/**
	 * Return the first candidate that is a non-empty string, or null.
	 *
	 * @param array<int, mixed> $candidates Ordered candidate values
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null
	 */
	private function firstNonEmptyString(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end firstNonEmptyString()
}//end class
