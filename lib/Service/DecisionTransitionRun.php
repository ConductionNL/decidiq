<?php

/**
 * Decidiq Decision Transition Run
 *
 * One lifecycle transition as it is being applied: the decision, the action,
 * the requesting user, the comment, the states it moves between, and the
 * meeting, domain and process-template policy it is guarded in.
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
 * @spec openspec/specs/decision-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * An immutable lifecycle transition run.
 *
 * Calling entering() sets the transition map entry and the decision's current
 * state once the decision is loaded; within() sets the guard context.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
final class DecisionTransitionRun {

	/**
	 * The states the transition may start from.
	 *
	 * @var array<int, string>
	 */
	private array $allowedFrom = [];

	/**
	 * The state the transition enters.
	 *
	 * @var string
	 */
	private string $newState = '';

	/**
	 * The decision's state before the transition.
	 *
	 * @var string
	 */
	private string $currentLifecycle = '';

	/**
	 * Linked meeting object array, when any.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $meeting = null;

	/**
	 * Governance domain driving the policy.
	 *
	 * @var string
	 */
	private string $domain = '';

	/**
	 * Process-template policy override, when any.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $policyOverride = null;

	/**
	 * Constructor for a DecisionTransitionRun.
	 *
	 * @param string      $decisionId    UUID of the decision
	 * @param string      $action        The transition action
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 * @param string      $comment       Transition comment recorded in the audit entry
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function __construct(
		public readonly string $decisionId,
		public readonly string $action,
		public readonly ?string $currentUserId,
		public readonly string $comment,
	) {
	}//end __construct()

	/**
	 * The same run, entering the given transition from the decision's state.
	 *
	 * @param array{from: array<int, string>, to: string} $transition       The transition map entry
	 * @param string                                       $currentLifecycle The decision's current state
	 *
	 * @return self The run with its states.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function entering(array $transition, string $currentLifecycle): self {
		$run = clone $this;
		$run->allowedFrom = $transition['from'];
		$run->newState = $transition['to'];
		$run->currentLifecycle = $currentLifecycle;
		return $run;
	}//end entering()

	/**
	 * The same run, guarded in the given meeting, domain and policy.
	 *
	 * @param array<string, mixed>|null $meeting        Linked meeting object array, when any
	 * @param string                    $domain         Governance domain driving the policy
	 * @param array<string, mixed>|null $policyOverride Process-template policy override, when any
	 *
	 * @return self The run with its guard context.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function within(?array $meeting, string $domain, ?array $policyOverride): self {
		$run = clone $this;
		$run->meeting = $meeting;
		$run->domain = $domain;
		$run->policyOverride = $policyOverride;
		return $run;
	}//end within()

	/**
	 * Whether the decision's state is one the transition may start from.
	 *
	 * @return bool True when the transition applies.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function startsFromAllowedState(): bool {
		return in_array(needle: $this->currentLifecycle, haystack: $this->allowedFrom, strict: true);
	}//end startsFromAllowedState()

	/**
	 * The decision's state before the transition.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function currentLifecycle(): string {
		return $this->currentLifecycle;
	}//end currentLifecycle()

	/**
	 * The state the transition enters.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function newState(): string {
		return $this->newState;
	}//end newState()

	/**
	 * The linked meeting.
	 *
	 * @return array<string, mixed>|null The meeting, or null.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function meeting(): ?array {
		return $this->meeting;
	}//end meeting()

	/**
	 * The governance domain.
	 *
	 * @return string The domain.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function domain(): string {
		return $this->domain;
	}//end domain()

	/**
	 * The process-template policy override.
	 *
	 * @return array<string, mixed>|null The override, or null.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function policyOverride(): ?array {
		return $this->policyOverride;
	}//end policyOverride()
}//end class
