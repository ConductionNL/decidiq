<?php

/**
 * Decidiq Mandate Substitution Service
 *
 * Starts and ends a mandate swap in a meeting and reads the meeting's seats
 * (bodies-substitute-mandate-swap, design D3). Only the meeting's chair or
 * secretary swaps, never while a vote of the meeting is open, only a member
 * is swapped out, and only a non-voting participant of the body takes the
 * seat. The seat, party, role and voting weight are copied from the member.
 * The substitution is written in system context because its schema leaves the
 * write verbs out, so these checks cannot be gone around on the object API.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Exception\SubstitutionRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Start, end and read the seats of a meeting.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
 */
class MandateSubstitutionService {

	/**
	 * The roles that vote in a body. A substitute holds none of them.
	 *
	 * @var list<string>
	 */
	private const VOTING_ROLES = ['chair', 'vice-chair', 'secretary', 'member'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService       Reads rounds, writes substitutions
	 * @param ParticipantResolver    $participantResolver The meeting's participants
	 * @param MeetingRoleGate        $roleGate            Who presides over the meeting
	 * @param AmendmentOrderService  $amendmentOrder      The meeting a voting round belongs to
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly MeetingRoleGate $roleGate,
		private readonly AmendmentOrderService $amendmentOrder,
	) {
	}//end __construct()

	/**
	 * The meeting's participants with their seats, every substitution of the
	 * meeting, and whether the caller may swap.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The caller
	 *
	 * @return array{participants: list<array<string, mixed>>, substitutions: list<array<string, mixed>>, canSubstitute: bool}
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
	 */
	public function seats(string $meetingId, string $userId): array {
		$resolver = $this->resolver();
		$participants = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $row) {
			$participants[] = [
				'id'           => $resolver->idOf(row: $row),
				'displayName'  => (string)($row['displayName'] ?? ''),
				'role'         => (string)($row['role'] ?? ''),
				'party'        => (string)($row['party'] ?? ''),
				'seatNumber'   => $row['seatNumber'] ?? null,
				'votingWeight' => $row['votingWeight'] ?? null,
			];
		}

		usort(
			$participants,
			static fn (array $a, array $b): int => [($a['seatNumber'] ?? PHP_INT_MAX), $a['displayName']] <=> [($b['seatNumber'] ?? PHP_INT_MAX), $b['displayName']]
		);

		return [
			'participants'  => $participants,
			'substitutions' => $resolver->allFor(meetingId: $meetingId),
			'canSubstitute' => $this->roleGate->isChairOrSecretary(meetingId: $meetingId, userId: $userId),
		];

	}//end seats()

	/**
	 * Swap a member for a substitute.
	 *
	 * @param string $meetingId     The meeting
	 * @param string $outgoingId    The member who leaves
	 * @param string $incomingId    The substitute who takes the seat
	 * @param string $reason        Why the member leaves
	 * @param string $userId        The caller, recorded as `recordedBy`
	 *
	 * @return array<string, mixed> The stored substitution, with its id
	 *
	 * @throws SubstitutionRefusedException 403 not presiding, 409 a vote is open or a seat is taken, 400 the seat plan does not allow it
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	public function start(string $meetingId, string $outgoingId, string $incomingId, string $reason, string $userId): array {
		$this->assertPresiding(meetingId: $meetingId, userId: $userId);
		$this->assertNoOpenRound(meetingId: $meetingId);

		if ($outgoingId === '' || $outgoingId === $incomingId) {
			throw new SubstitutionRefusedException(message: 'A member cannot be swapped for themselves.', status: 400);
		}

		$resolver = $this->resolver();
		$byId = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $row) {
			$byId[$resolver->idOf(row: $row)] = $row;
		}

		$outgoing = ($byId[$outgoingId] ?? null);
		if ($outgoing === null || ($outgoing['role'] ?? '') !== 'member') {
			throw new SubstitutionRefusedException(message: 'Only members can be substituted.', status: 400);
		}

		if ($resolver->isSubstitutedOut(meetingId: $meetingId, participantId: $outgoingId) === true) {
			throw new SubstitutionRefusedException(message: 'This member\'s seat is already held by a substitute.', status: 409);
		}

		$incoming = ($byId[$incomingId] ?? null);
		if ($incoming === null) {
			throw new SubstitutionRefusedException(message: 'The substitute is not a participant of the meeting\'s body.', status: 400);
		}

		if (in_array(($incoming['role'] ?? ''), self::VOTING_ROLES, true) === true) {
			throw new SubstitutionRefusedException(message: 'The substitute already votes in this body.', status: 400);
		}

		if (in_array($incomingId, $resolver->activeSubstitutes(meetingId: $meetingId), true) === true) {
			throw new SubstitutionRefusedException(message: 'The substitute already holds another seat.', status: 409);
		}

		$record = [
			'meeting'             => $meetingId,
			'outgoingParticipant' => $outgoingId,
			'incomingParticipant' => $incomingId,
			'seatNumber'          => $outgoing['seatNumber'] ?? null,
			'party'               => $outgoing['party'] ?? null,
			'role'                => 'member',
			'votingWeight'        => $outgoing['votingWeight'] ?? null,
			'startedAt'           => $this->now(),
			'recordedBy'          => $userId,
			'reason'              => $reason,
		];

		return $this->write(object: array_filter($record, static fn (mixed $value): bool => $value !== null && $value !== ''), id: null);

	}//end start()

	/**
	 * End a substitution: the seat goes back to the member, and the record stays.
	 *
	 * @param string $meetingId      The meeting
	 * @param string $substitutionId The substitution
	 * @param string $userId         The caller
	 *
	 * @return array<string, mixed> The stored substitution, with its id
	 *
	 * @throws SubstitutionRefusedException 403 not presiding, 404 no such substitution in the meeting, 409 a vote is open or it has ended
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
	 */
	public function end(string $meetingId, string $substitutionId, string $userId): array {
		$this->assertPresiding(meetingId: $meetingId, userId: $userId);
		$this->assertNoOpenRound(meetingId: $meetingId);

		$entity = $this->objectService->find(
			id: $substitutionId,
			register: 'decidiq',
			schema: SubstitutionResolver::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		$record = [];
		if ($entity !== null) {
			$record = $entity->jsonSerialize();
		}

		if ($record === [] || ($record['meeting'] ?? null) !== $meetingId) {
			throw new SubstitutionRefusedException(message: 'This meeting has no such substitution.', status: 404);
		}

		if ((string)($record['endedAt'] ?? '') !== '') {
			throw new SubstitutionRefusedException(message: 'This substitution has already ended.', status: 409);
		}

		unset($record['@self'], $record['id']);
		$record['endedAt'] = $this->now();

		return $this->write(object: $record, id: $substitutionId);

	}//end end()

	/**
	 * Refuse a caller who is not the meeting's chair or secretary (or an admin).
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The caller
	 *
	 * @return void
	 *
	 * @throws SubstitutionRefusedException 403
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
	 */
	private function assertPresiding(string $meetingId, string $userId): void {
		if ($this->roleGate->isChairOrSecretary(meetingId: $meetingId, userId: $userId) === false) {
			throw new SubstitutionRefusedException(message: 'Only the chair or the secretary of the meeting can swap seats.', status: 403);
		}
	}//end assertPresiding()

	/**
	 * Refuse while a voting round of the meeting is open.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return void
	 *
	 * @throws SubstitutionRefusedException 409
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	private function assertNoOpenRound(string $meetingId): void {
		$rounds = $this->objectService->findAll(['filters' => ['register' => 'decidiq', 'schema' => 'voting-round'], 'limit' => 1000]);
		foreach ($rounds as $entity) {
			$round = $entity->jsonSerialize();
			if ($this->isOpen(round: $round) === true
				&& $this->amendmentOrder->resolveMeetingIdForRound(round: $round) === $meetingId
			) {
				throw new SubstitutionRefusedException(message: 'A vote is in progress in this meeting. Swap seats after the round closes.', status: 409);
			}
		}
	}//end assertNoOpenRound()

	/**
	 * Whether a voting round is open: opened, and not closed or closing later.
	 *
	 * @param array<string, mixed> $round The round
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	private function isOpen(array $round): bool {
		if ((string)($round['openedAt'] ?? '') === '') {
			return false;
		}

		$closedAt = (string)($round['closedAt'] ?? '');
		return $closedAt === '' || strtotime($closedAt) > time();
	}//end isOpen()

	/**
	 * A fresh resolver, so a start or end reads the substitutions as they are now.
	 *
	 * @return SubstitutionResolver
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	private function resolver(): SubstitutionResolver {
		return new SubstitutionResolver(objectService: $this->objectService);
	}//end resolver()

	/**
	 * The current time as an ISO 8601 date-time.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DATE_ATOM);
	}//end now()

	/**
	 * Write a substitution in system context.
	 *
	 * @param array<string, mixed> $object The complete object
	 * @param string|null          $id     The uuid when it exists
	 *
	 * @return array<string, mixed> The stored object, with its id
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
	 */
	private function write(array $object, ?string $id): array {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: 'decidiq',
			schema: SubstitutionResolver::SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
