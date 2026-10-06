<?php

/**
 * Decidiq Vote Breakdown Service
 *
 * The result of a voting round per member, by name, and per faction. A
 * ballot names its voter only in its `relations` (VoteBallotFactory), so the
 * votes widget could not show who voted what; this reads the relations,
 * resolves each voter to their name and faction (Participant.party) and
 * counts per faction. A secret round gives its totals only.
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
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * A round's result per member and per faction.
 *
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
class VoteBreakdownService {

	/**
	 * Participants already read in this request: id => name and faction.
	 *
	 * @var array<string, array{name: string, faction: ?string}>
	 */
	private array $people = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ObjectRelationFilter $relationFilter Finds the ballots that reference a round
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectRelationFilter $relationFilter,
	) {
	}//end __construct()

	/**
	 * Whether the current user can read the round. OpenRegister decides: the
	 * round is looked up with the user's own rights.
	 *
	 * @param string $roundId The voting round UUID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
	 */
	public function canReadRound(string $roundId): bool {
		return $this->objectService->find(id: $roundId, register: 'decidiq', schema: 'voting-round') !== null;
	}//end canReadRound()

	/**
	 * The breakdown of one round, or null when the round cannot be read by
	 * the current user (OpenRegister's own access rules decide).
	 *
	 * @param string $roundId The voting round UUID
	 *
	 * @return array<string, mixed>|null secret, totals, members (by name) and factions (counts)
	 *
	 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
	 */
	public function forRound(string $roundId): ?array {
		$roundEntity = $this->objectService->find(id: $roundId, register: 'decidiq', schema: 'voting-round');
		if ($roundEntity === null) {
			return null;
		}

		$round = $roundEntity->jsonSerialize();
		$totals = [
			'for' => (int)($round['votesFor'] ?? 0),
			'against' => (int)($round['votesAgainst'] ?? 0),
			'abstain' => (int)($round['votesAbstain'] ?? 0),
		];

		if (($round['isSecret'] ?? false) === true) {
			return ['secret' => true, 'totals' => $totals, 'members' => [], 'factions' => []];
		}

		$ballots = $this->relationFilter->matching(
			entities: $this->objectService->findAll(
				config: ['filters' => (['register' => 'decidiq', 'schema' => 'vote'] + $this->relationFilter->filterFor(targetId: $roundId))],
				_rbac: false,
				_multitenancy: false
			),
			schema: 'voting-round',
			targetId: $roundId
		);

		$members = [];
		foreach ($ballots as $ballot) {
			$member = $this->memberRow(ballot: $ballot->jsonSerialize());
			if ($member !== null) {
				$members[] = $member;
			}
		}

		return ['secret' => false, 'totals' => $totals, 'members' => $members, 'factions' => $this->factionRows(members: $members)];
	}//end forRound()

	/**
	 * One member's line: who the vote counts for, their faction, the value
	 * and, for a proxy vote, who cast it.
	 *
	 * @param array<string, mixed> $ballot The ballot as stored
	 *
	 * @return array<string, mixed>|null
	 */
	private function memberRow(array $ballot): ?array {
		$voters = $this->voters(ballot: $ballot);
		$castBy = ($voters['participant'] ?? null);
		$memberId = ($voters['delegator'] ?? $castBy);
		if ($memberId === null) {
			return null;
		}

		$member = $this->person(participantId: $memberId);
		$row = [
			'participant' => $memberId,
			'name' => $member['name'],
			'faction' => $member['faction'],
			'value' => $this->valueOf(ballot: $ballot),
			'weight' => (int)($ballot['weight'] ?? 1),
			'castBy' => null,
			'castAt' => null,
			'ranking' => null,
		];

		if (is_string($ballot['castAt'] ?? null) === true) {
			$row['castAt'] = $ballot['castAt'];
		}

		if (is_array($ballot['ranking'] ?? null) === true) {
			$row['ranking'] = array_values($ballot['ranking']);
		}

		if ($castBy !== null && $castBy !== $memberId) {
			$row['castBy'] = $this->person(participantId: $castBy)['name'];
		}

		return $row;
	}//end memberRow()

	/**
	 * The participant ids a ballot names: the voter, and the member a proxy
	 * votes for. Reads the structured list the ballot is written with and the
	 * form OpenRegister flattens it into (`relations.N.id`).
	 *
	 * @param array<string, mixed> $ballot The ballot as stored
	 *
	 * @return array<string, string> participant and, for a proxy, delegator
	 */
	private function voters(array $ballot): array {
		$found = [];
		foreach ($this->relationEntries(ballot: $ballot) as $entry) {
			if (($entry['schema'] ?? '') !== 'participant' || is_string($entry['id'] ?? null) === false) {
				continue;
			}

			$found[(string)($entry['type'] ?? 'participant')] = $entry['id'];
		}

		return $found;
	}//end voters()

	/**
	 * The ballot's relations as a list of {id, schema, type} entries, from
	 * the structured list or from the flattened `relations.N.field` keys.
	 *
	 * @param array<string, mixed> $ballot The ballot as stored
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function relationEntries(array $ballot): array {
		$entries = [];
		foreach ([($ballot['relations'] ?? []), ($ballot['@self']['relations'] ?? [])] as $relations) {
			if (is_array($relations) === false) {
				continue;
			}

			foreach ($relations as $key => $value) {
				if (is_array($value) === true) {
					$entries[] = $value;
					continue;
				}

				if (is_string($key) === true && preg_match('/^relations\.(\d+)\.(\w+)$/', $key, $match) === 1) {
					$entries['flat' . $match[1]][$match[2]] = $value;
				}
			}
		}

		return $entries;
	}//end relationEntries()

	/**
	 * The name and faction of a participant, read once per request.
	 *
	 * @param string $participantId The participant UUID
	 *
	 * @return array{name: string, faction: ?string}
	 */
	private function person(string $participantId): array {
		if (isset($this->people[$participantId]) === false) {
			$entity = $this->objectService->find(
				id: $participantId,
				register: 'decidiq',
				schema: 'participant',
				_rbac: false,
				_multitenancy: false
			);
			$data = [];
			if ($entity !== null) {
				$data = $entity->jsonSerialize();
			}

			$faction = trim((string)($data['party'] ?? ''));
			if ($faction === '') {
				$faction = null;
			}

			$this->people[$participantId] = [
				'name' => (string)($data['displayName'] ?? ($data['name'] ?? $participantId)),
				'faction' => $faction,
			];
		}

		return $this->people[$participantId];
	}//end person()

	/**
	 * The value of a ballot as a string.
	 *
	 * @param array<string, mixed> $ballot The ballot as stored
	 *
	 * @return string|null
	 */
	private function valueOf(array $ballot): ?string {
		$value = ($ballot['value'] ?? null);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end valueOf()

	/**
	 * For, against and abstain counts per faction, factions in name order.
	 * Members without a faction are left out of this table; they still show
	 * by name.
	 *
	 * @param array<int, array<string, mixed>> $members The member rows
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function factionRows(array $members): array {
		$factions = [];
		foreach ($members as $member) {
			$faction = $member['faction'];
			if ($faction === null) {
				continue;
			}

			$factions[$faction] ??= ['faction' => $faction, 'for' => 0, 'against' => 0, 'abstain' => 0];
			$value = (string)$member['value'];
			if (isset($factions[$faction][$value]) === true && $value !== 'faction') {
				// Weighted like the round totals (vot-09); 1 on any other method.
				$factions[$faction][$value] += $member['weight'];
			}
		}

		ksort($factions);

		return array_values($factions);
	}//end factionRows()
}//end class
