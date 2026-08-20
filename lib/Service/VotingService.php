<?php

/**
 * Decidesk Voting Service
 *
 * The voting facade. Every operation is delegated to a single-purpose
 * collaborator: opening a round (VotingRoundOpener), casting a ballot
 * (VoteCastingService), closing a round (VotingRoundCloser), tallying
 * (VotingRoundResults), the public projection view (VotingRoundProjection) and
 * participant resolution (ParticipantUuidLookup).
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Stateless facade over the voting round governance rules.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingService {

	/**
	 * Configurable majority thresholds (mirrors Resolution.voteThreshold).
	 *
	 * @var string[]
	 */
	public const VOTE_THRESHOLDS = [
		'simple-majority',
		'qualified-majority-two-thirds',
		'qualified-majority-three-quarters',
		'unanimous',
	];

	/**
	 * Abstention-handling modes: 'exclude' keeps abstentions out of the
	 * calculation base (default); 'count' adds them to the base, making
	 * every threshold harder to reach.
	 *
	 * @var string[]
	 */
	public const ABSTENTION_MODES = ['exclude', 'count'];

	/**
	 * Tie-break rules for a simple-majority tie: 'rejected' (default — the
	 * motion fails, preserving the status quo), 'chair-decides' (result stays
	 * 'tied' until the chair re-runs close with an explicit casting vote),
	 * 'revote' (result stays 'tied'; the round may be reopened exactly once).
	 *
	 * @var string[]
	 */
	public const TIE_BREAK_RULES = ['rejected', 'chair-decides', 'revote'];

	/**
	 * Constructor for VotingService.
	 *
	 * @param VotingRoundOpener $opener Quorum check + round opening
	 * @param VoteCastingService $caster Ballot eligibility + recording
	 * @param VotingRoundCloser $closer The close sequence
	 * @param VotingRoundResults $results Tally + rule-aware result computation
	 * @param VotingRoundProjection $projection Public projection state
	 * @param ParticipantUuidLookup $participants Nextcloud UID -> participant UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly VotingRoundOpener $opener,
		private readonly VoteCastingService $caster,
		private readonly VotingRoundCloser $closer,
		private readonly VotingRoundResults $results,
		private readonly VotingRoundProjection $projection,
		private readonly ParticipantUuidLookup $participants,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
	 *
	 * @param string $nextcloudUid The Nextcloud user login name (UID)
	 *
	 * @return string|null The participant object UUID, or null if not found
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function resolveParticipantUuid(string $nextcloudUid): ?string {
		return $this->participants->forNextcloudUser(nextcloudUid: $nextcloudUid);
	}//end resolveParticipantUuid()

	/**
	 * Check whether quorum is met for a given meeting.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return bool True if quorum is met or quorumRequired is null/0
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function checkQuorum(string $meetingId): bool {
		return $this->opener->checkQuorum(meetingId: $meetingId);
	}//end checkQuorum()

	/**
	 * Open a VotingRound, optionally with preset participant UUIDs.
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
		return $this->opener->openVotingRound(
			motionId: $motionId,
			meetingId: $meetingId,
			votingMethod: $votingMethod,
			isSecret: $isSecret,
			closedAt: $closedAt,
			presetParticipantIds: $presetParticipantIds,
			revoteOfRoundId: $revoteOfRoundId,
			roundRules: $roundRules
		);

	}//end openVotingRound()

	/**
	 * Cast a vote in a VotingRound.
	 *
	 * Checks the round is open, verifies the participant is a member of the
	 * meeting that owns the round (#300), prevents duplicates (overwrites an
	 * existing vote), and enforces one-proxy-per-round for proxy votes.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The participant UUID
	 * @param string $value for | against | abstain
	 * @param bool $isProxy True when the participant is voting as proxy for another
	 * @param string|null $delegatorId The participant UUID being delegated (required when isProxy=true)
	 * @param string|null $callerUid The authenticated Nextcloud UID of the casting user (used only
	 *                               to detect an absence delegation when no formal proxy exists —
	 *                               delegations are configured by NC UID in the user settings)
	 *
	 * @return array<string,mixed> The created/updated Vote object
	 *
	 * @throws RuntimeException When the round is not open, the caller is not a meeting member,
	 *                          or proxy rules are violated
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
		return $this->caster->castVote(
			votingRoundId: $votingRoundId,
			participantId: $participantId,
			value: $value,
			isProxy: $isProxy,
			delegatorId: $delegatorId,
			callerUid: $callerUid
		);

	}//end castVote()

	/**
	 * Close a VotingRound, keeping the individual ballot values intact.
	 *
	 * When $chairCasting is provided, it is the chair's explicit casting vote
	 * resolving a tie under tieBreakRule 'chair-decides': the value is persisted
	 * as chairCastingVote on the round BEFORE the tally so computeResult() can
	 * resolve the tie, and the audit trail records how it was broken. Fail
	 * closed: a casting vote on a round whose tie-break rule is not
	 * 'chair-decides', or with a value other than for/against, is refused.
	 * Caller-side chair authorization is enforced by VotingController::close().
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string|null $chairCasting Optional chair casting vote ('for'|'against') resolving a tie
	 *
	 * @return array<string,mixed> The closed voting round object
	 *
	 * @throws RuntimeException When the casting vote is not permitted (fail closed)
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function closeVotingRound(string $votingRoundId, ?string $chairCasting = null): array {
		return $this->closeRound(votingRoundId: $votingRoundId, chairCasting: $chairCasting, anonymise: false);
	}//end closeVotingRound()

	/**
	 * Close a VotingRound and nullify the individual ballot values (GDPR).
	 *
	 * Identical to closeVotingRound() except for the final, destructive
	 * anonymisation step. It is a separate named method rather than a boolean
	 * flag so that irreversible data loss is explicit at every call site.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string|null $chairCasting Optional chair casting vote ('for'|'against') resolving a tie
	 *
	 * @return array<string,mixed> The closed voting round object
	 *
	 * @throws RuntimeException When the casting vote is not permitted (fail closed)
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function closeVotingRoundAnonymised(string $votingRoundId, ?string $chairCasting = null): array {
		return $this->closeRound(votingRoundId: $votingRoundId, chairCasting: $chairCasting, anonymise: true);
	}//end closeVotingRoundAnonymised()

	/**
	 * Compute the rule-aware voting result for a set of counts.
	 *
	 * Formula (F = for, A = against, B = abstain, all weighted; see the
	 * voting-system spec delta for the legal worked examples):
	 *
	 * - base = F + A ('exclude', default) or F + A + B ('count' — abstentions
	 *   make every threshold harder).
	 * - T == 0 -> 'invalid'.
	 * - Tie (simple-majority only, F == A and F > 0) -> tieBreakRule applies:
	 *   'rejected' -> 'rejected' (motion fails, status quo); 'chair-decides' ->
	 *   'tied' until the round carries a chairCastingVote; 'revote' -> 'tied'.
	 * - base == 0 -> 'rejected' (nothing can carry; guards unanimous vacuity).
	 * - simple-majority: adopted iff 2F > base (strict "50%+1").
	 * - qualified-majority-two-thirds: adopted iff 3F >= 2*base.
	 * - qualified-majority-three-quarters: adopted iff 4F >= 3*base.
	 * - unanimous: adopted iff F == base.
	 *
	 * Integer math throughout — no float threshold comparisons.
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
	public function computeResult(int $for, int $against, int $abstain, array $round): array {
		return $this->results->compute(for: $for, against: $against, abstain: $abstain, round: $round);
	}//end computeResult()

	/**
	 * Tally all votes in a VotingRound and update the round with counts and result.
	 *
	 * The result honours the round's configured voteThreshold, abstentionHandling
	 * and tieBreakRule (see computeResult()). The applied rules and the computed
	 * base are persisted on the round alongside the counts for auditability.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed> Tally with votesFor, votesAgainst, votesAbstain, total, base, result and applied rules
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function tallyResults(string $votingRoundId): array {
		return $this->results->tally(votingRoundId: $votingRoundId);
	}//end tallyResults()

	/**
	 * Record a show-of-hands tally for an open VotingRound.
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
	public function saveShowOfHandsTally(string $votingRoundId, int $votesFor, int $votesAgainst, int $votesAbstain): array {
		return $this->results->saveShowOfHands(
			votingRoundId: $votingRoundId,
			votesFor: $votesFor,
			votesAgainst: $votesAgainst,
			votesAbstain: $votesAbstain
		);

	}//end saveShowOfHandsTally()

	/**
	 * Get public-state for a VotingRound for projection display.
	 *
	 * Delegates to VotingRoundProjection, which owns the #303 visibility rules:
	 * a secret ballot and an unpublished round both read as not-found to an
	 * anonymous caller, so neither leaks even aggregate counts.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed>|null The public-state array or null if not found / not accessible
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function getPublicState(string $votingRoundId): ?array {
		return $this->projection->publicState(votingRoundId: $votingRoundId);
	}//end getPublicState()

	/**
	 * The shared close sequence behind the two named close methods.
	 *
	 * The ordering is load-bearing and is the reason this step still lives here
	 * rather than entirely in VotingRoundCloser: the casting vote must be
	 * persisted BEFORE the tally reads it, and the tally must run BEFORE the
	 * round is stamped closed.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string|null $chairCasting Optional chair casting vote resolving a tie
	 * @param bool $anonymise Whether to nullify individual vote values
	 *
	 * @return array<string,mixed> The closed voting round object
	 *
	 * @throws RuntimeException When the casting vote is not permitted (fail closed)
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function closeRound(string $votingRoundId, ?string $chairCasting, bool $anonymise): array {
		if ($chairCasting !== null) {
			$this->closer->applyChairCastingVote(votingRoundId: $votingRoundId, chairCasting: $chairCasting);
		}

		return $this->closer->close(
			votingRoundId: $votingRoundId,
			anonymise: $anonymise,
			tally: $this->tallyResults(votingRoundId: $votingRoundId)
		);

	}//end closeRound()
}//end class
