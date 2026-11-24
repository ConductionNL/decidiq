<?php

/**
 * Which decisions are still travelling a route on decidiq's old approval engine.
 *
 * Routes that had an open step when approval-routes-on-flow-user-tasks was
 * installed finish on the old engine; every send after it runs as a flow. This
 * answers which decisions are still on the old engine, marks them so, and is
 * what `occ decidiq:approval-routes:legacy` lists: the old engine can be
 * removed once the list is empty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Finds and marks the decisions whose route runs on the old engine.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */
class LegacyApprovalRoutes {
	/**
	 * Stage statuses that mean the route has not concluded.
	 *
	 * @var list<string>
	 */
	public const OPEN_STATUSES = ['active', 'pending'];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads stages and writes decisions.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * The decisions with an open stage on the old engine, each with its open step.
	 *
	 * @return list<array{decision: string, sequence: int, label: string}> One row per decision, its lowest open step.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	public function open(): array {
		$byDecision = [];
		foreach (self::OPEN_STATUSES as $status) {
			foreach ($this->store->findAll(schema: 'decision-stage', filters: ['status' => $status]) as $stage) {
				$decision = self::idOf(value: ($stage['decision'] ?? null));
				if ($decision === '') {
					continue;
				}

				$sequence = (int)($stage['sequence'] ?? 0);
				if (isset($byDecision[$decision]) === false || $sequence < $byDecision[$decision]['sequence']) {
					$byDecision[$decision] = ['decision' => $decision, 'sequence' => $sequence, 'label' => (string)($stage['label'] ?? '')];
				}
			}
		}

		ksort($byDecision);

		return array_values($byDecision);
	}//end open()

	/**
	 * Mark every decision still on the old engine as `legacy`.
	 *
	 * Idempotent: a decision already marked is not written again.
	 *
	 * @return int How many decisions were marked now.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
	 */
	public function mark(): int {
		$marked = 0;
		foreach ($this->open() as $row) {
			$decision = $this->store->find(schema: 'decision', uuid: $row['decision']);
			if ($decision === null || ($decision['approvalEngine'] ?? null) === ApprovalRouteFlowStarter::ENGINE_LEGACY) {
				continue;
			}

			$this->store->patch(schema: 'decision', data: ['approvalEngine' => ApprovalRouteFlowStarter::ENGINE_LEGACY], uuid: $row['decision']);
			$marked++;
		}

		return $marked;
	}//end mark()

	/**
	 * A relation's id, whether stored as an id or expanded.
	 *
	 * @param mixed $value The relation.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['uuid'] ?? ''));
		}

		return trim((string)$value);
	}//end idOf()
}//end class
