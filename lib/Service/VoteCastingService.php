<?php

/**
 * Decidiq Vote Casting Service
 *
 * Implements the cast-a-vote path: round state guard, meeting membership,
 * proxy rules, duplicate detection and the idempotent ballot write.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The cast-a-vote path, extracted from VotingService.
 *
 * VotingService::castVote() was a 229-line method with a cyclomatic complexity
 * of 33 and an NPath complexity above three million. This class is now only the
 * orchestration and the duplicate-ballot lookup: the eligibility rules live in
 * VoteCastGuard and the payload assembly in VoteBallotFactory.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteCastingService {

	/**
	 * Derives the secret-ballot voter and delegator tokens.
	 *
	 * @var VoterTokenSecret
	 */
	private readonly VoterTokenSecret $tokens;

	/**
	 * Fail-closed eligibility rules a cast must pass.
	 *
	 * @var VoteCastGuard
	 */
	private readonly VoteCastGuard $guard;

	/**
	 * Assembles the ballot payload.
	 *
	 * @var VoteBallotFactory
	 */
	private readonly VoteBallotFactory $ballots;

	/**
	 * Constructor for the VoteCastingService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ParticipantResolver $participantResolver Resolves a meeting's participants
	 * @param AmendmentOrderService $amendmentOrder Resolves the meeting behind a round
	 * @param ObjectRelationFilter $relationFilter Exact-id scoping for relation-filtered result sets
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service (ADR-084)
	 * @param ContainerInterface $container DI container — VoterTokenSecret, VoteBallotFactory and VoteCastGuard still resolve through it
	 *
	 * @return void
	 */
	public function __construct(
		LoggerInterface $logger,
		ParticipantResolver $participantResolver,
		AmendmentOrderService $amendmentOrder,
		private readonly ObjectRelationFilter $relationFilter,
		private readonly ObjectServiceInterface $objectService,
		ContainerInterface $container,
	) {
		$this->tokens = new VoterTokenSecret(container: $container);
		$this->ballots = new VoteBallotFactory(
			container: $container,
			logger: $logger,
			tokens: $this->tokens
		);
		$this->guard = new VoteCastGuard(
			container: $container,
			logger: $logger,
			relationFilter: $relationFilter,
			tokens: $this->tokens,
			participantResolver: $participantResolver,
			amendmentOrder: $amendmentOrder,
			objectService: $objectService
		);

	}//end __construct()

	/**
	 * Cast a vote in a VotingRound.
	 *
	 * Checks the round is open, verifies the participant is a member of the
	 * meeting that owns the round (#300), prevents duplicates (overwrites
	 * existing vote), and enforces one-proxy-per-round for proxy votes.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The participant UUID
	 * @param string $value for | against | abstain
	 * @param bool $isProxy True when the participant is voting as proxy for another
	 * @param string|null $delegatorId The participant UUID being delegated (required when isProxy=true)
	 * @param string|null $callerUid The authenticated Nextcloud UID of the casting user
	 *
	 * @return array<string,mixed> The created/updated Vote object
	 *
	 * @throws \RuntimeException When the round is not open, the caller is not a meeting member,
	 *                           or proxy rules are violated
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function castVote(
		string $votingRoundId,
		string $participantId,
		string $value,
		bool $isProxy,
		?string $delegatorId,
		?string $callerUid = null,
	): array {
		$round = $this->guard->loadOpenRound(votingRoundId: $votingRoundId);
		$this->guard->assertMeetingMembership(round: $round, participantId: $participantId);

		$isSecret = (bool)($round['isSecret'] ?? false);

		if ($isProxy === true && $delegatorId !== null) {
			$this->guard->assertGrant(
				round: $round,
				participantId: $participantId,
				delegatorId: $delegatorId,
				callerUid: $callerUid
			);
			$this->guard->assertNotAlreadyRegistered(
				votingRoundId: $votingRoundId,
				delegatorId: $delegatorId,
				isSecret: $isSecret
			);
		}

		$vote = $this->ballots->buildVote(
			votingRoundId: $votingRoundId,
			participantId: $participantId,
			value: $value,
			isProxy: $isProxy,
			delegatorId: $delegatorId,
			isSecret: $isSecret,
			existingVote: $this->findExistingVote(
				votingRoundId: $votingRoundId,
				participantId: $participantId,
				isSecret: $isSecret
			)
		);

		$saved = $this->objectService()->saveObject(register: 'decidesk', schema: 'vote', object: $vote);

		// The saveObject() call returns an ObjectEntity; normalise to satisfy the `: array` return type.
		return $this->normaliseSaved(saved: $saved, fallback: $vote);
	}//end castVote()

	/**
	 * Find this participant's existing ballot in the round, when there is one.
	 *
	 * For secret rounds the participant relation is suppressed for anonymity,
	 * so dedup is keyed on a deterministic voterToken instead.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The casting participant UUID
	 * @param bool $isSecret Whether the round is a secret ballot
	 *
	 * @return array<string,mixed>|null The existing vote, or null.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function findExistingVote(string $votingRoundId, string $participantId, bool $isSecret): ?array {
		$entities = $this->existingVoteEntities(
			votingRoundId: $votingRoundId,
			participantId: $participantId,
			isSecret: $isSecret
		);

		foreach ($entities as $entity) {
			return $entity->jsonSerialize();
		}

		return null;
	}//end findExistingVote()

	/**
	 * Query the ballot entities that would collide with this cast.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The casting participant UUID
	 * @param bool $isSecret Whether the round is a secret ballot
	 *
	 * @return array<int, mixed> The colliding vote entities.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function existingVoteEntities(string $votingRoundId, string $participantId, bool $isSecret): array {
		if ($isSecret === true) {
			return $this->votesInRound(
				votingRoundId: $votingRoundId,
				extraFilters: [
					'voterToken' => $this->tokens->voterToken(
						participantId: $participantId,
						votingRoundId: $votingRoundId
					),
				]
			);
		}

		// Scope to this round in the query, then to this participant in PHP, to
		// get an exact dedup match.
		//
		// The participant leg is deliberately NOT a second relation filter. Both
		// legs would have to be keyed `_relations.relations` (see
		// ObjectRelationFilter) and would collide into a single array key, so one
		// of the two ids would be silently dropped — a dedup check that quietly
		// stops deduping. matching() below re-checks the participant on the round
		// -scoped set, which is exact and cannot collide.
		return $this->relationFilter->matching(
			entities: $this->votesInRound(votingRoundId: $votingRoundId, extraFilters: []),
			schema: 'participant',
			targetId: $participantId
		);

	}//end existingVoteEntities()

	/**
	 * Fetch the votes of one round, scoped to that round exactly.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param array<string, mixed> $extraFilters Additional OpenRegister filters
	 *
	 * @return array<int, mixed> The matching vote entities.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function votesInRound(string $votingRoundId, array $extraFilters): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidesk');
		$objectService->setSchema('vote');

		return $this->relationFilter->matching(
			entities: $objectService->findAll(
				[
					'filters' => array_merge(
						$this->relationFilter->filterFor(targetId: $votingRoundId),
						$extraFilters
					),
				]
			),
			schema: 'voting-round',
			targetId: $votingRoundId
		);

	}//end votesInRound()

	/**
	 * Normalise the result of ObjectService::saveObject() to an array.
	 *
	 * @param mixed $saved The value returned by saveObject()
	 * @param array<string, mixed> $fallback The original object payload
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

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object The OpenRegister ObjectService.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
