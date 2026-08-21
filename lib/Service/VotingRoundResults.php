<?php

/**
 * Decidesk Voting Round Results
 *
 * Counting and result computation for a voting round: the ballot tally, the
 * chair-entered show-of-hands tally, and the rule-aware outcome that both share.
 * The applied rules and the computed base are persisted on the round alongside
 * the counts so the decision stays auditable.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Rule-aware tallying for a voting round, extracted from VotingService.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundResults {

	/**
	 * Pure, rule-aware result computation.
	 *
	 * @var VotingResultCalculator
	 */
	private readonly VotingResultCalculator $calculator;

	/**
	 * Exact-id scoping for OpenRegister relation-filtered result sets.
	 *
	 * @var ObjectRelationFilter
	 */
	private readonly ObjectRelationFilter $relationFilter;

	/**
	 * Resolves the meeting that owns a round through the motion/amendment chain.
	 *
	 * @var AmendmentOrderService
	 */
	private readonly AmendmentOrderService $amendmentOrder;

	/**
	 * ObjectEntity -> array normalisation for save results.
	 *
	 * @var SavedObjectNormaliser
	 */
	private readonly SavedObjectNormaliser $normaliser;

	/**
	 * Constructor for VotingRoundResults.
	 *
	 * @param MotionService $motionService The motion service (subject chain resolution)
	 * @param ParticipantResolver $participantResolver Meeting-attendance resolver
	 * @param ObjectServiceInterface $objectService OpenRegister's published object contract (ADR-084)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		MotionService $motionService,
		private readonly ParticipantResolver $participantResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->calculator = new VotingResultCalculator();
		$this->relationFilter = new ObjectRelationFilter();
		// Same ADR-084 leftover as VotingRoundOpener: `container: $container`
		// named an argument AmendmentOrderService no longer declares AND a
		// variable this constructor no longer receives, so every tally request
		// fatalled on construction.
		$this->amendmentOrder = new AmendmentOrderService(
			motionService: $motionService,
			objectService: $objectService
		);
		$this->normaliser = new SavedObjectNormaliser();

	}//end __construct()

	/**
	 * Compute the rule-aware voting result for a set of counts.
	 *
	 * @param int $for Weighted for-votes
	 * @param int $against Weighted against-votes
	 * @param int $abstain Weighted abstentions
	 * @param array<string,mixed> $round The voting round (rules + chairCastingVote are read from it)
	 *
	 * @return array{result: string, base: int, voteThreshold: string, abstentionHandling: string, tieBreakRule: string}
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function compute(int $for, int $against, int $abstain, array $round): array {
		return $this->calculator->compute(for: $for, against: $against, abstain: $abstain, round: $round);
	}//end compute()

	/**
	 * Tally all votes in a VotingRound and update the round with counts and result.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed> Tally with votesFor, votesAgainst, votesAbstain, total, base, result and applied rules
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function tally(string $votingRoundId): array {
		// Load the round first — the configured rules drive the result computation.
		$round = $this->loadRound(votingRoundId: $votingRoundId);
		$counts = $this->countVotes(voteEntities: $this->ballotsInRound(votingRoundId: $votingRoundId));

		$computed = $this->compute(
			for: $counts['votesFor'],
			against: $counts['votesAgainst'],
			abstain: $counts['votesAbstain'],
			round: ($round ?? [])
		);

		// Update VotingRound with tally + the applied rules and base (audit trail).
		$this->persistOutcome(round: $round, counts: $counts, computed: $computed);

		return [
			'votesFor' => $counts['votesFor'],
			'votesAgainst' => $counts['votesAgainst'],
			'votesAbstain' => $counts['votesAbstain'],
			'total' => ($counts['votesFor'] + $counts['votesAgainst'] + $counts['votesAbstain']),
			'base' => $computed['base'],
			'voteThreshold' => $computed['voteThreshold'],
			'abstentionHandling' => $computed['abstentionHandling'],
			'tieBreakRule' => $computed['tieBreakRule'],
			'result' => $computed['result'],
		];

	}//end tally()

	/**
	 * Record a show-of-hands tally for an open VotingRound.
	 *
	 * Only valid for rounds with votingMethod == 'show-of-hands'. Saves the
	 * chair-entered counts directly as aggregate totals and computes the result
	 * against the same configured threshold / abstention / tie-break rules as a
	 * ballot round.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param int $votesFor Count of raised hands for
	 * @param int $votesAgainst Count of raised hands against
	 * @param int $votesAbstain Count of abstentions
	 *
	 * @return array<string,mixed> Updated VotingRound data
	 *
	 * @throws RuntimeException When the round is not found or is not a show-of-hands round
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function saveShowOfHands(string $votingRoundId, int $votesFor, int $votesAgainst, int $votesAbstain): array {
		$round = $this->loadRound(votingRoundId: $votingRoundId);
		if ($round === null) {
			throw new RuntimeException("VotingRound $votingRoundId not found");
		}

		if (($round['votingMethod'] ?? '') !== 'show-of-hands') {
			throw new RuntimeException('saveShowOfHandsTally is only valid for show-of-hands rounds');
		}

		// #302: Validate submitted counts against the actual participant count for the meeting.
		$this->assertWithinAttendance(round: $round, submittedTotal: ($votesFor + $votesAgainst + $votesAbstain));

		$counts = ['votesFor' => $votesFor, 'votesAgainst' => $votesAgainst, 'votesAbstain' => $votesAbstain];
		$computed = $this->compute(for: $votesFor, against: $votesAgainst, abstain: $votesAbstain, round: $round);
		$round = $this->withOutcome(round: $round, counts: $counts, computed: $computed);

		$saved = $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

		// The saveObject() call returns an ObjectEntity; normalise to satisfy the `: array` return type.
		return $this->normaliser->toArray(saved: $saved, fallback: $round);
	}//end saveShowOfHands()

	/**
	 * Return every ballot that genuinely belongs to the given round.
	 *
	 * The filter is keyed via {@see ObjectRelationFilter::filterFor()}, NOT on the
	 * `voting-round` schema slug: decidesk writes ballots with a structured
	 * `relations` array, which OpenRegister flattens to `_relations` keys of the
	 * form `relations.<n>.id`, so a slug-keyed filter matched zero rows and every
	 * tally computed 0/0/0 on a healthy 200. The filter pins the related id but
	 * not the related SCHEMA, so the result set is still re-checked here.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<int,mixed> The Vote ObjectEntity result set
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function ballotsInRound(string $votingRoundId): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidesk');
		$objectService->setSchema('vote');

		return $this->relationFilter->matching(
			entities: $objectService->findAll(
				['filters' => $this->relationFilter->filterFor(targetId: $votingRoundId)]
			),
			schema: 'voting-round',
			targetId: $votingRoundId
		);

	}//end ballotsInRound()

	/**
	 * Sum the weighted for / against / abstain counts of a ballot set.
	 *
	 * @param array<int,mixed> $voteEntities The Vote ObjectEntity result set
	 *
	 * @return array{votesFor: int, votesAgainst: int, votesAbstain: int} The weighted counts
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function countVotes(array $voteEntities): array {
		$for = 0;
		$against = 0;
		$abstain = 0;

		foreach ($voteEntities as $voteEntity) {
			$vote = $voteEntity->jsonSerialize();
			$val = ($vote['value'] ?? '');
			$weight = (int)($vote['weight'] ?? 1);
			if ($val === 'for') {
				$for += $weight;
			} elseif ($val === 'against') {
				$against += $weight;
			} elseif ($val === 'abstain') {
				$abstain += $weight;
			}
		}

		return ['votesFor' => $for, 'votesAgainst' => $against, 'votesAbstain' => $abstain];
	}//end countVotes()

	/**
	 * Persist the tally and the applied rules onto the round (audit trail).
	 *
	 * @param array<string,mixed>|null $round The serialised voting round, or null when unknown
	 * @param array<string,int> $counts The weighted counts
	 * @param array<string,mixed> $computed The computed outcome
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function persistOutcome(?array $round, array $counts, array $computed): void {
		if ($round === null) {
			return;
		}

		$this->objectService()->saveObject(
			register: 'decidesk',
			schema: 'voting-round',
			object: $this->withOutcome(round: $round, counts: $counts, computed: $computed)
		);

	}//end persistOutcome()

	/**
	 * Stamp the counts, the result, the base and the applied rules onto a round.
	 *
	 * @param array<string,mixed> $round The serialised voting round
	 * @param array<string,int> $counts The weighted counts
	 * @param array<string,mixed> $computed The computed outcome
	 *
	 * @return array<string,mixed> The round with the outcome applied
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function withOutcome(array $round, array $counts, array $computed): array {
		$round['votesFor'] = $counts['votesFor'];
		$round['votesAgainst'] = $counts['votesAgainst'];
		$round['votesAbstain'] = $counts['votesAbstain'];
		$round['result'] = $computed['result'];
		$round['voteBase'] = $computed['base'];
		$round['voteThreshold'] = $computed['voteThreshold'];
		$round['abstentionHandling'] = $computed['abstentionHandling'];
		$round['tieBreakRule'] = $computed['tieBreakRule'];

		return $round;
	}//end withOutcome()

	/**
	 * Refuse a show-of-hands tally that exceeds the meeting's active attendance (#302).
	 *
	 * The round relates to a motion (or an amendment, resolved through its parent
	 * motion), which relates to a meeting. When the meeting cannot be resolved, or
	 * it has no active participants, there is nothing to validate against.
	 *
	 * @param array<string,mixed> $round The serialised voting round
	 * @param int $submittedTotal The chair-entered total
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the submitted total exceeds the active attendance
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function assertWithinAttendance(array $round, int $submittedTotal): void {
		$meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);
		if ($meetingId === null) {
			return;
		}

		// Count only active participants (leftAt is null) via canonical resolver.
		$meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
		$activeCount = $this->countActive(participants: $meetingParticipants);

		if ($activeCount > 0 && $submittedTotal > $activeCount) {
			throw new RuntimeException(
				"Ingevoerde tellingen ({$submittedTotal}) overschrijden het aantal actieve deelnemers ({$activeCount})"
			);
		}

	}//end assertWithinAttendance()

	/**
	 * Count the participants that have not left the meeting.
	 *
	 * @param array<int,array<string,mixed>> $participants The resolved meeting participants
	 *
	 * @return int The number of active participants
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function countActive(array $participants): int {
		$active = 0;
		foreach ($participants as $participant) {
			if (($participant['leftAt'] ?? null) === null) {
				$active++;
			}
		}

		return $active;
	}//end countActive()

	/**
	 * Load a voting round, or null when it does not exist.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed>|null The serialised voting round
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function loadRound(string $votingRoundId): ?array {
		$entity = $this->objectService()->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end loadRound()

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
}//end class
