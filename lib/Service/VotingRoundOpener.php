<?php

/**
 * Decidiq Voting Round Opener
 *
 * Opening a voting round: the quorum check, the fail-closed preflight (rule
 * resolution, revote-once guard, parliamentary ordering, preset validation), the
 * round payload, the subject lifecycle transition, and the fail-soft
 * announcements that follow.
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
 * @spec openspec/specs/motion-amendment/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

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
	 * The shape of a ranked-choice round's options.
	 *
	 * @var RankedBallotRules
	 */
	private readonly RankedBallotRules $rankedRules;

	/**
	 * The meeting's type and body rules (quorum, vote threshold).
	 *
	 * @var MeetingRuleSource
	 */
	private readonly MeetingRuleSource $ruleSource;

	/**
	 * Constructor for VotingRoundOpener.
	 *
	 * @param MotionService $motionService The motion service for lifecycle transitions
	 * @param ParticipantResolver $participantResolver Participant resolver for the quorum count
	 * @param VotingRoundPreflight $preflight Fail-closed preflight (rules, revote guard, presets)
	 * @param VotingOpenedNotifier $notifier Fail-soft announcements for a freshly opened round
	 * @param ObjectServiceInterface $objectService OpenRegister's published object contract (ADR-084)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		MotionService $motionService,
		ParticipantResolver $participantResolver,
		private readonly VotingRoundPreflight $preflight,
		private readonly VotingOpenedNotifier $notifier,
		private readonly ObjectServiceInterface $objectService,
	) {
		// ADR-084 replaced this class's `ContainerInterface $container`
		// parameter with `ObjectServiceInterface $objectService`, and replaced
		// AmendmentOrderService's `$container` with the same contract — but
		// this call site kept passing `container: $container`, a variable that
		// no longer exists. Constructing VotingRoundOpener therefore raised
		// "Undefined variable: $container" at REQUEST time, which is why
		// opening a round answered 500.
		$this->amendmentOrder = new AmendmentOrderService(
			motionService: $motionService,
			objectService: $objectService
		);

		$this->normaliser = new SavedObjectNormaliser();
		$this->rankedRules = new RankedBallotRules();
		$this->ruleSource = new MeetingRuleSource(objectService: $objectService, participantResolver: $participantResolver);

	}//end __construct()

	/**
	 * Check whether quorum is met for a given meeting.
	 *
	 * The threshold is Meeting.quorumRequired, else the body's quorum, else
	 * the body's quorumRule over its current members (BodyQuorum). Members
	 * marked present or proxy count; with no attendance taken, every member
	 * who has not left counts.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return bool True if quorum is met or no quorum is set
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function checkQuorum(string $meetingId): bool {
		return $this->ruleSource->quorumMet(meetingId: $meetingId);
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
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
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

		// Resolution order per rule: the chair's pick (non-null), then the meeting
		// type's threshold, then the template of the body (named, else the
		// meeting's), then the built-in default. Unknown values are rejected.
		$rules = $this->preflight->resolveRules(
			governanceBodyId: ($roundRules->governanceBodyId ?? $this->ruleSource->bodyIdOf(meetingId: $meetingId)),
			voteThreshold: ($roundRules->voteThreshold ?? $this->ruleSource->meetingTypeThreshold(meetingId: $meetingId)),
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

		// Checked before anything is written, so a refusal leaves no round.
		$options = $this->roundOptions(
			votingMethod: $votingMethod,
			requested: $roundRules->options,
			revoteOfRoundId: $revoteOfRoundId,
			tieBreakRule: (string)$rules['tieBreakRule']
		);

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
		if ($options !== []) {
			$votingRound['options'] = $options;
		}

		$created = $this->objectService()->saveObject(register: 'decidiq', schema: 'voting-round', object: $votingRound);

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
	 * The options a round opens with: the requested ones for a ranked-choice
	 * round (REQ-PRF-001), or the tied options of the round a ranked revote
	 * repeats (REQ-RPB-001); none for any other method.
	 *
	 * @param string $votingMethod The round's voting method.
	 * @param array<int, mixed> $requested The options as requested.
	 * @param string|null $revoteOfRoundId The tied round this round revotes, or null.
	 * @param string $tieBreakRule The round's resolved tie-break rule.
	 *
	 * @return array<int, array<string, string>> The checked options.
	 *
	 * @throws \InvalidArgumentException When the options do not fit the method.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	private function roundOptions(string $votingMethod, array $requested, ?string $revoteOfRoundId, string $tieBreakRule): array {
		if ($revoteOfRoundId !== null && $votingMethod === RankedBallotRules::METHOD) {
			$requested = ($this->preflight->tiedOptionsOf(revoteOfRoundId: $revoteOfRoundId) ?? $requested);
		}

		return $this->rankedRules->openingOptions(
			votingMethod: $votingMethod,
			options: $requested,
			tieBreakRule: $tieBreakRule
		);
	}//end roundOptions()

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
