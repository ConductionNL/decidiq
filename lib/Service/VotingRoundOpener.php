<?php

/**
 * Decidesk Voting Round Opener
 *
 * Opening a voting round: the quorum check, the fail-closed preflight (rule
 * resolution, revote-once guard, parliamentary ordering, preset validation), the
 * round payload, the subject lifecycle transition, and the fail-soft
 * announcements that follow.
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
 * @spec openspec/specs/motion-amendment/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use InvalidArgumentException;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The open-a-voting-round path, extracted from VotingService.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundOpener {

	/**
	 * Parliamentary amendment ordering + subject/meeting resolution.
	 *
	 * @var AmendmentOrderService
	 */
	private readonly AmendmentOrderService $amendmentOrder;

	/**
	 * ObjectEntity -> array normalisation for the save result.
	 *
	 * @var SavedObjectNormaliser
	 */
	private readonly SavedObjectNormaliser $normaliser;

	/**
	 * Constructor for VotingRoundOpener.
	 *
	 * @param MotionService $motionService The motion service for lifecycle transitions
	 * @param ParticipantResolver $participantResolver Participant resolver for the quorum count
	 * @param VotingRoundPreflight $preflight Fail-closed preflight (rules, revote guard, presets)
	 * @param VotingOpenedNotifier $notifier Fail-soft announcements for a freshly opened round
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		MotionService $motionService,
		private readonly ParticipantResolver $participantResolver,
		private readonly VotingRoundPreflight $preflight,
		private readonly VotingOpenedNotifier $notifier,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->amendmentOrder = new AmendmentOrderService(
			container: $container,
			motionService: $motionService
		);

		$this->normaliser = new SavedObjectNormaliser();

	}//end __construct()

	/**
	 * Check whether quorum is met for a given meeting.
	 *
	 * Counts Participants whose leftAt is null (active) in the GovernanceBody, and
	 * compares against Meeting.quorumRequired.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return bool True if quorum is met or quorumRequired is null/0
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function checkQuorum(string $meetingId): bool {
		$meetingEntity = $this->objectService()->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
		$meeting = null;
		if ($meetingEntity !== null) {
			$meeting = $meetingEntity->jsonSerialize();
		}

		if ($meeting === null) {
			return false;
		}

		$quorumRequired = (int)($meeting['quorumRequired'] ?? 0);
		if ($quorumRequired === 0) {
			return true;
		}

		// Count active participants (leftAt is null) via the shared
		// ParticipantResolver, which resolves the meeting → governance-body link
		// and the participant memberships from BOTH the structured relation list
		// and the flat field-keyed relation map ('@self.relations.governanceBody')
		// produced by the standard OpenRegister object API. The previous inline
		// logic read '$meeting["relations"]' as a structured list and filtered on
		// '_relations.governance-body', neither of which matches OR-object-API
		// data, so it always counted 0 active participants and failed closed.
		$participants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);

		$activeCount = 0;
		foreach ($participants as $participant) {
			if (($participant['leftAt'] ?? null) === null) {
				$activeCount++;
			}
		}

		return $activeCount >= $quorumRequired;
	}//end checkQuorum()

	/**
	 * Open a VotingRound, optionally with preset participant UUIDs.
	 *
	 * Parliamentary ordering (motion-amendment spec, fail closed):
	 * - subjectType 'motion': rejected while any amendment of the motion is still
	 *   in an in-flight lifecycle (draft/proposed/deliberating/voting) — amendments
	 *   are voted before the main motion.
	 * - subjectType 'amendment': $motionId is the AMENDMENT UUID; rejected when a
	 *   sibling amendment earlier in the configured order (votingOrder ascending,
	 *   unordered last by submittedAt) is still undecided.
	 *
	 * @param string $motionId The motion UUID (the amendment UUID when subjectType is 'amendment')
	 * @param string $meetingId The meeting UUID
	 * @param string $votingMethod The voting method (for-against-abstain, show-of-hands, etc.)
	 * @param bool $isSecret Whether the ballot is secret
	 * @param string|null $closedAt Optional pre-defined close time
	 * @param array<string> $presetParticipantIds Optional array of participant UUIDs for a voting group preset
	 * @param string|null $revoteOfRoundId UUID of a tied round this round is the single permitted revote of
	 * @param VotingRoundRules|null $roundRules The configurable decision rules (threshold / abstention /
	 *                                          tie-break / subject type / opening body); null = all defaults
	 *
	 * @return array<string,mixed> The created voting round object with excludedPresetUuids key if any UUIDs were excluded
	 *
	 * @throws RuntimeException When quorum is not met, the revote guard fails, the amendment ordering rule is
	 *                          violated, or the lifecycle transition fails
	 * @throws InvalidArgumentException When a rule or subjectType value is not in its enum (fail closed)
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function openVotingRound(
		string $motionId,
		string $meetingId,
		string $votingMethod,
		bool $isSecret,
		?string $closedAt,
		array $presetParticipantIds = [],
		?string $revoteOfRoundId = null,
		?VotingRoundRules $roundRules = null,
	): array {
		$roundRules = ($roundRules ?? new VotingRoundRules());
		$subjectType = $roundRules->subjectType;

		// Process-configuration: resolution order per rule is caller value (non-null) ->
		// body template default -> built-in default. The caller (controller) always passes
		// explicit values, so it always wins; the template only fills nulls. Unknown rule
		// values are rejected, never silently defaulted.
		$rules = $this->preflight->resolveRules(
			governanceBodyId: $roundRules->governanceBodyId,
			voteThreshold: $roundRules->voteThreshold,
			abstentionHandling: $roundRules->abstentionHandling,
			tieBreakRule: $roundRules->tieBreakRule,
			subjectType: $subjectType
		);

		$quorumWith = $this->checkQuorum(meetingId: $meetingId);
		if ($quorumWith === false) {
			throw new RuntimeException('Quorum niet bereikt');
		}

		// Revote-once guard: the referenced round must be a tied revote-rule round
		// that has not been revoted before (fail closed on every mismatch).
		if ($revoteOfRoundId !== null) {
			$this->preflight->assertRevoteAllowed(revoteOfRoundId: $revoteOfRoundId);
		}

		// Parliamentary ordering (motion-amendment spec) and the lifecycle
		// transition below apply to fresh rounds only: a revote re-opens a
		// question that was already in order and never left 'voting'.
		$isFreshRound = ($revoteOfRoundId === null);
		if ($isFreshRound === true) {
			$this->amendmentOrder->assertOrdering(subjectId: $motionId, subjectType: $subjectType);
		}

		// Preset UUIDs are validated against active memberships; the eligible
		// ones become participant relations on the round.
		$presets = $this->preflight->splitPresetParticipants(meetingId: $meetingId, presetIds: $presetParticipantIds);
		$votingRound = $this->preflight->buildRoundPayload(
			motionId: $motionId,
			subjectType: $subjectType,
			votingMethod: $votingMethod,
			isSecret: $isSecret,
			closedAt: $closedAt,
			quorumWith: $quorumWith,
			rules: $rules,
			revoteOfRoundId: $revoteOfRoundId,
			participantIds: $presets['eligible']
		);

		$created = $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $votingRound);

		if ($isFreshRound === true) {
			$this->preflight->transitionSubjectToVoting(subjectId: $motionId, subjectType: $subjectType);
		}

		// ObjectService::saveObject() returns an ObjectEntity; normalise to an array so the
		// declared `: array` return type holds and callers can subscript the result.
		$result = $this->normaliser->toArray(saved: $created, fallback: $votingRound);

		if (count($presets['excluded']) > 0) {
			$result['excludedPresetUuids'] = $presets['excluded'];
		}

		// Fail-soft announcements: the activity-feed entry and the
		// preference-aware "pending vote" notifications (user-settings spec),
		// fanned out to each participant's active absence delegate.
		$this->notifier->announce(
			round: $result,
			motionId: $motionId,
			meetingId: $meetingId,
			closedAt: $closedAt,
			subjectType: $subjectType
		);

		return $result;
	}//end openVotingRound()

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
