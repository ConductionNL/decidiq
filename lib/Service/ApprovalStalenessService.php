<?php

/**
 * Approval Staleness Service
 *
 * When a subject on an approval route changes, withdraws the grants whose
 * approval basis the change touched, reopens their steps and tells the actors
 * (REQ-DWP-003). `ApprovalBasisWatcher` decides WHICH grants a change
 * invalidates; this class reads the subject's stages and actions, writes the
 * withdrawals, rewinds the route through the engine's own stage walk and
 * hands the actors to the notifier.
 *
 * OPT-IN. A stage without an `approvalBasis` is never looked at, and a subject
 * none of whose stages declares one costs one stage read and nothing more.
 *
 * WHICH STAGES. Only an `active` stage (a grant on a step still signing) and a
 * `decided` one (the step closed on the grants) can hold a standing grant that
 * matters. A pending or skipped stage is where the route has not been, or was
 * rewound from; its old grants are history already.
 *
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
 * Withdraws stale approvals when their subject changes.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */
class ApprovalStalenessService {
	/**
	 * The stage states that can hold a standing grant.
	 *
	 * @var array<int, string>
	 */
	private const WATCHED_STATES = ['active', 'decided'];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore        $store    Reads the stages and actions, writes the withdrawals.
	 * @param ApprovalBasisWatcher       $watcher  Decides which grants a change withdraws.
	 * @param ApprovalRouteAdvancer      $advancer Rewinds the route through the engine's stage walk.
	 * @param ApprovalWithdrawalNotifier $notifier Tells the actors.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalBasisWatcher $watcher,
		private readonly ApprovalRouteAdvancer $advancer,
		private readonly ApprovalWithdrawalNotifier $notifier,
	) {
	}//end __construct()

	/**
	 * Apply a change of the subject to the approvals on it.
	 *
	 * @param SubjectVersions $change The subject as it was and as it now is.
	 *
	 * @return array<int, array<string, mixed>> The withdrawn actions, each with its `withdrawnPaths`.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function subjectChanged(SubjectVersions $change): array {
		if (trim($change->subject) === '') {
			return [];
		}

		$stages = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => $change->subject]);
		$watched = array_values(array_filter($stages, static fn (array $stage): bool => self::isWatched(stage: $stage)));
		if ($watched === []) {
			return [];
		}

		$actions = $this->store->findAll(schema: 'approval-action', filters: ['subject' => $change->subject]);
		$withdrawnAt = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		$withdrawn = [];
		$reopened = [];
		foreach ($watched as $stage) {
			$stepWithdrawals = $this->watcher->withdrawals(
				step: $stage,
				actions: self::actionsOfStep(actions: $actions, sequence: (int)($stage['sequence'] ?? 0)),
				before: $change->before,
				after: $change->after,
				withdrawnAt: $withdrawnAt,
			);
			foreach ($stepWithdrawals as $withdrawal) {
				// Keyed by id: two routes on the subject with a stage at the
				// same step see the same action, which is written once.
				$withdrawn[(string)$withdrawal['id']] = $withdrawal;
			}

			if ($stepWithdrawals !== [] && (string)($stage['status'] ?? '') === 'decided') {
				$reopened[] = $stage;
			}
		}

		if ($withdrawn === []) {
			return [];
		}

		$this->writeWithdrawals(withdrawals: $withdrawn, withdrawnAt: $withdrawnAt);
		$this->advancer->reopenWithdrawnStages(stages: $stages, reopened: $reopened);
		$this->notifier->notify(
			withdrawals: array_values($withdrawn),
			deepLink: ApprovalWithdrawalNotifier::deepLink(subject: $change->subject, subjectSchema: (string)($watched[0]['note'] ?? '')),
		);

		return array_values($withdrawn);
	}//end subjectChanged()

	/**
	 * Write each withdrawal onto its action.
	 *
	 * @param array<string, array<string, mixed>> $withdrawals Per action id, the withdrawn action.
	 * @param string                              $withdrawnAt When.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	private function writeWithdrawals(array $withdrawals, string $withdrawnAt): void {
		foreach (array_keys($withdrawals) as $id) {
			$this->store->patch(
				schema: 'approval-action',
				data: [
					'state' => 'withdrawn',
					'withdrawnReason' => ApprovalBasisWatcher::REASON_BASIS_CHANGED,
					'withdrawnAt' => $withdrawnAt,
				],
				uuid: (string)$id,
			);
		}
	}//end writeWithdrawals()

	/**
	 * Whether a stage declares a basis and can hold a standing grant.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool True when it is watched.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function isWatched(array $stage): bool {
		$basis = ($stage['approvalBasis'] ?? []);

		return is_array($basis) === true
			&& $basis !== []
			&& trim((string)($stage['id'] ?? '')) !== ''
			&& in_array((string)($stage['status'] ?? ''), self::WATCHED_STATES, true) === true;
	}//end isWatched()

	/**
	 * The actions recorded on a step that have an id to write to.
	 *
	 * @param array<int, array<string, mixed>> $actions  The subject's actions.
	 * @param int                              $sequence The step.
	 *
	 * @return array<int, array<string, mixed>> The step's actions.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function actionsOfStep(array $actions, int $sequence): array {
		return array_values(
			array_filter(
				$actions,
				static fn (array $action): bool => trim((string)($action['id'] ?? '')) !== '' && (int)($action['step'] ?? 0) === $sequence
			)
		);
	}//end actionsOfStep()
}//end class
