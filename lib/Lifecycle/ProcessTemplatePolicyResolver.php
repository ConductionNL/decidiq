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
class ProcessTemplatePolicyResolver {
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
	public function resolve(?array $template): ?array {
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

		return [
			'quorumEnforced' => (($template['quorumRequired'] ?? true) === true),
			'chairOnlyTransitions' => $this->chairOnlyTransitions(transitions: $transitions),
			'allowDecideWithoutVote' => (($template['allowDecideWithoutVote'] ?? false) === true),
		];

	}//end resolve()

	/**
	 * Collect the `from:to` edges that only the chair may traverse.
	 *
	 * @param array<int|string, mixed> $transitions The declared transitions
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return list<string> The chair-only transition edges
	 */
	private function chairOnlyTransitions(array $transitions): array {
		$chairOnly = [];
		foreach ($transitions as $transition) {
			$edge = $this->transitionEdge(transition: $transition);
			if ($edge === null) {
				continue;
			}

			if ($this->isChairOnly(transition: $transition) === true) {
				$chairOnly[] = $edge;
			}
		}

		return $chairOnly;
	}//end chairOnlyTransitions()

	/**
	 * Render a transition as its `from:to` edge key, or null when malformed.
	 *
	 * @param mixed $transition A single transitions[] entry
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return string|null The edge key, or null when the transition is unusable
	 */
	private function transitionEdge(mixed $transition): ?string {
		if (is_array($transition) === false) {
			return null;
		}

		$from = ($transition['from'] ?? null);
		$to = ($transition['to'] ?? null);
		if (is_string($from) === false || is_string($to) === false || $from === '' || $to === '') {
			return null;
		}

		return "$from:$to";
	}//end transitionEdge()

	/**
	 * Decide whether a transition is chair-only, via the flag or the guard token.
	 *
	 * @param array<string, mixed> $transition A well-formed transitions[] entry
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return bool True when only the chair may traverse the transition
	 */
	private function isChairOnly(array $transition): bool {
		if (($transition['chairOnly'] ?? false) === true) {
			return true;
		}

		return in_array('chair_only', (array)($transition['guards'] ?? []), true);
	}//end isChairOnly()

	/**
	 * Extract the template's default voting rule, or null when not configured.
	 *
	 * @param array<string, mixed>|null $template The process-template object array, or null
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array{voteThreshold?: string, abstentionHandling?: string, tieBreakRule?: string}|null
	 */
	public function resolveVotingRule(?array $template): ?array {
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
