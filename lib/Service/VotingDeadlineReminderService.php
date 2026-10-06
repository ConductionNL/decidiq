<?php

/**
 * Decidiq Voting Deadline Reminder Service
 *
 * Finds open voting rounds whose deadline falls within the next 24 hours
 * and notifies participants who have not voted yet
 * (nextcloud-integration spec, notification requirement).
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

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 24-hour pre-deadline voting reminders.
 *
 * Selection contract: a round needs a reminder when it is open (no
 * closedAt, or a closedAt the chair preset for a moment still ahead), its
 * deadline (votingDeadline, else that preset closedAt) falls within the next
 * REMINDER_WINDOW seconds (and has not already passed), and it has no
 * deadlineReminderSentAt marker. After sending, the marker is stamped via
 * saveObject so the hourly job never reminds twice.
 *
 * Audience: the members of the round's meeting (round → motion or amendment
 * → meeting → governance body → participants, through the same resolvers the
 * cast guard uses) minus those whose ballot is already on file.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingDeadlineReminderService {

	/**
	 * Reminder window before the deadline, in seconds (24 hours).
	 *
	 * @var int
	 */
	public const REMINDER_WINDOW = 86400;

	/**
	 * Constructor for VotingDeadlineReminderService.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads OpenRegister services)
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Pure window check: is the deadline within (0, REMINDER_WINDOW] of now?
	 *
	 * @param string $deadline ISO-8601 deadline timestamp
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return bool True when the deadline is upcoming within the window
	 */
	public function isWithinReminderWindow(string $deadline, int $now): bool {
		if ($deadline === '') {
			return false;
		}

		try {
			$timestamp = (new DateTimeImmutable($deadline))->getTimestamp();
		} catch (\Throwable) {
			return false;
		}

		$delta = ($timestamp - $now);
		return ($delta > 0 && $delta <= self::REMINDER_WINDOW);
	}//end isWithinReminderWindow()

	/**
	 * Find open voting rounds that need a deadline reminder now.
	 *
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return array<int, array<string, mixed>> Round payloads needing a reminder
	 */
	public function findRoundsNeedingReminder(int $now): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			// The register and schema belong INSIDE `filters`: as top-level config
			// keys OpenRegister ignores them, so the scan matched nothing (#1379).
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'voting-round',
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to scan voting rounds for deadline reminders',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$due = [];
		foreach ($rows as $entity) {
			$row = $this->rowToArray(entity: $entity);
			if ($row !== null && $this->roundNeedsReminder(row: $row, now: $now) === true) {
				$due[] = $row;
			}
		}

		return $due;
	}//end findRoundsNeedingReminder()

	/**
	 * Whether one voting-round row is still open, unreminded, and inside the window.
	 *
	 * A closing time the chair sets when opening the round is stored as
	 * closedAt (VoteCastGuard refuses ballots after it) and mirrored to
	 * votingDeadline. A closedAt that is still ahead therefore means "closes
	 * then", not "closed", and serves as the deadline when votingDeadline is
	 * absent (rounds opened before the mirror existed) (#1379).
	 *
	 * Note `($x ?? '') === ''` is already true for a missing OR null value, so
	 * no separate null test is needed on either marker.
	 *
	 * @param array<string, mixed> $row One voting-round payload
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return bool True when this round needs a deadline reminder
	 */
	private function roundNeedsReminder(array $row, int $now): bool {
		$closedAt = (string)($row['closedAt'] ?? '');
		$alreadySent = (($row['deadlineReminderSentAt'] ?? '') !== '');
		if ($alreadySent === true || $this->isClosed(closedAt: $closedAt, now: $now) === true) {
			return false;
		}

		$deadline = (string)($row['votingDeadline'] ?? '');
		if ($deadline === '') {
			$deadline = $closedAt;
		}

		return $this->isWithinReminderWindow(deadline: $deadline, now: $now);

	}//end roundNeedsReminder()

	/**
	 * Whether a round's closedAt marks it closed at $now.
	 *
	 * Empty means open; an unparseable value is treated as closed so a
	 * malformed round never triggers a reminder.
	 *
	 * @param string $closedAt The round's closedAt value
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return bool True when the round is closed
	 */
	private function isClosed(string $closedAt, int $now): bool {
		if ($closedAt === '') {
			return false;
		}

		try {
			return (new DateTimeImmutable($closedAt))->getTimestamp() <= $now;
		} catch (\Throwable) {
			return true;
		}

	}//end isClosed()

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

	/**
	 * Send the reminder for one round and stamp the sent marker.
	 *
	 * Audience: meeting participants who have not cast a vote in this
	 * round yet (motion → meeting → participants walk; participants
	 * without a nextcloudUserId link are skipped).
	 *
	 * @param array<string, mixed> $round Round payload (from findRoundsNeedingReminder)
	 * @param int $now Current unix timestamp (marker value)
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return int Number of reminder notifications sent
	 */
	public function remindRound(array $round, int $now): int {
		$roundId = (string)($round['id'] ?? ($round['@self']['id'] ?? ''));
		if ($roundId === '') {
			return 0;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$notificationService = $this->container->get('OpenRegisterNotificationService');

			$votedUserIds = $this->resolveVotedUserIds(objectService: $objectService, roundId: $roundId);
			$audience = $this->resolveParticipantUserIds(round: $round);

			$pending = array_values(array_diff($audience, $votedUserIds));

			$sent = 0;
			foreach ($pending as $uid) {
				try {
					$notificationService->sendNotification(
						userId: $uid,
						title: 'Voting deadline approaching',
						message: 'A voting round closes within 24 hours and your vote has not been cast yet.',
						deepLink: '/voting-rounds/' . $roundId
					);
					$sent++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'Decidiq: deadline reminder notification failed',
						['roundId' => $roundId, 'uid' => $uid, 'exception' => $e->getMessage()]
					);
				}
			}

			// Stamp the marker even when the audience was empty — the round was
			// evaluated and must not be re-scanned every hour until the deadline.
			$round['deadlineReminderSentAt'] = gmdate('Y-m-d\TH:i:s\Z', $now);
			$objectService->saveObject(
				object: $round,
				register: 'decidiq',
				schema: 'voting-round',
				uuid: $roundId,
			);

			$this->logger->info(
				'Decidiq: voting deadline reminders sent',
				['roundId' => $roundId, 'sent' => $sent, 'pending' => count($pending)]
			);

			return $sent;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: deadline reminder failed for round',
				['roundId' => $roundId, 'exception' => $e->getMessage()]
			);
			return 0;
		}//end try

	}//end remindRound()

	/**
	 * Run a full reminder sweep (called by the hourly background job).
	 *
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return int Total notifications sent across all due rounds
	 */
	public function run(int $now): int {
		$total = 0;
		foreach ($this->findRoundsNeedingReminder(now: $now) as $round) {
			$total += $this->remindRound(round: $round, now: $now);
		}

		return $total;
	}//end run()

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
}//end class
