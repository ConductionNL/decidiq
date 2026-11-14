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
	 * Turns a voting group into the meeting's participants.
	 *
	 * @var VotingGroupResolver
	 */
	private readonly VotingGroupResolver $votingGroups;

	/**
	 * Constructor for VotingRoundOpener.
	 *
	 * @param MotionService $motionService The motion service for lifecycle transitions
	 * @param ParticipantResolver $participantResolver Participant resolver for the quorum count
	 * @param VotingRoundPreflight $preflight Fail-closed preflight (rules, revote guard, presets)
	 * @param VotingOpenedNotifier $notifier Fail-soft announcements for a freshly opened round
	 * @param ObjectServiceInterface $objectService OpenRegister's published object contract (ADR-084)
	 * @param VotingGroupResolver|null $votingGroups Turns a voting group into the meeting's participants; null builds the default
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
		?VotingGroupResolver $votingGroups = null,
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
		$this->votingGroups = ($votingGroups ?? new VotingGroupResolver(participants: $participantResolver, objectService: $objectService));

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
	 * @param VotingRoundRequest $request The subject, meeting, method, secrecy and optional parts
	 *
	 * @return array<string,mixed> The created voting round object with excludedPresetUuids key if any UUIDs were excluded
	 *
	 * @throws RuntimeException When quorum is not met, the revote guard fails, the amendment ordering rule is
	 *                          violated, or the lifecycle transition fails. A rule or subjectType value
	 *                          outside its enum is refused with an InvalidArgumentException from the
	 *                          preflight (fail closed).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
	 */
	public function openVotingRound(VotingRoundRequest $request): array {
		$roundRules = $request->rules();
		$subjectType = $roundRules->subjectType;
		$meetingId = $request->meetingId;
		$motionId = $request->motionId;
		$revoteOfRoundId = $request->revoteOfRoundId();

		// Resolution order per rule: the chair's pick (non-null), then the meeting
		// type's threshold, then the template of the body (named, else the
		// meeting's), then the built-in default. Unknown values are rejected.
		$bodyId = ($roundRules->governanceBodyId ?? $this->ruleSource->bodyIdOf(meetingId: $meetingId));
		$rules = $this->preflight->resolveRules(
			governanceBodyId: $bodyId,
			voteThreshold: ($roundRules->voteThreshold ?? $this->ruleSource->meetingTypeThreshold(meetingId: $meetingId)),
			abstentionHandling: $roundRules->abstentionHandling,
			tieBreakRule: $roundRules->tieBreakRule,
			subjectType: $subjectType
		);

		// The body may limit the methods its meetings use; checked before anything is written.
		$this->preflight->assertMethodAllowed(governanceBodyId: $bodyId, votingMethod: $request->votingMethod);

		$quorumWith = $this->quorumOrRefuse(meetingId: $meetingId);

		// Revote-once guard: the referenced round must be a tied revote-rule round
		// that has not been revoted before (fail closed on every mismatch).
		if ($revoteOfRoundId !== null) {
			$this->preflight->assertRevoteAllowed(revoteOfRoundId: $revoteOfRoundId);
		}

		// Checked before anything is written, so a refusal leaves no round.
		$options = $this->roundOptions(request: $request, tieBreakRule: (string)$rules['tieBreakRule']);

		// Parliamentary ordering (motion-amendment spec) and the lifecycle
		// transition below apply to fresh rounds only: a revote re-opens a
		// question that was already in order and never left 'voting'.
		if ($request->isFreshRound() === true) {
			$this->amendmentOrder->assertOrdering(subjectId: $motionId, subjectType: $subjectType);
		}

		// A voting group stands for its members who are participants of this
		// meeting (REQ-VGP-002); refused before anything is written when it
		// cannot be used. Its left-out members are reported (REQ-VGP-005).
		$group = $this->groupParticipants(request: $request, governanceBodyId: $roundRules->governanceBodyId);

		// Preset UUIDs are validated against active memberships; the eligible
		// ones become participant relations, a substituted seat as its substitute (REQ-MSW-002).
		$chosen = ($group['participantIds'] ?? $request->presetParticipantIds());
		$presetIds = $this->ruleSource->seatHoldersFor(meetingId: $meetingId, participantIds: $chosen);
		$presets = $this->preflight->splitPresetParticipants(meetingId: $meetingId, presetIds: $presetIds);
		$resolution = new VotingRoundResolution(rules: $rules, quorumWith: $quorumWith, participantIds: $presets['eligible']);
		$votingRound = $this->withOptions(votingRound: $resolution->roundPayload(request: $request), options: $options);

		$created = $this->objectService()->saveObject(register: 'decidiq', schema: 'voting-round', object: $votingRound);

		if ($request->isFreshRound() === true) {
			$this->preflight->transitionSubjectToVoting(subjectId: $motionId, subjectType: $subjectType);
		}

		// ObjectService::saveObject() returns an ObjectEntity; normalise to an array so the
		// declared `: array` return type holds and callers can subscript the result.
		$result = $this->withExcludedPresets(
			result: $this->normaliser->toArray(saved: $created, fallback: $votingRound),
			excluded: $presets['excluded']
		);
		$result = $this->withExcludedGroupMembers(result: $result, excluded: ($group['excluded'] ?? []));

		// Fail-soft announcements: the activity-feed entry and the
		// preference-aware "pending vote" notifications (user-settings spec),
		// fanned out to each participant's active absence delegate.
		$this->notifier->announce(
			round: $result,
			motionId: $motionId,
			meetingId: $meetingId,
			closedAt: $request->closedAt(),
			subjectType: $subjectType
		);

		return $result;
	}//end openVotingRound()

	/**
	 * The quorum outcome; refused when the meeting has no quorum.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return mixed What checkQuorum() reported.
	 *
	 * @throws RuntimeException When quorum is not reached.
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	private function quorumOrRefuse(string $meetingId): mixed {
		$quorumWith = $this->checkQuorum(meetingId: $meetingId);
		if ($quorumWith === false) {
			throw new RuntimeException('Quorum niet bereikt');
		}

		return $quorumWith;
	}//end quorumOrRefuse()

	/**
	 * The round payload with its options, when it has any.
	 *
	 * @param array<string, mixed> $votingRound The round payload.
	 * @param array<mixed>         $options     The round's options.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	private function withOptions(array $votingRound, array $options): array {
		if ($options !== []) {
			$votingRound['options'] = $options;
		}

		return $votingRound;
	}//end withOptions()

	/**
	 * The created round with the preset participants that were not eligible, when there are any.
	 *
	 * @param array<string, mixed> $result   The created round.
	 * @param array<mixed>         $excluded The preset ids left out.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	private function withExcludedPresets(array $result, array $excluded): array {
		if (count($excluded) > 0) {
			$result['excludedPresetUuids'] = $excluded;
		}

		return $result;
	}//end withExcludedPresets()

	/**
	 * The participants and left-out members of the round's voting group, or null when it names none.
	 *
	 * @param VotingRoundRequest $request          The request.
	 * @param string|null        $governanceBodyId The body the request names, or null for the meeting's.
	 *
	 * @return array{participantIds: list<string>, excluded: list<array{id: string, name: string}>}|null
	 *
	 * @throws \InvalidArgumentException When the group cannot be used for this round.
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	private function groupParticipants(VotingRoundRequest $request, ?string $governanceBodyId): ?array {
		$groupId = $request->votingGroupId();
		if ($groupId === null) {
			return null;
		}

		return $this->votingGroups->resolve(groupId: $groupId, meetingId: $request->meetingId, bodyId: $governanceBodyId);
	}//end groupParticipants()

	/**
	 * The created round with the voting group's left-out members, when there are any.
	 *
	 * Their ids join `excludedPresetUuids`, and `excludedGroupMembers` carries
	 * their names, so the panel can say who was left out (REQ-VGP-005).
	 *
	 * @param array<string, mixed>                  $result   The created round.
	 * @param list<array{id: string, name: string}> $excluded The left-out members.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-005-the-chair-sees-who-a-voting-group-left-out
	 */
	private function withExcludedGroupMembers(array $result, array $excluded): array {
		if ($excluded === []) {
			return $result;
		}

		$result['excludedPresetUuids'] = array_values(
			array_merge(($result['excludedPresetUuids'] ?? []), array_column($excluded, 'id'))
		);
		$result['excludedGroupMembers'] = $excluded;

		return $result;
	}//end withExcludedGroupMembers()

	/**
	 * The options a round opens with: the requested ones for a ranked-choice
	 * round (REQ-PRF-001), or the tied options of the round a ranked revote
	 * repeats (REQ-RPB-001); none for any other method. Options that do not
	 * fit the method are refused with an InvalidArgumentException from
	 * RankedBallotRules.
	 *
	 * @param VotingRoundRequest $request      The request: method, requested options and revote reference.
	 * @param string             $tieBreakRule The round's resolved tie-break rule.
	 *
	 * @return array<int, array<string, string>> The checked options.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	private function roundOptions(VotingRoundRequest $request, string $tieBreakRule): array {
		$requested = $request->rules()->options;
		if ($request->isFreshRound() === false && $request->votingMethod === RankedBallotRules::METHOD) {
			$requested = ($this->preflight->tiedOptionsOf(revoteOfRoundId: (string)$request->revoteOfRoundId()) ?? $requested);
		}

		return $this->rankedRules->openingOptions(
			votingMethod: $request->votingMethod,
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
