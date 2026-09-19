<?php

/**
 * Approval Basis Watcher
 *
 * An approval is of something. If that something is rewritten afterwards, the
 * approval on file is an approval of a document nobody has read. This decides
 * which grants a change to the subject invalidates.
 *
 * WHY IT IS OPT-IN AND WHY THAT MATTERS
 * --------------------------------------
 * A step declares `approvalBasis`, the property paths its approvers are
 * actually approving. An empty basis never withdraws anything. Without that,
 * every routine edit anywhere on a subject, an internal note, a tag, a changed
 * assignee, would silently throw away every approval on it, and the feature
 * would be turned off within a week by the first team it happened to.
 *
 * WHY THE COMPARISON IS OVER VALUES AND NOT OVER A CHANGE FEED
 * ------------------------------------------------------------
 * An edit that sets a property to the value it already had is not a change to
 * what was approved, whatever the update event says. Comparing the before and
 * after values means a save with no real change withdraws nothing, which is the
 * difference between a rule people trust and one they route around.
 *
 *
 * NOT REACHABLE YET, AND THAT IS THE FIRST THING TO KNOW ABOUT THIS CLASS
 * ------------------------------------------------------------------------
 * Measured 2026-09-18 with `git grep -l`: this class is named by exactly two
 * files, its own and its own unit test. Nothing in lib/ constructs it, no DI
 * registration mentions it, no route reaches it. Everything below describes what
 * it WOULD do; none of it runs today, and the green suite beside it tests the
 * class in isolation, so it cannot tell you otherwise.
 *
 * Read this before believing a present-tense sentence further down. Scope for
 * making it reachable is in
 * openspec/changes/the-decision-as-a-walked-process/reachability-scope.md.
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * Decides which granted approvals a change to the subject withdraws.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */
final class ApprovalBasisWatcher {
	/**
	 * The reason stamped on an approval the system withdrew by itself.
	 *
	 * @var string
	 */
	public const REASON_BASIS_CHANGED = 'basis-changed';

	/**
	 * Which of the step's basis properties actually changed.
	 *
	 * @param array<string, mixed> $step The step, with its `approvalBasis`.
	 * @param array<string, mixed> $before The subject as it was.
	 * @param array<string, mixed> $after The subject as it now is.
	 *
	 * @return array<int, string> The paths that changed, in the order the basis declares them.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function changedPaths(array $step, array $before, array $after): array {
		$basis = ($step['approvalBasis'] ?? []);
		if (is_array($basis) === false || $basis === []) {
			// An empty basis never withdraws anything. This is the opt-in.
			return [];
		}

		$changed = [];
		foreach ($basis as $path) {
			if (is_string($path) === false || $path === '') {
				continue;
			}

			if ($this->valueAt(subject: $before, path: $path) !== $this->valueAt(subject: $after, path: $path)) {
				$changed[] = $path;
			}
		}

		return $changed;
	}//end changedPaths()

	/**
	 * The grants this change invalidates, and the withdrawal to stamp on each.
	 *
	 * Only GRANTED actions are touched. A refusal is not invalidated by an edit:
	 * somebody who said no to an earlier version has not thereby said nothing,
	 * and asking them again is a decision for a human, not a side effect.
	 *
	 * @param array<string, mixed> $step The step, with its `approvalBasis`.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 * @param array<string, mixed> $before The subject as it was.
	 * @param array<string, mixed> $after The subject as it now is.
	 * @param string $withdrawnAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array<int, array<string, mixed>> The withdrawn actions, each with its reason and the paths that caused it.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function withdrawals(
		array $step,
		array $actions,
		array $before,
		array $after,
		string $withdrawnAt = '',
	): array {
		$changed = $this->changedPaths(step: $step, before: $before, after: $after);
		if ($changed === []) {
			return [];
		}

		$when = $withdrawnAt;
		if ($when === '') {
			$when = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		}

		$withdrawn = [];
		foreach ($actions as $action) {
			if (is_array($action) === false || $this->isGrant(action: $action) === false) {
				continue;
			}

			$action['state'] = 'withdrawn';
			$action['withdrawnReason'] = self::REASON_BASIS_CHANGED;
			$action['withdrawnAt'] = $when;
			$action['withdrawnPaths'] = $changed;

			$withdrawn[] = $action;
		}

		return $withdrawn;
	}//end withdrawals()

	/**
	 * Whether the step reopens because of this change.
	 *
	 * @param array<string, mixed> $step The step.
	 * @param array<int, array<string, mixed>> $actions The actions recorded against it.
	 * @param array<string, mixed> $before The subject as it was.
	 * @param array<string, mixed> $after The subject as it now is.
	 *
	 * @return bool True when at least one grant is withdrawn.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function reopens(array $step, array $actions, array $before, array $after): bool {
		return ($this->withdrawals(step: $step, actions: $actions, before: $before, after: $after) !== []);
	}//end reopens()

	/**
	 * The people whose approval was taken away from them, so they can be told.
	 *
	 * Being told is the point. An approver who is not notified finds out that
	 * their approval was withdrawn when somebody asks them why the step is still
	 * open, which is the worst possible moment.
	 *
	 * @param array<int, array<string, mixed>> $withdrawals The withdrawn actions.
	 *
	 * @return array<int, string> The actors, without duplicates.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function actorsToNotify(array $withdrawals): array {
		$actors = [];
		foreach ($withdrawals as $withdrawal) {
			$actor = (string)($withdrawal['actor'] ?? '');
			if ($actor !== '' && in_array($actor, $actors, true) === false) {
				$actors[] = $actor;
			}
		}

		return $actors;
	}//end actorsToNotify()

	/**
	 * Whether an action is a grant that still stands.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return bool True when it is a standing grant.
	 */
	private function isGrant(array $action): bool {
		$state = (string)($action['state'] ?? '');
		if ($state === 'withdrawn' || $state === 'refused') {
			return false;
		}

		if ($state === 'granted') {
			return true;
		}

		return in_array((string)($action['action'] ?? ''), ['approved', 'endorsed'], true);
	}//end isGrant()

	/**
	 * Read a dotted path out of a structure.
	 *
	 * @param array<string, mixed> $subject The subject.
	 * @param string $path The dotted path.
	 *
	 * @return mixed The value, or null when the path does not resolve.
	 */
	private function valueAt(array $subject, string $path): mixed {
		$cursor = $subject;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end valueAt()
}//end class
