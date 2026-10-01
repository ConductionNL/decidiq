<?php

/**
 * Decidiq Vote Context Reader
 *
 * The context of a single ballot: the member it counts for, the round it was
 * cast in, the decision that round resolves and the body that took it. Read
 * without the caller's RBAC and once per request, so a voting record of many
 * ballots reads each round, decision and meeting only once.
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

/**
 * Reads the member, round, decision and body of a ballot.
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class VoteContextReader {

	private const REGISTER = 'decidiq';

	/**
	 * Objects already read in this request: schema => id => data (null = absent).
	 *
	 * @var array<string, array<string, array<string, mixed>|null>>
	 */
	private array $read = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The member a ballot belongs to: the delegator of a proxy ballot, else the caster.
	 *
	 * @param array<string, mixed> $vote The ballot
	 *
	 * @return string|null The participant id
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function memberOf(array $vote): ?string {
		$found = [];
		foreach ($this->relationsOf(object: $vote) as $relation) {
			if (($relation['schema'] ?? '') === 'participant' && is_string($relation['id'] ?? null) === true) {
				$found[(string)($relation['type'] ?? 'participant')] = $relation['id'];
			}
		}

		$member = ($found['delegator'] ?? ($found['participant'] ?? ($vote['participant'] ?? null)));
		if (is_string($member) === true && $member !== '') {
			return $member;
		}

		return null;
	}//end memberOf()

	/**
	 * The round a ballot was cast in.
	 *
	 * @param array<string, mixed> $vote The ballot
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The round id and round
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function roundOf(array $vote): ?array {
		$roundId = ($vote['votingRound'] ?? null);
		foreach ($this->relationsOf(object: $vote) as $relation) {
			if (($relation['schema'] ?? '') === 'voting-round' && is_string($relation['id'] ?? null) === true) {
				$roundId = $relation['id'];
			}
		}

		if (is_string($roundId) === false || $roundId === '') {
			return null;
		}

		$round = $this->object(schema: 'voting-round', id: $roundId);
		if ($round === null) {
			return null;
		}

		return [$roundId, $round];
	}//end roundOf()

	/**
	 * The decision a round resolves, through its decision stage.
	 *
	 * @param string               $roundId The round id
	 * @param array<string, mixed> $round   The round
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The decision id and decision
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function decisionOf(string $roundId, array $round): ?array {
		$stage = null;
		$stageId = ($round['decisionStage'] ?? null);
		if (is_string($stageId) === true && $stageId !== '') {
			$stage = $this->object(schema: 'decision-stage', id: $stageId);
		}

		if ($stage === null) {
			$stages = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => 'decision-stage', 'votingRound' => $roundId], 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
			if ($stages !== []) {
				$stage = $stages[0]->jsonSerialize();
			}
		}

		$decisionId = ($stage['decision'] ?? null);
		if (is_string($decisionId) === false || $decisionId === '') {
			return null;
		}

		$decision = $this->object(schema: 'decision', id: $decisionId);
		if ($decision === null) {
			return null;
		}

		return [$decisionId, $decision];
	}//end decisionOf()

	/**
	 * The body that took a decision: the body of its meeting.
	 *
	 * @param array<string, mixed> $decision The decision
	 *
	 * @return string|null The governance body id
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function bodyOf(array $decision): ?string {
		$meetingId = ($decision['meeting'] ?? null);
		if (is_string($meetingId) === false || $meetingId === '') {
			return null;
		}

		$body = ($this->object(schema: 'meeting', id: $meetingId)['governanceBody'] ?? null);
		if (is_string($body) === true && $body !== '') {
			return $body;
		}

		return null;
	}//end bodyOf()

	/**
	 * An object by id, read once per request and without the caller's RBAC.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The object id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function object(string $schema, string $id): ?array {
		if (array_key_exists($id, ($this->read[$schema] ?? [])) === false) {
			$entity = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
			$this->read[$schema][$id] = null;
			if ($entity !== null) {
				$this->read[$schema][$id] = $entity->jsonSerialize();
			}
		}

		return $this->read[$schema][$id];
	}//end object()

	/**
	 * The relation entries of an object, in either place OpenRegister keeps them.
	 *
	 * @param array<string, mixed> $object The object
	 *
	 * @return list<array<string, mixed>>
	 */
	private function relationsOf(array $object): array {
		$entries = [];
		foreach ([($object['relations'] ?? []), ($object['@self']['relations'] ?? [])] as $relations) {
			if (is_array($relations) === false) {
				continue;
			}

			foreach ($relations as $relation) {
				if (is_array($relation) === true) {
					$entries[] = $relation;
				}
			}
		}

		return $entries;
	}//end relationsOf()
}//end class
