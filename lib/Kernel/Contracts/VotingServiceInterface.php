<?php

/**
 * Contract of VotingService
 *
 * The public surface of VotingService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Service\VoteCast;
use RuntimeException;

/**
 * Contract of VotingService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingServiceInterface {

	/**
	 * Cast a vote in a VotingRound.
	 *
	 * Checks the round is open, verifies the participant is a member of the
	 * meeting that owns the round (#300), prevents duplicates (overwrites an
	 * existing vote), and enforces one-proxy-per-round for proxy votes.
	 *
	 * @param VoteCast $cast The ballot: round, participant, value or ranking, and proxy delegator
	 * @param string|null $callerUid The authenticated Nextcloud UID of the casting user (used only
	 *                               to detect an absence delegation when no formal proxy exists,
	 *                               since delegations are configured by NC UID in the user settings)
	 *
	 * @return array<string,mixed> The created/updated Vote object
	 *
	 * @throws RuntimeException When the round is not open, the caller is not a meeting member,
	 *                          or proxy rules are violated
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function castVote(VoteCast $cast, ?string $callerUid = null): array;

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
	public function closeVotingRound(string $votingRoundId, ?string $chairCasting = null): array;

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
	public function closeVotingRoundAnonymised(string $votingRoundId, ?string $chairCasting = null): array;

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
	public function getPublicState(string $votingRoundId): ?array;

	/**
	 * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
	 *
	 * @param string $nextcloudUid The Nextcloud user login name (UID)
	 *
	 * @return string|null The participant object UUID, or null if not found
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function resolveParticipantUuid(string $nextcloudUid): ?string;

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
	public function saveShowOfHandsTally(string $votingRoundId, int $votesFor, int $votesAgainst, int $votesAbstain): array;
}//end interface
