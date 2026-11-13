<?php

/**
 * Decidiq RecordedVoteWriter: the chair or secretary records each member's choice on a roll call round.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Exception\RecordedVotesRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates a whole recorded list before writing anything, then writes one vote per member.
 *
 * Each vote is built by the same factory as a cast vote, so its slug and
 * relations are the same and a corrected list replaces the earlier recorded
 * votes instead of adding to them. A member left out has no vote.
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
class RecordedVoteWriter {

	/**
	 * The method whose votes are recorded by name.
	 *
	 * @var string
	 */
	public const METHOD = 'roll-call';

	/**
	 * The values a recorded vote may take.
	 *
	 * @var string[]
	 */
	private const VALUES = ['for', 'against', 'abstain'];

	/**
	 * Builds a vote the way a cast vote is built.
	 *
	 * @var VoteBallotFactory
	 */
	private readonly VoteBallotFactory $ballots;

	/**
	 * Matches votes to their round and participant.
	 *
	 * @var ObjectRelationFilter
	 */
	private readonly ObjectRelationFilter $relationFilter;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService       OpenRegister objects.
	 * @param ParticipantResolver    $participantResolver The meeting's participants.
	 * @param AmendmentOrderService  $amendmentOrder      Resolves the round's meeting.
	 * @param ContainerInterface     $container           For the ballot factory.
	 * @param LoggerInterface        $logger              For the ballot factory.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly AmendmentOrderService $amendmentOrder,
		ContainerInterface $container,
		LoggerInterface $logger,
	) {
		$this->ballots = new VoteBallotFactory(container: $container, logger: $logger);
		$this->relationFilter = new ObjectRelationFilter();
	}//end __construct()

	/**
	 * Record the list, or refuse it as a whole.
	 *
	 * @param string                                    $votingRoundId The round.
	 * @param array<int, array{participantId?: mixed, value?: mixed}> $entries The members' choices.
	 * @param string|null                               $heldAt        When the vote was held, ISO 8601; now when null.
	 * @param string                                    $recordedBy    The caller's user id.
	 *
	 * @return array{votingRoundId: string, recorded: int}
	 *
	 * @throws RecordedVotesRefusedException When the round or any entry is wrong; nothing is written then.
	 *
	 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
	 */
	public function record(string $votingRoundId, array $entries, ?string $heldAt, string $recordedBy): array {
		$round = $this->openRollCall(votingRoundId: $votingRoundId);
		$choices = $this->validEntries(round: $round, entries: $entries);
		$existing = $this->existingVotes(votingRoundId: $votingRoundId, participantIds: array_keys($choices));
		$this->refuseOwnVotes(existing: $existing);

		$castAt = $this->castAt(heldAt: $heldAt);
		foreach ($choices as $participantId => $value) {
			$cast = (new VoteCast(votingRoundId: $votingRoundId, participantId: (string)$participantId, value: $value))
				->forRound(value: $value, isSecret: false);
			$vote = $this->ballots->buildVote(cast: $cast, existingVote: ($existing[$participantId] ?? null));
			$vote['entryMode'] = 'recorded';
			$vote['recordedBy'] = $recordedBy;
			$vote['castAt'] = $castAt;
			$this->objectService->saveObject(register: 'decidiq', schema: 'vote', object: $vote);
		}

		return ['votingRoundId' => $votingRoundId, 'recorded' => count($choices)];
	}//end record()

	/**
	 * The round, when it is an open, public roll call.
	 *
	 * @param string $votingRoundId The round.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RecordedVotesRefusedException When it is not.
	 */
	private function openRollCall(string $votingRoundId): array {
		try {
			$entity = $this->objectService->find(id: $votingRoundId, register: 'decidiq', schema: 'voting-round');
		} catch (Throwable) {
			$entity = null;
		}

		if ($entity === null) {
			throw new RecordedVotesRefusedException(message: 'This voting round does not exist.', status: 404);
		}

		$round = $entity->jsonSerialize();
		if (($round['isSecret'] ?? false) === true) {
			throw new RecordedVotesRefusedException(message: 'A secret ballot cannot be recorded by name.');
		}

		if (($round['votingMethod'] ?? '') !== self::METHOD) {
			throw new RecordedVotesRefusedException(message: 'Votes are recorded by name only on a roll call round.');
		}

		$closedAt = ($round['closedAt'] ?? null);
		if (($round['openedAt'] ?? null) === null || ($closedAt !== null && $closedAt !== '')) {
			throw new RecordedVotesRefusedException(message: 'Votes can only be recorded while the round is open.');
		}

		return $round;
	}//end openRollCall()

	/**
	 * The choices by participant, when every entry is right.
	 *
	 * @param array<string, mixed>                       $round   The round.
	 * @param array<int, array<string, mixed>|mixed>     $entries The posted entries.
	 *
	 * @return array<string, string> Value by participant id.
	 *
	 * @throws RecordedVotesRefusedException Naming every unknown, repeated or wrongly valued entry.
	 */
	private function validEntries(array $round, array $entries): array {
		if ($entries === []) {
			throw new RecordedVotesRefusedException(message: 'The list names nobody.');
		}

		$active = $this->activeParticipantIds(round: $round);
		$choices = [];
		$wrong = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				$entry = [];
			}

			$participantId = (string)($entry['participantId'] ?? '');
			$value = ($entry['value'] ?? null);
			if ($participantId === '' || isset($active[$participantId]) === false || isset($choices[$participantId]) === true
				|| in_array($value, self::VALUES, true) === false
			) {
				$wrong[] = $participantId;
				continue;
			}

			$choices[$participantId] = $value;
		}

		if ($wrong !== []) {
			throw new RecordedVotesRefusedException(
				message: 'These entries are not active participants of the meeting, are listed twice or have no for, against or abstain: '.implode(', ', $wrong),
				participantIds: $wrong
			);
		}

		return $choices;
	}//end validEntries()

	/**
	 * The meeting's participants who have not left, by id.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return array<string, true>
	 */
	private function activeParticipantIds(array $round): array {
		$meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);
		if ($meetingId === null) {
			return [];
		}

		$active = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $participant) {
			$left = (string)($participant['leftAt'] ?? '');
			$id = (string)($participant['id'] ?? '');
			if ($id !== '' && $left === '') {
				$active[$id] = true;
			}
		}

		return $active;
	}//end activeParticipantIds()

	/**
	 * The listed members' votes already on the round, by participant.
	 *
	 * @param string   $votingRoundId  The round.
	 * @param string[] $participantIds The listed members.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function existingVotes(string $votingRoundId, array $participantIds): array {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('vote');
		$inRound = $this->relationFilter->matching(
			entities: $this->objectService->findAll(['filters' => $this->relationFilter->filterFor(targetId: $votingRoundId)]),
			schema: 'voting-round',
			targetId: $votingRoundId
		);

		$existing = [];
		foreach ($participantIds as $participantId) {
			foreach ($this->relationFilter->matching(entities: $inRound, schema: 'participant', targetId: (string)$participantId) as $entity) {
				$existing[(string)$participantId] = $entity->jsonSerialize();
				break;
			}
		}

		return $existing;
	}//end existingVotes()

	/**
	 * Refuse a list that would replace a vote a member cast themselves.
	 *
	 * @param array<string, array<string, mixed>> $existing The listed members' votes on the round.
	 *
	 * @return void
	 *
	 * @throws RecordedVotesRefusedException Naming those members, with 409.
	 */
	private function refuseOwnVotes(array $existing): void {
		$own = [];
		foreach ($existing as $participantId => $vote) {
			if (($vote['entryMode'] ?? 'cast') !== 'recorded') {
				$own[] = (string)$participantId;
			}
		}

		if ($own !== []) {
			throw new RecordedVotesRefusedException(
				message: 'These members cast their own vote, which is not replaced: '.implode(', ', $own),
				status: 409,
				participantIds: $own
			);
		}
	}//end refuseOwnVotes()

	/**
	 * When the vote was held, as stored.
	 *
	 * @param string|null $heldAt The posted moment.
	 *
	 * @return string ISO 8601.
	 *
	 * @throws RecordedVotesRefusedException When the moment cannot be read.
	 */
	private function castAt(?string $heldAt): string {
		if ($heldAt === null || trim($heldAt) === '') {
			return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		}

		try {
			return (new DateTimeImmutable($heldAt))->format(DateTimeInterface::ATOM);
		} catch (Throwable) {
			throw new RecordedVotesRefusedException(message: 'heldAt is not a date and time.');
		}
	}//end castAt()
}//end class
