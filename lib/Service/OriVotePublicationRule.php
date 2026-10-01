<?php

/**
 * Decidiq ORI vote publication rule
 *
 * Decides which votes and vote events the public ORI API may publish, and
 * gives each the fields an anonymous caller may read.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The publication rule for ORI `votes` and `voteevents`.
 *
 * A vote event is public when its round is closed and the decision it
 * resolves has `isPublished: public`. A vote is public when its vote event is
 * public, the round is not secret, the vote has a value, and the body that
 * took the decision has `publishVotingRecords: true`. Everything is read in
 * system context because the rule, not the caller's rights, is the gate; only
 * the allow-listed fields below ever leave this class. Anything the rule
 * cannot resolve is withheld.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
 */
class OriVotePublicationRule {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * How many ballots or rounds one collection request reads.
	 */
	private const LIMIT = 100;

	/**
	 * A vote's value as an ORI option.
	 *
	 * @var array<string, string>
	 */
	private const OPTIONS = [
		'for' => 'yes',
		'against' => 'no',
		'abstain' => 'abstain',
	];

	/**
	 * Constructor.
	 *
	 * @param VotingRecordService     $records       The record reader (a person's ballots, party)
	 * @param VoteContextReader       $context       Member, round, decision and body of a ballot
	 * @param PersonParticipantLookup $people        Person and participant matching
	 * @param ObjectServiceInterface  $objectService OpenRegister object service
	 */
	public function __construct(
		private readonly VotingRecordService $records,
		private readonly VoteContextReader $context,
		private readonly PersonParticipantLookup $people,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The public votes, optionally of one person only.
	 *
	 * @param string|null $voter A person id, or null for every voter
	 *
	 * @return list<array<string, mixed>> The ORI vote fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function votes(?string $voter): array {
		if ($voter === '') {
			$voter = null;
		}

		$items = [];
		foreach ($this->ballots(voter: $voter) as $id => $vote) {
			$fields = $this->publicVote(voteId: (string)$id, vote: $vote);
			if ($fields !== null && ($voter === null || $fields['voter'] === $voter)) {
				$items[] = $fields;
			}
		}

		return $items;
	}//end votes()

	/**
	 * The ballots to judge: every ballot, or the ballots of one person's participants.
	 *
	 * @param string|null $voter A person id, or null for every voter
	 *
	 * @return array<string, array<string, mixed>> Ballots by id
	 */
	private function ballots(?string $voter): array {
		if ($voter === null) {
			return $this->all(schema: 'vote');
		}

		$person = $this->context->object(schema: 'person', id: $voter);
		if ($person === null) {
			return [];
		}

		$ballots = [];
		foreach ($this->people->participantIdsOf(person: $person) as $participantId) {
			$ballots += $this->records->votesOf(participantId: $participantId);
		}

		return $ballots;
	}//end ballots()

	/**
	 * One public vote by id, or null when the rule withholds it.
	 *
	 * @param string $voteId The vote id
	 *
	 * @return array<string, mixed>|null The ORI vote fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function vote(string $voteId): ?array {
		$vote = $this->context->object(schema: 'vote', id: $voteId);
		if ($vote === null) {
			return null;
		}

		return $this->publicVote(voteId: $voteId, vote: $vote);
	}//end vote()

	/**
	 * The public vote events.
	 *
	 * @return list<array<string, mixed>> The ORI vote event fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function voteEvents(): array {
		$items = [];
		foreach ($this->all(schema: 'voting-round') as $id => $round) {
			$fields = $this->publicVoteEvent(roundId: (string)$id, round: $round);
			if ($fields !== null) {
				$items[] = $fields;
			}
		}

		return $items;
	}//end voteEvents()

	/**
	 * One public vote event by id, or null when the rule withholds it.
	 *
	 * @param string $roundId The voting round id
	 *
	 * @return array<string, mixed>|null The ORI vote event fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function voteEvent(string $roundId): ?array {
		$round = $this->context->object(schema: 'voting-round', id: $roundId);
		if ($round === null) {
			return null;
		}

		return $this->publicVoteEvent(roundId: $roundId, round: $round);
	}//end voteEvent()

	/**
	 * The vote event fields of a round, or null when the round is not public:
	 * it is closed and its decision is published.
	 *
	 * @param string               $roundId The round id
	 * @param array<string, mixed> $round   The round
	 *
	 * @return array<string, mixed>|null
	 */
	private function publicVoteEvent(string $roundId, array $round): ?array {
		if (empty($round['closedAt']) === true) {
			return null;
		}

		$decision = $this->context->decisionOf(roundId: $roundId, round: $round);
		if ($decision === null || ($decision[1]['isPublished'] ?? null) !== 'public') {
			return null;
		}

		return [
			'id' => $roundId,
			'motion' => $decision[0],
			'result' => ($round['result'] ?? null),
			'start_date' => ($round['openedAt'] ?? null),
			'end_date' => $round['closedAt'],
			'counts' => [
				['option' => 'yes', 'value' => (int)($round['votesFor'] ?? 0)],
				['option' => 'no', 'value' => (int)($round['votesAgainst'] ?? 0)],
				['option' => 'abstain', 'value' => (int)($round['votesAbstain'] ?? 0)],
			],
		];
	}//end publicVoteEvent()

	/**
	 * The vote fields of a ballot, or null when the rule withholds it.
	 *
	 * @param string               $voteId The vote id
	 * @param array<string, mixed> $vote   The ballot
	 *
	 * @return array{id: string, voter: ?string, option: string, vote_event: string, group: ?string}|null
	 */
	private function publicVote(string $voteId, array $vote): ?array {
		$option = (self::OPTIONS[(string)($vote['value'] ?? '')] ?? null);
		$context = $this->publicContext(vote: $vote);
		if ($option === null || $context === null) {
			return null;
		}

		[$round, $bodyId] = $context;
		$participantId = $this->context->memberOf(vote: $vote);
		if ($participantId === null) {
			return null;
		}

		$participant = ($this->context->object(schema: 'participant', id: $participantId) ?? []);
		$voter = $this->people->personIdOf(participant: $participant);

		return [
			'id' => $voteId,
			'voter' => $voter,
			'option' => $option,
			'vote_event' => $round[0],
			'group' => $this->records->partyAt(
				personId: ($voter ?? ''),
				participant: $participantId,
				bodyId: $bodyId,
				date: (string)($vote['castAt'] ?? $round[1]['closedAt'])
			),
		];
	}//end publicVote()

	/**
	 * The round and body of a ballot when both may be published: the round is
	 * closed and not secret, its decision is published and the body publishes
	 * voting records. Null when any of that fails or cannot be resolved.
	 *
	 * @param array<string, mixed> $vote The ballot
	 *
	 * @return array{0: array{0: string, 1: array<string, mixed>}, 1: string}|null The round and the body id
	 */
	private function publicContext(array $vote): ?array {
		$round = $this->context->roundOf(vote: $vote);
		if ($round === null || ($round[1]['isSecret'] ?? true) !== false || empty($round[1]['closedAt']) === true) {
			return null;
		}

		$decision = $this->context->decisionOf(roundId: $round[0], round: $round[1]);
		if ($decision === null || ($decision[1]['isPublished'] ?? null) !== 'public') {
			return null;
		}

		$bodyId = $this->context->bodyOfRound(roundId: $round[0], round: $round[1], decision: $decision[1]);
		if ($bodyId === null || ($this->context->object(schema: 'governance-body', id: $bodyId)['publishVotingRecords'] ?? false) !== true) {
			return null;
		}

		return [$round, $bodyId];
	}//end publicContext()

	/**
	 * Objects of one schema, read in system context, by id.
	 *
	 * @param string $schema The schema slug
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(string $schema): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$objects = [];
		foreach ($entities as $entity) {
			$object = $entity->jsonSerialize();
			$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
			if (is_string($id) === true && $id !== '') {
				$objects[$id] = $object;
			}
		}

		return $objects;
	}//end all()
}//end class
