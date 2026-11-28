<?php

/**
 * Decidiq approval-action state repair.
 *
 * Backfills `state` onto the approval actions stored before the route engine
 * wrote it. The schema defaults `state` to `open`, so every sign-off recorded
 * before ApprovalRouteService::appendAction() wrote the state reads as an
 * approval still awaited, and would appear as work on a task list that has
 * already been done.
 *
 * Only a row that records an act is touched: one with a verb (`approved`,
 * `returned`, `lapsed`, ...) whose state is missing or `open`. A row with no
 * verb is a real open work item and is left alone, and a row already carrying
 * a terminal state (a lapse, a withdrawn grant) keeps it. No other field is
 * written, so the trail stays the audit record it was.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ApprovalActionStateRepairInterface;

/**
 * Writes the terminal state onto stored approval actions that record an act.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */
class ApprovalActionStateRepair implements ApprovalActionStateRepairInterface {
	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and patches the approval actions.
	 * @param OpenApprovalGuard   $guard Says which state each recorded verb ends in, the
	 *        same mapping the engine writes with. A pure rule, so it defaults to a real one.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly OpenApprovalGuard $guard = new OpenApprovalGuard(),
	) {
	}//end __construct()

	/**
	 * Write the terminal state onto every stored action that records an act but still reads as open.
	 *
	 * Idempotent: a repaired row carries a terminal state and is skipped on the
	 * next run.
	 *
	 * @return integer How many actions were repaired.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function repair(): int {
		$repaired = 0;
		foreach ($this->store->findAll(schema: 'approval-action', filters: []) as $action) {
			$state = $this->missingState(action: $action);
			if ($state === '') {
				continue;
			}

			$this->store->patch(
				schema: 'approval-action',
				data: ['state' => $state],
				uuid: (string)($action['id'] ?? ''),
			);
			$repaired++;
		}

		return $repaired;
	}//end repair()

	/**
	 * The state a stored action should carry and does not, or an empty string when it needs none.
	 *
	 * @param array<string, mixed> $action The stored action.
	 *
	 * @return string The state to write, or an empty string.
	 */
	private function missingState(array $action): string {
		$current = (string)($action['state'] ?? '');
		if ($current !== '' && $current !== OpenApprovalGuard::STATE_OPEN) {
			return '';
		}

		return $this->guard->stateForVerb(verb: trim((string)($action['action'] ?? '')));
	}//end missingState()
}//end class
