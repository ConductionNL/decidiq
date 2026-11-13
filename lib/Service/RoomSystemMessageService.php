<?php

/**
 * Decidiq RoomSystemMessageService
 *
 * Applies a message from the chamber's conference and voting system: a speaker
 * marking becomes a speech on the member's engagement record, a desk vote a
 * named vote under decidiq's own voting rules.
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\EngagementServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a message's seat to one member of the meeting's body and applies it.
 *
 * Every outcome is written back on the message: `applied` with the member, or
 * `rejected` with a reason the clerk can act on. A rejected message stays and
 * can be applied again once the cause is fixed. Messages are read and written
 * in system context: the caller is the integriq synchronisation user (on
 * create) or a chair or secretary the controller already checked.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md
 */
class RoomSystemMessageService {

	public const SCHEMA = 'room-system-message';

	public const APPLIED = 'applied';

	public const REJECTED = 'rejected';

	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface     $objects    OpenRegister objects.
	 * @param EngagementServiceInterface $engagement Records a speech on the member's engagement record.
	 * @param VoteCastingService         $votes      Casts a vote under the voting rules.
	 * @param LoggerInterface            $logger     The logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly EngagementServiceInterface $engagement,
		private readonly VoteCastingService $votes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply a message by its id.
	 *
	 * @param string $messageId The message UUID.
	 *
	 * @return array<string, mixed>|null The message after applying, or null when it does not exist.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
	 */
	public function applyById(string $messageId): ?array {
		$message = $this->find(id: $messageId, schema: self::SCHEMA);
		if ($message === null) {
			return null;
		}

		return $this->apply(message: $message);
	}//end applyById()

	/**
	 * Apply a message and record the outcome on it.
	 *
	 * An already applied message is left as it is, so a repeated apply never
	 * casts or records twice.
	 *
	 * @param array<string, mixed> $message The message, with its `id`.
	 *
	 * @return array<string, mixed> The message as stored afterwards.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-004-a-speaker-marking-becomes-a-speech-on-the-members-engagement-record
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-005-a-desk-vote-becomes-a-named-vote-under-the-normal-voting-rules
	 */
	public function apply(array $message): array {
		if (($message['status'] ?? null) === self::APPLIED) {
			return $message;
		}

		try {
			$participant = $this->applyMessage(message: $message);
			return $this->record(message: $message, status: self::APPLIED, reason: null, participant: $participant);
		} catch (RoomSystemRefusal $refusal) {
			return $this->record(message: $message, status: self::REJECTED, reason: $refusal->getMessage(), participant: $refusal->participant);
		} catch (Throwable $error) {
			// A voting or engagement rule that refused: its own words are the reason.
			$this->logger->info('Decidiq: room system message not applied', ['message' => ($message['id'] ?? null), 'error' => $error->getMessage()]);
			return $this->record(message: $message, status: self::REJECTED, reason: $error->getMessage(), participant: null);
		}
	}//end apply()

	/**
	 * Apply one message; the member it was applied for.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The participant UUID.
	 *
	 * @throws RoomSystemRefusal For a message decidiq refuses on its own terms.
	 */
	private function applyMessage(array $message): string {
		$meetingId = (string)($message['meeting'] ?? '');
		$this->assertNotAlreadyApplied(message: $message, meetingId: $meetingId);
		$participant = $this->memberAtSeat(meetingId: $meetingId, seat: trim((string)($message['seat'] ?? '')));

		if (($message['kind'] ?? null) === 'speech') {
			$this->engagement->captureEngagement(
				meetingId: $meetingId,
				participant: $participant,
				eventType: 'speech',
				eventData: [
					'startTime'  => ($message['startedAt'] ?? null),
					'endTime'    => ($message['endedAt'] ?? null),
					'agendaItem' => ($message['agendaItem'] ?? null),
					'source'     => 'room-system',
				]
			);
			return $participant;
		}

		if (($message['kind'] ?? null) === 'vote') {
			$this->castDeskVote(message: $message, participant: $participant);
			return $participant;
		}

		throw new RoomSystemRefusal(message: 'The room system sent a message of an unknown kind.', participant: $participant);
	}//end applyMessage()

	/**
	 * Cast a desk vote through the voting rules, then mark the ballot as cast in person through the room system.
	 *
	 * @param array<string, mixed> $message     The vote message.
	 * @param string               $participant The member.
	 *
	 * @return void
	 *
	 * @throws RoomSystemRefusal For a secret round or a message without a round or a vote.
	 */
	private function castDeskVote(array $message, string $participant): void {
		$roundId = (string)($message['votingRound'] ?? '');
		$value = (string)($message['value'] ?? '');
		if ($roundId === '' || $value === '') {
			throw new RoomSystemRefusal(message: 'The vote names no voting round or no vote.', participant: $participant);
		}

		$round = $this->find(id: $roundId, schema: 'voting-round');
		if ($round !== null && (bool)($round['isSecret'] ?? false) === true) {
			throw new RoomSystemRefusal(message: 'A secret ballot cannot be read from the desk units', participant: $participant);
		}

		$vote = $this->votes->castVote(cast: new VoteCast(votingRoundId: $roundId, participantId: $participant, value: $value));
		$voteId = (string)($vote['id'] ?? ($vote['@self']['id'] ?? ''));
		if ($voteId === '') {
			return;
		}

		$stored = ($this->find(id: $voteId, schema: 'vote') ?? $vote);
		unset($stored['id'], $stored['@self']);
		$this->objects->saveObject(
			object: (['castAs' => 'in-person', 'castVia' => 'room-system'] + $stored),
			register: self::REGISTER,
			schema: 'vote',
			uuid: $voteId,
			_rbac: false,
			_multitenancy: false
		);
	}//end castDeskVote()

	/**
	 * Refuse a message whose external id was already applied in this meeting.
	 *
	 * @param array<string, mixed> $message   The message.
	 * @param string               $meetingId The meeting.
	 *
	 * @return void
	 *
	 * @throws RoomSystemRefusal When another message with the same id was applied.
	 */
	private function assertNotAlreadyApplied(array $message, string $meetingId): void {
		$externalId = (string)($message['externalId'] ?? '');
		if ($externalId === '') {
			return;
		}

		foreach ($this->rows(schema: self::SCHEMA, filters: ['meeting' => $meetingId, 'externalId' => $externalId]) as $other) {
			if (($other['id'] ?? null) !== ($message['id'] ?? null) && ($other['status'] ?? null) === self::APPLIED) {
				throw new RoomSystemRefusal(message: 'Already applied', participant: null);
			}
		}
	}//end assertNotAlreadyApplied()

	/**
	 * The one member of the meeting's body with this seat.
	 *
	 * @param string $meetingId The meeting.
	 * @param string $seat      The seat.
	 *
	 * @return string The participant UUID.
	 *
	 * @throws RoomSystemRefusal When no member, or more than one, has the seat.
	 */
	private function memberAtSeat(string $meetingId, string $seat): string {
		$filters = ['roomSeat' => $seat];
		$body = (string)($this->find(id: $meetingId, schema: 'meeting')['governanceBody'] ?? '');
		if ($body !== '') {
			$filters['governanceBody'] = $body;
		}

		$members = array_values(array_filter(
			$this->rows(schema: 'participant', filters: $filters),
			static fn (array $row): bool => trim((string)($row['roomSeat'] ?? '')) === $seat && $seat !== ''
		));
		if (count($members) === 1) {
			return (string)$members[0]['id'];
		}

		if ($members === []) {
			throw new RoomSystemRefusal(message: sprintf('Seat %s is not linked to a member', $seat), participant: null);
		}

		throw new RoomSystemRefusal(message: sprintf('Seat %s is linked to more than one member', $seat), participant: null);
	}//end memberAtSeat()

	/**
	 * Write the outcome on the message.
	 *
	 * @param array<string, mixed> $message     The message.
	 * @param string               $status      Applied or rejected.
	 * @param string|null          $reason      Why it was rejected.
	 * @param string|null          $participant The member it was applied for.
	 *
	 * @return array<string, mixed> The message as stored.
	 */
	private function record(array $message, string $status, ?string $reason, ?string $participant): array {
		$id = (string)($message['id'] ?? '');
		$fields = $message;
		unset($fields['id'], $fields['@self']);
		$fields['status'] = $status;
		$fields['rejectReason'] = $reason;
		$fields['participant'] = $participant;

		$saved = $this->objects->saveObject(
			object: $fields,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => (string)$saved->getUuid()] + (array)$saved->getObject();
	}//end record()

	/**
	 * One object as an array with its `id`, or null when it does not exist.
	 *
	 * @param string $id     The object UUID.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function find(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return ['id' => (string)$entity->getUuid()] + (array)$entity->getObject();
	}//end find()

	/**
	 * The objects of a schema matching the filters, as arrays with their `id`.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<array<string, mixed>> The objects.
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objects->findAll(
			config: ['filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters), 'limit' => 50],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $rows;
	}//end rows()
}//end class
