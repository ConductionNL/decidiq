<?php

/**
 * Decidiq Voting Reminder Audience
 *
 * Who a voting-deadline reminder goes to: the members of the meeting behind
 * a voting round who have not cast their ballot yet (#1379).
 *
 * Extracted from VotingDeadlineReminderService so the reminder sweep (which
 * rounds, when, and the sent marker) and the audience walk (round → meeting
 * → members, minus the ballots on file) each stay small.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Nextcloud users a voting-deadline reminder should reach.
 *
 * Audience: the members of the round's meeting (round → motion or amendment
 * → meeting → governance body → participants, through the same resolvers the
 * cast guard uses) minus those whose ballot is already on file.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingReminderAudience {

	/**
	 * Constructor for VotingReminderAudience.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads the round → meeting → members resolvers)
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Nextcloud UIDs of the round's members who have not voted yet.
	 *
	 * Participants without a nextcloudUserId link are skipped.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $round Round payload
	 * @param string $roundId UUID of the voting round
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string[]
	 */
	public function pendingUserIds(object $objectService, array $round, string $roundId): array {
		$members = $this->resolveParticipantUserIds(round: $round);
		$voted = $this->resolveVotedUserIds(objectService: $objectService, roundId: $roundId);

		return array_values(array_diff($members, $voted));
	}//end pendingUserIds()

	/**
	 * Nextcloud UIDs of participants whose ballot in this round is on file.
	 *
	 * Votes link to their round through a structured `relations` entry, the
	 * shape VoteBallotFactory writes, so they are found with the same
	 * ObjectRelationFilter the tally uses. The ballot belongs to the delegator
	 * on a proxy vote and to the caster otherwise. A secret ballot carries no
	 * participant relation, so it cannot exclude anyone from the reminder.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param string $roundId UUID of the voting round
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string[]
	 */
	private function resolveVotedUserIds(object $objectService, string $roundId): array {
		$relationFilter = new ObjectRelationFilter();
		try {
			$votes = $relationFilter->matching(
				entities: $objectService->findAll(
					[
						'filters' => (['register' => 'decidiq', 'schema' => 'vote'] + $relationFilter->filterFor(targetId: $roundId)),
					]
				),
				schema: 'voting-round',
				targetId: $roundId
			);
		} catch (\Throwable) {
			return [];
		}

		$uids = [];
		foreach ($votes as $entity) {
			$row = $this->rowToArray(entity: $entity);
			$ownerId = null;
			if ($row !== null) {
				$ownerId = $this->ballotOwnerId(vote: $row);
			}

			if ($ownerId === null) {
				continue;
			}

			$uid = $this->participantUserId(objectService: $objectService, participantId: $ownerId);
			if ($uid !== null) {
				$uids[] = $uid;
			}
		}//end foreach

		return array_values(array_unique($uids));
	}//end resolveVotedUserIds()

	/**
	 * The participant UUID a vote counts for, read from its relations.
	 *
	 * @param array<string, mixed> $vote One vote payload
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string|null The ballot owner's participant UUID, or null when not recorded
	 */
	private function ballotOwnerId(array $vote): ?string {
		$relations = ($vote['relations'] ?? ($vote['@self']['relations'] ?? []));
		if (is_array($relations) === false) {
			return null;
		}

		$wantedType = null;
		if (($vote['isProxy'] ?? false) === true) {
			$wantedType = 'delegator';
		}

		foreach ($relations as $relation) {
			if (is_array($relation) === true
				&& ($relation['schema'] ?? '') === 'participant'
				&& ($relation['type'] ?? null) === $wantedType
			) {
				return $this->refId(ref: $relation);
			}
		}

		return null;
	}//end ballotOwnerId()

	/**
	 * Nextcloud UIDs of the members of the meeting behind this round.
	 *
	 * Resolved through the same services the cast guard uses
	 * (AmendmentOrderService for round → motion/amendment → meeting,
	 * ParticipantResolver for meeting → governance body → participants), so
	 * the reminder reaches exactly the members who may vote.
	 *
	 * @param array<string, mixed> $round Round payload
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string[]
	 */
	private function resolveParticipantUserIds(array $round): array {
		try {
			$meetingId = $this->container->get(AmendmentOrderService::class)->resolveMeetingIdForRound(round: $round);
			if ($meetingId === null) {
				return [];
			}

			$participants = $this->container->get(ParticipantResolver::class)->resolveMeetingParticipants(meetingId: $meetingId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not resolve the members to remind for a voting round',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$uids = [];
		foreach ($participants as $entity) {
			$row = $this->rowToArray(entity: $entity);
			$uid = null;
			if ($row !== null) {
				$uid = $this->rowUserId(row: $row);
			}

			if ($uid !== null) {
				$uids[] = $uid;
			}
		}

		return array_values(array_unique($uids));
	}//end resolveParticipantUserIds()

	/**
	 * Resolve a participant UUID to its linked Nextcloud UID.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param string $participantId UUID of the participant
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string|null
	 */
	private function participantUserId(object $objectService, string $participantId): ?string {
		try {
			$entity = $objectService->find(id: $participantId, register: 'decidiq', schema: 'participant');
		} catch (\Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->rowUserId(row: (array)$entity->jsonSerialize());
	}//end participantUserId()

	/**
	 * Normalise one OpenRegister list item (entity or array) to an array.
	 *
	 * @param mixed $entity ObjectEntity or already-serialized array
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return array<string, mixed>|null The row, or null when unusable
	 */
	private function rowToArray(mixed $entity): ?array {
		if (is_object($entity) === true) {
			return (array)$entity->jsonSerialize();
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		return null;
	}//end rowToArray()

	/**
	 * Read a UUID out of a reference that may be a bare string or an {id} object.
	 *
	 * @param mixed $ref The raw reference value
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string|null The UUID, or null when not resolvable
	 */
	private function refId(mixed $ref): ?string {
		if (is_array($ref) === true) {
			$ref = ($ref['id'] ?? null);
		}

		if (is_string($ref) === true && $ref !== '') {
			return $ref;
		}

		return null;
	}//end refId()

	/**
	 * Read the linked Nextcloud UID off a participant row.
	 *
	 * @param array<string, mixed> $row The participant payload
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string|null The UID, or null when the participant has no NC link
	 */
	private function rowUserId(array $row): ?string {
		$uid = ($row['nextcloudUserId'] ?? ($row['owner'] ?? null));
		if (is_string($uid) === true && $uid !== '') {
			return $uid;
		}

		return null;
	}//end rowUserId()
}//end class
