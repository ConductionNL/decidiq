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
	 * @param string               $subject The subject's uuid.
	 * @param array<string, mixed> $before  The subject as it was.
	 * @param array<string, mixed> $after   The subject as it now is.
	 *
	 * @return array<int, array<string, mixed>> The withdrawn actions, each with its `withdrawnPaths`.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function subjectChanged(string $subject, array $before, array $after): array {
		if (trim($subject) === '') {
			return [];
		}

		$stages = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => $subject]);
		$watched = array_values(array_filter($stages, static fn (array $stage): bool => self::isWatched(stage: $stage)));
		if ($watched === []) {
			return [];
		}

		$actions = $this->store->findAll(schema: 'approval-action', filters: ['subject' => $subject]);
		$withdrawn = [];
		$reopened = [];
		$withdrawnAt = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		foreach ($watched as $stage) {
			$stepWithdrawals = $this->withdrawOnStage(
				stage: $stage,
				actions: self::actionsOfStep(actions: $actions, stage: $stage, skip: $withdrawn),
				before: $before,
				after: $after,
				withdrawnAt: $withdrawnAt,
			);
			if ($stepWithdrawals === []) {
				continue;
			}

			foreach ($stepWithdrawals as $withdrawal) {
				$withdrawn[(string)$withdrawal['id']] = $withdrawal;
			}

			if ((string)($stage['status'] ?? '') === 'decided') {
				$reopened[] = $stage;
			}
		}

		if ($withdrawn === []) {
			return [];
		}

		$this->advancer->reopenWithdrawnStages(stages: $stages, reopened: $reopened);
		$this->notifier->notify(
			withdrawals: array_values($withdrawn),
			subject: $subject,
			subjectSchema: (string)($watched[0]['note'] ?? ''),
		);

		return array_values($withdrawn);
	}//end subjectChanged()

	/**
	 * Withdraw the grants of one stage that the change made stale.
	 *
	 * @param array<string, mixed>             $stage       The stage, with its `approvalBasis`.
	 * @param array<int, array<string, mixed>> $actions     The actions on its step.
	 * @param array<string, mixed>             $before      The subject as it was.
	 * @param array<string, mixed>             $after       The subject as it now is.
	 * @param string                           $withdrawnAt When.
	 *
	 * @return array<int, array<string, mixed>> The withdrawn actions.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	private function withdrawOnStage(array $stage, array $actions, array $before, array $after, string $withdrawnAt): array {
		$withdrawals = $this->watcher->withdrawals(
			step: $stage,
			actions: $actions,
			before: $before,
			after: $after,
			withdrawnAt: $withdrawnAt,
		);

		foreach ($withdrawals as $withdrawal) {
			$this->store->patch(
				schema: 'approval-action',
				data: [
					'state' => 'withdrawn',
					'withdrawnReason' => ApprovalBasisWatcher::REASON_BASIS_CHANGED,
					'withdrawnAt' => $withdrawnAt,
				],
				uuid: (string)$withdrawal['id'],
			);
		}

		return $withdrawals;
	}//end withdrawOnStage()

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
	 * The actions recorded on a stage's step that are not already withdrawn
	 * in this pass and have an id to write to.
	 *
	 * @param array<int, array<string, mixed>> $actions The subject's actions.
	 * @param array<string, mixed>             $stage   The stage.
	 * @param array<string, mixed>             $skip    The ids already withdrawn.
	 *
	 * @return array<int, array<string, mixed>> The step's actions.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function actionsOfStep(array $actions, array $stage, array $skip): array {
		$sequence = (int)($stage['sequence'] ?? 0);

		return array_values(
			array_filter(
				$actions,
				static function (array $action) use ($sequence, $skip): bool {
					$id = trim((string)($action['id'] ?? ''));
					return $id !== '' && isset($skip[$id]) === false && (int)($action['step'] ?? 0) === $sequence;
				}
			)
		);
	}//end actionsOfStep()
}//end class
