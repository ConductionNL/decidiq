<?php

/**
 * Decidesk Minutes Context Resolver
 *
 * Answers the handful of OpenRegister lookup questions that every minutes
 * workflow asks: "give me this Minutes record", "which Meeting is it linked
 * to", "which GovernanceBody does that Meeting belong to", "who are the active
 * participants".
 *
 * Extracted from ALVMinutesService and MinutesService, which each carried their
 * own copy of the relation-unwrapping dance. A duplicated lookup is a lookup
 * that can silently drift, so there is exactly one here.
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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Resolves Minutes, Meeting, GovernanceBody and Participant context from OpenRegister.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 */
class MinutesContextResolver {

	/**
	 * Upper bound applied to every participant query.
	 *
	 * @var int
	 */
	private const PARTICIPANT_LIMIT = 999;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy-loads OpenRegister services)
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Fetch a Minutes record, or null when it does not exist.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array<string,mixed>|null The Minutes data, or null when not found
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function findMinutes(string $minutesId): ?array {
		return $this->findObject(id: $minutesId, schema: 'minutes');
	}//end findMinutes()

	/**
	 * Fetch a Minutes record or fail.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array<string,mixed> The Minutes data
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function requireMinutes(string $minutesId): array {
		$minutes = $this->findMinutes(minutesId: $minutesId);
		if ($minutes === null) {
			throw new MissingObjectException(message: "Minutes not found: $minutesId");
		}

		return $minutes;
	}//end requireMinutes()

	/**
	 * Fetch a Meeting record or fail.
	 *
	 * @param string $meetingId The Meeting ID
	 *
	 * @return array<string,mixed> The Meeting data
	 *
	 * @throws MissingObjectException When the Meeting record does not exist
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function requireMeeting(string $meetingId): array {
		$meeting = $this->findObject(id: $meetingId, schema: 'meeting');
		if ($meeting === null) {
			throw new MissingObjectException(message: "Meeting not found: $meetingId");
		}

		return $meeting;
	}//end requireMeeting()

	/**
	 * Resolve the Meeting ID linked to a Minutes record.
	 *
	 * @param array<string,mixed> $minutes The Minutes data
	 *
	 * @return string|null The linked Meeting ID, or null when there is none
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function linkedMeetingId(array $minutes): ?string {
		return $this->firstRelation(object: $minutes, relation: 'Meeting');
	}//end linkedMeetingId()

	/**
	 * Resolve the GovernanceBody ID a Minutes record ultimately belongs to.
	 *
	 * Walks Minutes → Meeting → GovernanceBody. Returns null at the first step
	 * that cannot be resolved; callers decide whether that is fatal.
	 *
	 * @param array<string,mixed> $minutes The Minutes data
	 *
	 * @return string|null The GovernanceBody ID, or null when it cannot be resolved
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function governanceBodyIdForMinutes(array $minutes): ?string {
		$meetingId = $this->linkedMeetingId(minutes: $minutes);
		if ($meetingId === null) {
			return null;
		}

		$meeting = $this->findObject(id: $meetingId, schema: 'meeting');
		if ($meeting === null) {
			return null;
		}

		return $this->governanceBodyId(meeting: $meeting);
	}//end governanceBodyIdForMinutes()

	/**
	 * Resolve the GovernanceBody ID linked to a Meeting record.
	 *
	 * @param array<string,mixed> $meeting The Meeting data
	 *
	 * @return string|null The GovernanceBody ID, or null when there is none
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function governanceBodyId(array $meeting): ?string {
		return $this->firstRelation(object: $meeting, relation: 'GovernanceBody');
	}//end governanceBodyId()

	/**
	 * Fetch the active participants of a GovernanceBody.
	 *
	 * Returns an empty list when no body is given — a workflow without a body
	 * has no membership roll, which is not an error.
	 *
	 * @param string|null $bodyId The GovernanceBody ID
	 *
	 * @return array<int,array<string,mixed>> The active participant records
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function activeParticipants(?string $bodyId): array {
		if ($bodyId === null) {
			return [];
		}

		return $this->findParticipants(
			filters: [
				'leftAt' => null,
				'_limit' => self::PARTICIPANT_LIMIT,
				'_relations.governance-body' => $bodyId,
			]
		);

	}//end activeParticipants()

	/**
	 * Fetch the participants holding any of the given roles.
	 *
	 * @param array<int,string> $roles The roles to match
	 *
	 * @return array<int,array<string,mixed>> The matching participant records
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
	 */
	public function participantsByRole(array $roles): array {
		return $this->findParticipants(
			filters: [
				'role' => $roles,
				'_limit' => self::PARTICIPANT_LIMIT,
			]
		);

	}//end participantsByRole()

	/**
	 * Fetch the agenda items of a Meeting, ordered by orderNumber.
	 *
	 * @param string $meetingId The Meeting ID
	 *
	 * @return array<int,array<string,mixed>> The agenda item records
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function agendaItems(string $meetingId): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidesk');
		$objectService->setSchema('agenda-item');

		$entities = $objectService->findAll(
			[
				'filters' => [
					'_relations.meeting' => $meetingId,
					'_limit' => self::PARTICIPANT_LIMIT,
					'_order' => 'orderNumber:ASC',
				],
			]
		);

		return array_map(static fn ($entity) => $entity->jsonSerialize(), $entities);
	}//end agendaItems()

	/**
	 * Run a participant query and serialise the results.
	 *
	 * @param array<string,mixed> $filters The OpenRegister filter map
	 *
	 * @return array<int,array<string,mixed>> The participant records
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	private function findParticipants(array $filters): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidesk');
		$objectService->setSchema('participant');

		$entities = $objectService->findAll(['filters' => $filters]);

		return array_map(static fn ($entity) => $entity->jsonSerialize(), $entities);
	}//end findParticipants()

	/**
	 * Fetch a single decidesk object and serialise it.
	 *
	 * @param string $id The object ID
	 * @param string $schema The schema slug
	 *
	 * @return array<string,mixed>|null The object data, or null when not found
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	private function findObject(string $id, string $schema): ?array {
		$entity = $this->objectService()->find(id: $id, register: 'decidesk', schema: $schema);
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findObject()

	/**
	 * Unwrap the first ID from a relation that may be a scalar or a list.
	 *
	 * @param array<string,mixed> $object The object carrying the relations map
	 * @param string $relation The relation key
	 *
	 * @return string|null The first related ID, or null when the relation is empty
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	private function firstRelation(array $object, string $relation): ?string {
		$related = ($object['relations'][$relation] ?? null);
		if (empty($related) === true) {
			return null;
		}

		if (is_array($related) === true) {
			$related = ($related[0] ?? null);
		}

		if (empty($related) === true) {
			return null;
		}

		return (string)$related;
	}//end firstRelation()

	/**
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The OpenRegister ObjectService instance
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
