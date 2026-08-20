<?php

/**
 * Decision Transition Guard
 *
 * Pure guard for the decision lifecycle state machine. Holds the guarded
 * transition map (draft → proposed → deliberating → voting → decided →
 * enacted → archived) and the per-domain transition policy, mirroring the
 * MeetingTransitionGuard / WorkflowService pattern. No DI — every rule is
 * exhaustively unit-testable.
 *
 * @category Lifecycle
 * @package  OCA\Decidesk\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

/**
 * Guard for decision lifecycle transitions.
 *
 * Implements the decidesk guarded-transition-map pattern (NOT a Symfony
 * Workflow dependency): a const transition map is the single source of
 * truth for the lifecycle graph, and per-domain policy modulates quorum
 * enforcement, chair-only transitions, and decide-without-vote.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionTransitionGuard {

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
	 * Lifecycle states in which a decision has actually been DECIDED.
	 *
	 * Derived from the `Decision` schema's own `lifecycle` description in
	 * `lib/Settings/decidesk_register.json` — *"orthogonal to 'outcome' (the
	 * voting result, set when reaching 'decided')"* — and from the ordered
	 * state list above: `decided` is the first state past the vote, and
	 * `enacted` / `archived` are only reachable through it.
	 *
	 * `withdrawn` is deliberately NOT here. It is terminal in the
	 * `x-openregister-lifecycle` sense (nothing follows it) but a withdrawn
	 * decision was never decided, so it has no `adopted|rejected` outcome to
	 * record. Requiring one there would forbid withdrawing a draft.
	 *
	 * @var string[]
	 */
	public const TERMINAL_OUTCOME_STATES = [
		'decided',
		'enacted',
		'archived',
	];

	/**
	 * Fields a decision MUST carry once it reaches a terminal outcome state.
	 *
	 * These are exactly the two properties that used to sit unconditionally in
	 * the `Decision` schema's `required[]`. They were moved here because an
	 * in-flight motion has no legal outcome and the schema could not express
	 * "required only at the end": OpenRegister builds the validated schema in
	 * `Schema::getSchemaObject()` from a fixed key list
	 * (title/description/version/type/required/$schema/$id/properties), so a
	 * JSON-Schema `if`/`then` block never reaches the validator at all.
	 * Measured, not assumed — see the register's
	 * `x-decidesk-terminal-completeness` note.
	 *
	 * @var string[]
	 */
	public const TERMINAL_REQUIRED_FIELDS = [
		'outcome',
		'decisionDate',
	];

	/**
	 * The closed `outcome` vocabulary, mirroring the schema enum.
	 *
	 * A value outside this set (the shipped `motie-woonlasten-2025` seed once
	 * carried `outcome: "pending"`) is not a recorded outcome — it is an
	 * in-flight placeholder — so it does not satisfy terminal completeness.
	 *
	 * @var string[]
	 */
	public const OUTCOME_VALUES = [
		'adopted',
		'rejected',
	];

	/**
	 * Valid lifecycle transitions keyed by action name.
	 *
	 * Each entry defines:
	 * - `from`: the set of states from which this action is permitted
	 * - `to`:   the resulting state after the transition
	 *
	 * The `decide` action lists `deliberating` as a source, but that edge is
	 * only domain-permitted when `allowDecideWithoutVote` is set (operational
	 * MT decisions recorded without a formal voting round) — see
	 * isTransitionAllowed().
	 *
	 * @var array<string, array{from: string[], to: string}>
	 */
	private const TRANSITIONS = [
		'propose' => ['from' => ['draft'],                    'to' => 'proposed'],
		'deliberate' => ['from' => ['proposed'],                 'to' => 'deliberating'],
		'openVoting' => ['from' => ['deliberating'],             'to' => 'voting'],
		'decide' => ['from' => ['voting', 'deliberating'],   'to' => 'decided'],
		'enact' => ['from' => ['decided'],                  'to' => 'enacted'],
		'archive' => ['from' => ['decided', 'enacted'],       'to' => 'archived'],
	];

	/**
	 * Per-domain decision workflow policy.
	 *
	 * - `quorumEnforced`: entering `voting` requires the linked meeting's
	 *   quorum to be met.
	 * - `chairOnlyTransitions`: "from:to" pairs restricted to the meeting
	 *   chair.
	 * - `allowDecideWithoutVote`: permits the deliberating → decided edge
	 *   (operational domains where formal voting rounds are optional).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DOMAIN_POLICIES = [
		'legislative' => [
			'quorumEnforced' => true,
			'chairOnlyTransitions' => ['deliberating:voting', 'voting:decided'],
			'allowDecideWithoutVote' => false,
		],
		'association' => [
			'quorumEnforced' => true,
			'chairOnlyTransitions' => ['deliberating:voting'],
			'allowDecideWithoutVote' => false,
		],
		'corporate' => [
			'quorumEnforced' => true,
			'chairOnlyTransitions' => ['deliberating:voting'],
			'allowDecideWithoutVote' => false,
		],
		'operations' => [
			'quorumEnforced' => false,
			'chairOnlyTransitions' => [],
			'allowDecideWithoutVote' => true,
		],
		'citizen' => [
			'quorumEnforced' => false,
			'chairOnlyTransitions' => [],
			'allowDecideWithoutVote' => true,
		],
	];

	/**
	 * Default-deny fallback policy for unrecognized domains (same posture as
	 * WorkflowService::RESTRICTED_WORKFLOW / #314): quorum enforced, the
	 * sensitive transitions chair-only, no decide-without-vote. A mis-typed
	 * or injected domain string can never grant a more permissive policy.
	 *
	 * @var array<string, mixed>
	 */
	private const RESTRICTED_POLICY = [
		'quorumEnforced' => true,
		'chairOnlyTransitions' => ['deliberating:voting', 'voting:decided'],
		'allowDecideWithoutVote' => false,
	];

	/**
	 * Get the decision workflow policy for a governance domain.
	 *
	 * Unknown domains fall back to the restrictive default-deny policy.
	 *
	 * When a non-null $policyOverride is supplied (process-configuration: a
	 * governance body's assigned process template, translated by
	 * ProcessTemplatePolicyResolver) it REPLACES the domain-keyed lookup. A null
	 * override is byte-identical to the pre-process-config behaviour, so bodies
	 * without a template keep the built-in hardcoded default-deny policy.
	 *
	 * @param string $domain The governance domain (legislative|association|corporate|operations|citizen)
	 * @param array<string, mixed>|null $policyOverride Optional template-derived policy that replaces the domain lookup
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed> The decision workflow policy
	 */
	public function getDomainPolicy(string $domain, ?array $policyOverride = null): array {
		if ($policyOverride !== null) {
			return $policyOverride;
		}

		return self::DOMAIN_POLICIES[$domain] ?? self::RESTRICTED_POLICY;
	}//end getDomainPolicy()

	/**
	 * Resolve a transition action to its map entry, or null when unknown.
	 *
	 * @param string $action Transition action name
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array{from: string[], to: string}|null
	 */
	public function resolveTransition(string $action): ?array {
		return self::TRANSITIONS[$action] ?? null;
	}//end resolveTransition()

	/**
	 * List all action names known to the transition map.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string[]
	 */
	public function getKnownActions(): array {
		return array_keys(self::TRANSITIONS);
	}//end getKnownActions()

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
	public function getAvailableActions(string $currentLifecycle, string $domain = 'operations', ?array $policyOverride = null): array {
		$available = [];
		foreach (self::TRANSITIONS as $action => $transition) {
			if (in_array(needle: $currentLifecycle, haystack: $transition['from'], strict: true) === false) {
				continue;
			}

			$isAllowed = $this->isTransitionAllowed(
				domain: $domain,
				fromState: $currentLifecycle,
				toState: $transition['to'],
				policyOverride: $policyOverride
			);
			if ($isAllowed === false) {
				continue;
			}

			$available[] = $action;
		}

		return $available;
	}//end getAvailableActions()

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
	public function isTransitionAllowed(string $domain, string $fromState, string $toState, ?array $policyOverride = null): bool {
		$policy = $this->getDomainPolicy(domain: $domain, policyOverride: $policyOverride);

		// The deliberating → decided shortcut skips the formal voting round and
		// is only available in domains that explicitly allow it.
		if ($fromState === 'deliberating' && $toState === 'decided'
			&& ($policy['allowDecideWithoutVote'] ?? false) !== true
		) {
			return false;
		}

		return true;
	}//end isTransitionAllowed()

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
	public function requiresChairAuthorization(string $domain, string $from, string $to, ?array $policyOverride = null): bool {
		$policy = $this->getDomainPolicy(domain: $domain, policyOverride: $policyOverride);
		return in_array(needle: "$from:$to", haystack: ($policy['chairOnlyTransitions'] ?? []), strict: true);
	}//end requiresChairAuthorization()

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
	public function isQuorumRequired(string $domain, ?array $policyOverride = null): bool {
		$policy = $this->getDomainPolicy(domain: $domain, policyOverride: $policyOverride);
		return ($policy['quorumEnforced'] ?? true) === true;
	}//end isQuorumRequired()

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
	public function isVotingOpenAllowed(array $meeting): bool {
		return ($meeting['quorumWith'] ?? false) === true;
	}//end isVotingOpenAllowed()

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
	public function isEnactAllowed(array $decision): bool {
		return ($decision['outcome'] ?? '') === 'adopted';
	}//end isEnactAllowed()

	/**
	 * Whether entering this lifecycle state means the decision has been decided.
	 *
	 * @param string $lifecycle The lifecycle state being entered
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return bool True when the state is a terminal outcome state
	 */
	public function isTerminalOutcomeState(string $lifecycle): bool {
		return in_array(needle: $lifecycle, haystack: self::TERMINAL_OUTCOME_STATES, strict: true);
	}//end isTerminalOutcomeState()

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
	public function getMissingTerminalFields(array $decision): array {
		$missing = [];
		foreach (self::TERMINAL_REQUIRED_FIELDS as $field) {
			$value = ($decision[$field] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				$missing[] = $field;
				continue;
			}

			if ($field === 'outcome'
				&& in_array(needle: $value, haystack: self::OUTCOME_VALUES, strict: true) === false
			) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end getMissingTerminalFields()
}//end class
