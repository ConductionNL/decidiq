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
	 * Refuses a member whose seat a substitute holds, and a non-voting
	 * participant who holds no seat (bodies-substitute-mandate-swap).
	 *
	 * @var SeatHolderGuard
	 */
	private readonly SeatHolderGuard $seats;

	/**
	 * Constructor for the VoteCastingService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ParticipantResolver $participantResolver Resolves a meeting's participants
	 * @param AmendmentOrderService $amendmentOrder Resolves the meeting behind a round
	 * @param ObjectRelationFilter $relationFilter Exact-id scoping for relation-filtered result sets
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service (ADR-084)
	 * @param ContainerInterface $container DI container — VoterTokenSecret, VoteBallotFactory and VoteCastGuard still resolve through it
	 * @param RecusalGuard $recusal Keeps a member recused on the matter out of the vote (bod-10)
	 *
	 * @return void
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function __construct(
		LoggerInterface $logger,
		ParticipantResolver $participantResolver,
		AmendmentOrderService $amendmentOrder,
		private readonly ObjectRelationFilter $relationFilter,
		private readonly ObjectServiceInterface $objectService,
		ContainerInterface $container,
		private readonly RecusalGuard $recusal,
	) {
		// The ballot factory owns the token secret; the guard and the dedup
		// lookup below use the same one.
		$this->ballots = new VoteBallotFactory(container: $container, logger: $logger);
		$this->guard = new VoteCastGuard(
			container: $container,
			logger: $logger,
			relationFilter: $relationFilter,
			tokens: $this->ballots->tokens(),
			participantResolver: $participantResolver,
			amendmentOrder: $amendmentOrder,
			objectService: $objectService
		);
		$this->seats = new SeatHolderGuard(
			amendmentOrder: $amendmentOrder,
			participantResolver: $participantResolver,
			objectService: $objectService,
			logger: $logger
		);

	}//end __construct()

	/**
	 * Cast a vote in a VotingRound.
	 *
	 * Checks the round is open, verifies the participant is a member of the
	 * meeting that owns the round (#300), prevents duplicates (overwrites
	 * existing vote), and enforces one-proxy-per-round for proxy votes. A
	 * refusal (the round is not open, the caller is not a meeting member, or
	 * the proxy rules are violated) is a RuntimeException from the guards.
	 *
	 * @param VoteCast    $cast      The ballot: round, caster, choice and proxy
	 * @param string|null $callerUid The authenticated Nextcloud UID of the casting user
	 *
	 * @return array<string,mixed> The created/updated Vote object
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function castVote(VoteCast $cast, ?string $callerUid = null): array {
		$round = $this->guard->loadOpenRound(votingRoundId: $cast->votingRoundId);

		// A ranked-choice round takes a full ranking and stores value `ranked`;
		// any other round refuses a ranking (REQ-PRF-002). Checked before
		// anything is read or written for the ballot.
		$cast = $cast->forRound(
			value: $this->guard->ballotValue(round: $round, value: $cast->value(), ranking: $cast->ranking()),
			isSecret: (bool)($round['isSecret'] ?? false)
		);

		$this->guard->assertMeetingMembership(round: $round, participantId: $cast->participantId);
		$this->seats->assertHoldsSeat(round: $round, participantId: $cast->participantId);

		// The ballot counts for the delegator on a proxy vote, for the caster
		// otherwise; a member recused on the matter does not vote (bod-10).
		$this->recusal->assertNotRecused(round: $round, participantId: $cast->ballotOwner());

		if ($cast->isDelegated() === true) {
			$this->guard->assertGrant(
				round: $round,
				participantId: $cast->participantId,
				delegatorId: (string)$cast->delegatorId(),
				callerUid: $callerUid
			);
			$this->guard->assertNotAlreadyRegistered(
				votingRoundId: $cast->votingRoundId,
				delegatorId: (string)$cast->delegatorId(),
				isSecret: $cast->isSecret()
			);
		}

		$vote = $this->ballots->buildVote(cast: $cast, existingVote: $this->findExistingVote(cast: $cast));

		$saved = $this->objectService()->saveObject(register: 'decidiq', schema: 'vote', object: $vote);

		// The saveObject() call returns an ObjectEntity; normalise to satisfy the `: array` return type.
		return $this->normaliseSaved(saved: $saved, fallback: $vote);
	}//end castVote()

	/**
	 * Find this participant's existing ballot in the round, when there is one.
	 *
	 * For secret rounds the participant relation is suppressed for anonymity,
	 * so dedup is keyed on a deterministic voterToken instead.
	 *
	 * @param VoteCast $cast The settled ballot
	 *
	 * @return array<string,mixed>|null The existing vote, or null.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function findExistingVote(VoteCast $cast): ?array {
		foreach ($this->existingVoteEntities(cast: $cast) as $entity) {
			return $entity->jsonSerialize();
		}

		return null;
	}//end findExistingVote()

	/**
	 * Query the ballot entities that would collide with this cast.
	 *
	 * @param VoteCast $cast The settled ballot
	 *
	 * @return array<int, mixed> The colliding vote entities.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function existingVoteEntities(VoteCast $cast): array {
		if ($cast->isSecret() === true) {
			return $this->votesInRound(
				votingRoundId: $cast->votingRoundId,
				extraFilters: [
					'voterToken' => $this->ballots->tokens()->voterToken(
						participantId: $cast->participantId,
						votingRoundId: $cast->votingRoundId
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
			entities: $this->votesInRound(votingRoundId: $cast->votingRoundId, extraFilters: []),
			schema: 'participant',
			targetId: $cast->participantId
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
		$objectService->setRegister('decidiq');
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
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
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
