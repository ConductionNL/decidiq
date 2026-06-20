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
class DecisionTransitionGuard
{

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
        'propose'    => ['from' => ['draft'],                    'to' => 'proposed'],
        'deliberate' => ['from' => ['proposed'],                 'to' => 'deliberating'],
        'openVoting' => ['from' => ['deliberating'],             'to' => 'voting'],
        'decide'     => ['from' => ['voting', 'deliberating'],   'to' => 'decided'],
        'enact'      => ['from' => ['decided'],                  'to' => 'enacted'],
        'archive'    => ['from' => ['decided', 'enacted'],       'to' => 'archived'],
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
            'quorumEnforced'         => true,
            'chairOnlyTransitions'   => ['deliberating:voting', 'voting:decided'],
            'allowDecideWithoutVote' => false,
        ],
        'association' => [
            'quorumEnforced'         => true,
            'chairOnlyTransitions'   => ['deliberating:voting'],
            'allowDecideWithoutVote' => false,
        ],
        'corporate'   => [
            'quorumEnforced'         => true,
            'chairOnlyTransitions'   => ['deliberating:voting'],
            'allowDecideWithoutVote' => false,
        ],
        'operations'  => [
            'quorumEnforced'         => false,
            'chairOnlyTransitions'   => [],
            'allowDecideWithoutVote' => true,
        ],
        'citizen'     => [
            'quorumEnforced'         => false,
            'chairOnlyTransitions'   => [],
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
        'quorumEnforced'         => true,
        'chairOnlyTransitions'   => ['deliberating:voting', 'voting:decided'],
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
     * @param string                    $domain         The governance domain (legislative|association|corporate|operations|citizen)
     * @param array<string, mixed>|null $policyOverride Optional template-derived policy that replaces the domain lookup
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, mixed> The decision workflow policy
     */
    public function getDomainPolicy(string $domain, ?array $policyOverride=null): array
    {
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
    public function resolveTransition(string $action): ?array
    {
        return self::TRANSITIONS[$action] ?? null;

    }//end resolveTransition()

    /**
     * List all action names known to the transition map.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return string[]
     */
    public function getKnownActions(): array
    {
        return array_keys(self::TRANSITIONS);

    }//end getKnownActions()

    /**
     * Return the action names available from a lifecycle state under the
     * given domain policy (the spec's "allowed transitions from the current
     * state"). The deliberating → decided edge is filtered out unless the
     * domain allows deciding without a formal vote.
     *
     * @param string                    $currentLifecycle The decision's current lifecycle state
     * @param string                    $domain           The governance domain
     * @param array<string, mixed>|null $policyOverride   Optional template-derived policy (process-configuration)
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return string[] Action names the caller may attempt from this state
     */
    public function getAvailableActions(string $currentLifecycle, string $domain='operations', ?array $policyOverride=null): array
    {
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
     * @param string                    $domain         The governance domain
     * @param string                    $fromState      The current lifecycle state
     * @param string                    $toState        The target lifecycle state
     * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return bool True when the edge is permitted by domain policy
     */
    public function isTransitionAllowed(string $domain, string $fromState, string $toState, ?array $policyOverride=null): bool
    {
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
     * @param string                    $domain         The governance domain
     * @param string                    $from           The current lifecycle state
     * @param string                    $to             The target lifecycle state
     * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return bool True when only the chair may perform this transition
     */
    public function requiresChairAuthorization(string $domain, string $from, string $to, ?array $policyOverride=null): bool
    {
        $policy = $this->getDomainPolicy(domain: $domain, policyOverride: $policyOverride);
        return in_array(needle: "$from:$to", haystack: ($policy['chairOnlyTransitions'] ?? []), strict: true);

    }//end requiresChairAuthorization()

    /**
     * Check whether the domain enforces meeting quorum before a decision may
     * enter the `voting` state.
     *
     * @param string                    $domain         The governance domain
     * @param array<string, mixed>|null $policyOverride Optional template-derived policy (process-configuration)
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return bool True when quorum must be met before openVoting
     */
    public function isQuorumRequired(string $domain, ?array $policyOverride=null): bool
    {
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
    public function isVotingOpenAllowed(array $meeting): bool
    {
        return ($meeting['quorumMet'] ?? false) === true;

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
    public function isEnactAllowed(array $decision): bool
    {
        return ($decision['outcome'] ?? '') === 'adopted';

    }//end isEnactAllowed()
}//end class
