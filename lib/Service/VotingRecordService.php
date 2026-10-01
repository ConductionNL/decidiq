<?php

/**
 * Decidiq Voting Record Service
 *
 * A person's voting record: their votes in closed rounds that were not
 * secret, newest first, with the decision, the choice, the round's result and
 * the party they voted for at the time. Votes point at participants, so the
 * person's participants are found first, read-only, by PersonParticipantLookup;
 * the context of each ballot comes from VoteContextReader.
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
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reads a person's voting record.
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class VotingRecordService {

	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService  OpenRegister object service
	 * @param PersonParticipantLookup $participants   Person to participant lookup
	 * @param ObjectRelationFilter    $relationFilter Relation filter for app-cast ballots
	 * @param VoteContextReader       $context        Member, round, decision and body of a ballot
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly PersonParticipantLookup $participants,
		private readonly ObjectRelationFilter $relationFilter,
		private readonly VoteContextReader $context,
	) {
	}//end __construct()

	/**
	 * The person, read with the caller's own rights.
	 *
	 * @param string $personId The person id
	 *
	 * @return array<string, mixed>|null The person, or null when absent or not readable
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function findPerson(string $personId): ?array {
		$entity = $this->objectService->find(id: $personId, register: self::REGISTER, schema: 'person');
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findPerson()

	/**
	 * Refuse a person the caller may not read: OpenRegister answers with the
	 * caller's own rights, so a person they cannot see is refused here before
	 * any vote is read, the same way as one that does not exist.
	 *
	 * @param string $personId The person id
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the caller cannot read the person
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function requireReadablePerson(string $personId): void {
		if ($this->findPerson(personId: $personId) === null) {
			throw new DoesNotExistException('Person not found.');
		}
	}//end requireReadablePerson()

	/**
	 * A person's votes in closed rounds that were not secret, newest first.
	 *
	 * @param string $personId The person id
	 *
	 * @return list<array<string, mixed>> Rows of vote, date, decision, choice, result, party and body
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function forPerson(string $personId): array {
		$person = $this->context->object(schema: 'person', id: $personId);
		if ($person === null) {
			return [];
		}

		$rows = [];
		foreach ($this->participants->participantIdsOf(person: $person) as $participantId) {
			foreach ($this->votesOf(participantId: $participantId) as $voteId => $vote) {
				$row = $this->row(personId: $personId, participant: $participantId, voteId: $voteId, vote: $vote);
				if ($row !== null) {
					$rows[$voteId] = $row;
				}
			}
		}

		$rows = array_values($rows);
		usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

		return $rows;
	}//end forPerson()

	/**
	 * One record row, or null when the vote is not part of the record.
	 *
	 * @param string               $personId    The person id
	 * @param string               $participant The participant id
	 * @param string               $voteId      The vote id
	 * @param array<string, mixed> $vote        The ballot
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $personId, string $participant, string $voteId, array $vote): ?array {
		$choice = ($vote['value'] ?? null);
		if ($this->context->memberOf(vote: $vote) !== $participant || is_string($choice) === false || $choice === '') {
			return null;
		}

		$round = $this->context->roundOf(vote: $vote);
		if ($round === null || ($round[1]['isSecret'] ?? false) !== false || empty($round[1]['closedAt']) === true) {
			return null;
		}

		$date = ($vote['castAt'] ?? $round[1]['closedAt']);
		$decision = $this->context->decisionOf(roundId: $round[0], round: $round[1]);
		$body = null;
		$decisionRow = null;
		if ($decision !== null) {
			$body = $this->context->bodyOf(decision: $decision[1]);
			$decisionRow = ['id' => $decision[0], 'title' => (string)($decision[1]['title'] ?? '')];
		}

		return [
			'vote' => $voteId,
			'date' => $date,
			'decision' => $decisionRow,
			'choice' => $choice,
			'result' => ($round[1]['result'] ?? null),
			'party' => $this->partyAt(personId: $personId, participant: $participant, bodyId: $body, date: (string)$date),
			'body' => $body,
		];
	}//end row()

	/**
	 * The ballots that name a participant, in either stored shape, by vote id.
	 *
	 * @param string $participantId The participant id
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function votesOf(string $participantId): array {
		$byProperty = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'vote', 'participant' => $participantId]],
			_rbac: false,
			_multitenancy: false
		);
		$byRelation = $this->relationFilter->matching(
			entities: $this->objectService->findAll(
				config: ['filters' => (['register' => self::REGISTER, 'schema' => 'vote'] + $this->relationFilter->filterFor(targetId: $participantId))],
				_rbac: false,
				_multitenancy: false
			),
			schema: 'participant',
			targetId: $participantId
		);

		$votes = [];
		foreach (array_merge($byProperty, $byRelation) as $entity) {
			$vote = $entity->jsonSerialize();
			$id = ($vote['id'] ?? ($vote['@self']['id'] ?? null));
			if (is_string($id) === true && $id !== '') {
				$votes[$id] = $vote;
			}
		}

		return $votes;
	}//end votesOf()

	/**
	 * The party of the person at a date: the membership active then, in the
	 * vote's body when known, else the participant's own party.
	 *
	 * @param string      $personId    The person id
	 * @param string      $participant The participant id
	 * @param string|null $bodyId      The body the vote was in, when known
	 * @param string      $date        The vote's date
	 *
	 * @return string|null
	 */
	private function partyAt(string $personId, string $participant, ?string $bodyId, string $date): ?string {
		if ($bodyId !== null) {
			$memberships = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => 'membership', 'person' => $personId, 'governanceBody' => $bodyId]],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($memberships as $entity) {
				$membership = $entity->jsonSerialize();
				$party = trim((string)($membership['party'] ?? ''));
				if ($party !== '' && self::activeOn(membership: $membership, date: $date) === true) {
					return $party;
				}
			}
		}

		$party = trim((string)($this->context->object(schema: 'participant', id: $participant)['party'] ?? ''));
		if ($party === '') {
			return null;
		}

		return $party;
	}//end partyAt()

	/**
	 * Whether a membership ran on a date (ISO strings compare by their date part).
	 *
	 * @param array<string, mixed> $membership The membership
	 * @param string               $date       An ISO date or date-time
	 *
	 * @return bool
	 */
	private static function activeOn(array $membership, string $date): bool {
		$day = substr($date, 0, 10);
		$start = substr((string)($membership['startDate'] ?? ''), 0, 10);
		$end = substr((string)($membership['endDate'] ?? ''), 0, 10);

		return ($start === '' || $start <= $day) && ($end === '' || $end >= $day);
	}//end activeOn()
}//end class
