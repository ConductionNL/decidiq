<?php

/**
 * Decidiq Minutes Context Resolver
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
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
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
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
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
		// The Minutes schema declares `meeting` as a property; OpenRegister
		// keys its relations by that field name. The capitalised relation key
		// is the older shape.
		$ref = ($minutes['meeting'] ?? null);
		if (is_array($ref) === true) {
			$ref = ($ref['id'] ?? $ref['uuid'] ?? null);
		}

		if (is_string($ref) === true && $ref !== '') {
			return $ref;
		}

		return ($this->firstRelation(object: $minutes, relation: 'meeting') ?? $this->firstRelation(object: $minutes, relation: 'Meeting'));
	}//end linkedMeetingId()

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
		$objectService->setRegister('decidiq');
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
	 * Fetch a single Decidiq object and serialise it.
	 *
	 * @param string $id The object ID
	 * @param string $schema The schema slug
	 *
	 * @return array<string,mixed>|null The object data, or null when not found
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	private function findObject(string $id, string $schema): ?array {
		$entity = $this->objectService()->find(id: $id, register: 'decidiq', schema: $schema);
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
