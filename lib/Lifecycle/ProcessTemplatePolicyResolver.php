<?php

/**
 * Process Template Policy Resolver
 *
 * Pure translator that maps a process-template object into the policy shape the
 * DecisionTransitionGuard / WorkflowService already consume. No DI — every rule
 * is exhaustively unit-testable. Returns null when the template is malformed so
 * the caller falls back to the built-in hardcoded default-deny policy (fail-safe,
 * never fail-open).
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
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

/**
 * Translates a process template into a guard policy override.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplatePolicyResolver
{
    /**
     * Translate a process-template object array into the guard policy shape
     * `{quorumEnforced, chairOnlyTransitions, allowDecideWithoutVote}`.
     *
     * Returns null when the template lacks a usable state machine, so the caller
     * reverts to the built-in hardcoded domain policy. A malformed template can
     * therefore never *loosen* a guard — it only reverts to default-deny.
     *
     * @param array<string, mixed>|null $template The process-template object array, or null when none assigned
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, mixed>|null The guard policy override, or null to fall back
     */
    public function resolve(?array $template): ?array
    {
        if (is_array($template) === false) {
            return null;
        }

        $stateMachine = ($template['stateMachine'] ?? null);
        if (is_array($stateMachine) === false) {
            return null;
        }

        $transitions = ($stateMachine['transitions'] ?? null);
        if (is_array($transitions) === false) {
            return null;
        }

        $chairOnly = [];
        foreach ($transitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            $from = ($transition['from'] ?? null);
            $to   = ($transition['to'] ?? null);
            if (is_string($from) === false || is_string($to) === false || $from === '' || $to === '') {
                continue;
            }

            $isChairOnly = (($transition['chairOnly'] ?? false) === true);
            if ($isChairOnly === false && in_array('chair_only', (array) ($transition['guards'] ?? []), true) === true) {
                $isChairOnly = true;
            }

            if ($isChairOnly === true) {
                $chairOnly[] = "$from:$to";
            }
        }//end foreach

        return [
            'quorumEnforced'         => (($template['quorumRequired'] ?? true) === true),
            'chairOnlyTransitions'   => $chairOnly,
            'allowDecideWithoutVote' => (($template['allowDecideWithoutVote'] ?? false) === true),
        ];

    }//end resolve()

    /**
     * Extract the template's default voting rule, or null when not configured.
     *
     * @param array<string, mixed>|null $template The process-template object array, or null
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array{voteThreshold?: string, abstentionHandling?: string, tieBreakRule?: string}|null
     */
    public function resolveVotingRule(?array $template): ?array
    {
        if (is_array($template) === false) {
            return null;
        }

        $rule = ($template['votingRule'] ?? null);
        if (is_array($rule) === false || $rule === []) {
            return null;
        }

        return $rule;

    }//end resolveVotingRule()
}//end class
