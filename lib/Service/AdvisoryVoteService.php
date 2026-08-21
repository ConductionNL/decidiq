<?php

/**
 * Decidesk Advisory Vote Service
 *
 * Advisory (non-statutory) citizen votes on a BudgetProposal.
 *
 * Extracted from VotingService so the advisory path — no quorum, no secret
 * ballot, no proxy, voor/tegen only — cannot be confused with, or accidentally
 * mixed into, the statutory VotingRound tallies. That separation is an
 * invariant of the voting-system spec, and it is easier to hold when the two
 * live in different classes.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Advisory citizen votes on BudgetProposals, kept separate from the statutory
 * VotingRound tallies.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/citizen-participation/spec.md
 */
class AdvisoryVoteService {

	/**
	 * Exact-id scoping for OpenRegister relation filters.
	 *
	 * @var ObjectRelationFilter
	 */
	private ObjectRelationFilter $relationFilter;

	/**
	 * Constructor for AdvisoryVoteService.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->relationFilter = new ObjectRelationFilter();

	}//end __construct()

	/**
	 * Record one advisory citizen vote and return the recomputed tally.
	 *
	 * The integrity rules are:
	 * - one vote per citizen per proposal (duplicate => RuntimeException);
	 * - atomic upsert via a deterministic @self.slug so concurrent casts converge;
	 * - the proposal tally is recomputed by counting CitizenVote objects, never mixed
	 *   with statutory VotingRound Vote tallies (separation invariant).
	 *
	 * No quorum, no secret ballot, no proxy — voor/tegen only. The caller
	 * (BudgetVotingService) enforces the voting-window guard; this method enforces
	 * the value enum and the one-vote-per-citizen integrity.
	 *
	 * @param string $proposalId The validated BudgetProposal UUID.
	 * @param string $voterId The authenticated citizen NC UID.
	 * @param string $value 'voor' | 'tegen'.
	 *
	 * @return array<string,mixed> ['vote' => <CitizenVote>, 'votesFor' => int, 'votesAgainst' => int].
	 *
	 * @throws \RuntimeException When the proposal is missing or a duplicate vote exists.
	 * @throws \InvalidArgumentException When the value is not voor/tegen.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function applyAdvisoryTally(string $proposalId, string $voterId, string $value): array {
		if (in_array($value, ['voor', 'tegen'], true) === false) {
			throw new InvalidArgumentException("Advisory vote value must be 'voor' or 'tegen'");
		}

		$objectService = $this->objectService();

		// Duplicate detection — reuse the relation filter (the statutory dedup path):
		// scope to the proposal, then to the voterId.
		//
		// The filter key comes from ObjectRelationFilter::filterFor(), NOT from the
		// schema slug. `_relations.budget-proposal` — what this call site used to
		// hand-write — can never match: OpenRegister keys the `_relations` JSONB by
		// the property path it walked (`relations.0.id`), never by the related
		// schema's slug, so the query returned ZERO rows on a healthy HTTP 200 and
		// this guard let every duplicate through. Measured on a live instance:
		// voting twice as the same user produced two CitizenVote rows sharing one
		// idempotency slug, both answered 201.
		$objectService->setRegister('decidesk');
		$objectService->setSchema('citizen-vote');
		$existing = $this->relationFilter->matching(
			entities: $objectService->findAll(
				[
					'filters' => ($this->relationFilter->filterFor(targetId: $proposalId) + ['voterId' => $voterId]),
				]
			),
			schema: 'budget-proposal',
			targetId: $proposalId
		);
		foreach ($existing as $existingEntity) {
			$existingVote = $existingEntity->jsonSerialize();
			if ((string)($existingVote['voterId'] ?? '') === $voterId) {
				throw new RuntimeException('Citizen has already voted on this proposal');
			}
		}

		// Atomic upsert via deterministic slug (same idempotency strategy as castVote()).
		$idempotencySlug = 'cvote-' . substr($proposalId, 0, 8) . '-' . substr(hash('sha256', $voterId), 0, 16);

		$vote = [
			'@self' => ['slug' => $idempotencySlug],
			'voteValue' => $value,
			'voterId' => $voterId,
			'proposalId' => $proposalId,
			'weight' => 1,
			'isProxy' => false,
			'castAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'relations' => [
				['register' => 'decidesk', 'schema' => 'budget-proposal', 'id' => $proposalId],
			],
		];

		$saved = $objectService->saveObject(register: 'decidesk', schema: 'citizen-vote', object: $vote);
		$savedArr = $this->normaliseSaved(saved: $saved, fallback: $vote);

		// Re-tally all CitizenVotes for this proposal (atomic count path) and persist
		// onto the BudgetProposal. CitizenVote objects only — never statutory Votes.
		$tally = $this->tallyAdvisoryProposal(proposalId: $proposalId);

		return [
			'vote' => $savedArr,
			'votesFor' => $tally['votesFor'],
			'votesAgainst' => $tally['votesAgainst'],
		];

	}//end applyAdvisoryTally()

	/**
	 * Recount advisory CitizenVotes for a BudgetProposal and persist the tally.
	 *
	 * @param string $proposalId The BudgetProposal UUID.
	 *
	 * @return array{votesFor:int,votesAgainst:int} The recomputed advisory tally.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function tallyAdvisoryProposal(string $proposalId): array {
		$objectService = $this->objectService();

		$objectService->setRegister('decidesk');
		$objectService->setSchema('citizen-vote');
		// Same correction as applyAdvisoryTally(): the filter key is
		// ObjectRelationFilter::filterFor()'s, not the schema slug. With
		// `_relations.budget-proposal` this query returned nothing, so every
		// advisory tally wrote back votesFor=0 / votesAgainst=0 over real votes.
		$voteEntities = $this->relationFilter->matching(
			entities: $objectService->findAll(['filters' => $this->relationFilter->filterFor(targetId: $proposalId)]),
			schema: 'budget-proposal',
			targetId: $proposalId
		);

		$for = 0;
		$against = 0;
		foreach ($voteEntities as $voteEntity) {
			$vote = $voteEntity->jsonSerialize();
			$val = (string)($vote['voteValue'] ?? '');
			if ($val === 'voor') {
				$for++;
			} elseif ($val === 'tegen') {
				$against++;
			}
		}

		$proposalEntity = $objectService->find(id: $proposalId, register: 'decidesk', schema: 'budget-proposal');
		if ($proposalEntity !== null) {
			$proposal = $proposalEntity->jsonSerialize();
			$proposal['votesFor'] = $for;
			$proposal['votesAgainst'] = $against;
			$objectService->saveObject(register: 'decidesk', schema: 'budget-proposal', object: $proposal);
		}

		return [
			'votesFor' => $for,
			'votesAgainst' => $against,
		];

	}//end tallyAdvisoryProposal()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object The OpenRegister ObjectService
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Normalise the result of ObjectService::saveObject() to an array.
	 *
	 * @param mixed $saved The value returned by saveObject().
	 * @param array<string, mixed> $fallback The original object payload.
	 *
	 * @return array<string, mixed> The persisted object as an array.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function normaliseSaved(mixed $saved, array $fallback): array {
		if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
			return $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $fallback;
	}//end normaliseSaved()
}//end class
