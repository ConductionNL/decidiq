<?php

/**
 * Contract of DecisionTransitionGuard
 *
 * The public surface of DecisionTransitionGuard that other components call, so they depend
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

/**
 * Contract of DecisionTransitionGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionTransitionGuardInterface {

	/**
	 * Ordered lifecycle states of the decision state machine.
	 *
	 * @var string[]
	 */
	public const STATES = [
		'draft',
		'proposed',
		'deliberating',
		'voting',
		'decided',
		'enacted',
		'archived',
	];

	/**
	 * Return the action names available from a lifecycle state under the
	 * given domain policy (the spec's "allowed transitions from the current
	 * state"). The deliberating → decided edge is filtered out unless the
	 * domain allows deciding without a formal vote.
	 *
	 * @param string $currentLifecycle The decision's current lifecycle state
	 * @param string $domain The governance domain
	 * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return string[] Action names the caller may attempt from this state
	 */
	public function getAvailableActions(string $currentLifecycle, string $domain = 'operations', ?array $policyOverride = null): array;

	/**
	 * List all action names known to the transition map.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string[]
	 */
	public function getKnownActions(): array;

	/**
	 * List the terminal-completeness fields a decision is still missing.
	 *
	 * This is the enforcement that replaces the unconditional `required[]`
	 * entries on the schema. An empty list means the decision may enter a
	 * terminal outcome state; anything else names precisely what is absent so
	 * the caller can say so.
	 *
	 * A present-but-out-of-vocabulary `outcome` counts as missing: recording
	 * `pending` as the result of a vote is the same defect as recording
	 * nothing.
	 *
	 * @param array<string, mixed> $decision Decision object array
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string[] The names of the absent (or unusable) terminal fields
	 */
	public function getMissingTerminalFields(array $decision): array;

	/**
	 * Check whether a decision may be enacted: only decisions with a positive
	 * voting outcome (`outcome=adopted`) qualify.
	 *
	 * @param array<string, mixed> $decision Decision object array
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return bool True when the decision outcome permits enactment
	 */
	public function isEnactAllowed(array $decision): bool;

	/**
	 * Check whether the domain enforces meeting quorum before a decision may
	 * enter the `voting` state.
	 *
	 * @param string $domain The governance domain
	 * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return bool True when quorum must be met before openVoting
	 */
	public function isQuorumRequired(string $domain, ?array $policyOverride = null): bool;

	/**
	 * Whether entering this lifecycle state means the decision has been decided.
	 *
	 * @param string $lifecycle The lifecycle state being entered
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return bool True when the state is a terminal outcome state
	 */
	public function isTerminalOutcomeState(string $lifecycle): bool;

	/**
	 * Validate whether a from → to edge is permitted by the domain policy.
	 *
	 * The transition map itself is validated by the caller (resolveTransition
	 * + from-state membership); this method applies the domain-level gates on
	 * top. Chair-only transitions are allowed by domain rules — the caller
	 * must separately enforce chair authorization via
	 * requiresChairAuthorization().
	 *
	 * @param string $domain The governance domain
	 * @param string $fromState The current lifecycle state
	 * @param string $toState The target lifecycle state
	 * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return bool True when the edge is permitted by domain policy
	 */
	public function isTransitionAllowed(string $domain, string $fromState, string $toState, ?array $policyOverride = null): bool;

	/**
	 * Check whether the linked meeting permits opening the vote.
	 *
	 * Reads the declaratively-computed `quorumMet` field on the Meeting
	 * schema (x-openregister-calculations) — the same field
	 * MeetingTransitionGuard::isOpenAllowed() reads.
	 *
	 * @param array<string, mixed> $meeting Meeting object array (already loaded by the caller)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return bool True when the meeting's quorum is met
	 */
	public function isVotingOpenAllowed(array $meeting): bool;

	/**
	 * Check whether a transition is restricted to the meeting chair in the
	 * given domain.
	 *
	 * @param string $domain The governance domain
	 * @param string $from The current lifecycle state
	 * @param string $to The target lifecycle state
	 * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return bool True when only the chair may perform this transition
	 */
	public function requiresChairAuthorization(string $domain, string $from, string $to, ?array $policyOverride = null): bool;

	/**
	 * Resolve a transition action to its map entry, or null when unknown.
	 *
	 * @param string $action Transition action name
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array{from: string[], to: string}|null
	 */
	public function resolveTransition(string $action): ?array;
}//end interface
