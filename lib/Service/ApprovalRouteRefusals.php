<?php

/**
 * Why an approval route cannot run as a flow on the installed OpenRegister.
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
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The refusals for a route and its steps, one message per missing feature.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
 */
class ApprovalRouteRefusals {
	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteFlowCapabilities $capabilities What the installed OpenRegister offers.
	 */
	public function __construct(
		private readonly ApprovalRouteFlowCapabilities $capabilities,
	) {
	}//end __construct()

	/**
	 * Refusals that concern the route as a whole.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function ofRoute(array $route): array {
		$refusals = [];
		if (trim((string)($route['deadline'] ?? '')) !== '') {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::DEADLINE_SPLIT, where: 'The route');
		}

		$excluded = ($route['excluded'] ?? null);
		if (is_array($excluded) === true && $excluded !== []) {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::EXCLUSION_LIST, where: 'The route');
		}

		return array_values(array_filter($refusals));
	}//end ofRoute()

	/**
	 * Refusals that concern one step.
	 *
	 * @param array<string, mixed> $step   The step.
	 * @param int                  $number The step's order, as the secretary numbered it.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function ofStep(array $step, int $number): array {
		$where    = sprintf('Step %d', $number);
		$refusals = [];

		if (trim((string)($step['actorRule'] ?? '')) !== '') {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::PERFORMER_RULE, where: $where);
		} else if (trim((string)($step['actor'] ?? '')) === '') {
			$refusals[] = sprintf('%s names nobody who acts on it; a step that runs as a task needs a person, a body or a role.', $where);
		}

		$onSilence = trim((string)($step['onSilence'] ?? ''));
		if ($onSilence === 'hold') {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::HOLD, where: $where);
		} else if ($onSilence !== '') {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::LAPSE_OUTCOME, where: $where);
		}

		if (is_numeric($step['askSubstituteAfter'] ?? null) === true) {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::REMINDER, where: $where);
		}

		if (in_array((string)($step['thresholdKind'] ?? 'all'), ['count', 'share'], true) === true) {
			$refusals[] = $this->missing(feature: ApprovalRouteFlowCapabilities::PARTIAL_THRESHOLD, where: $where);
		}

		return array_values(array_filter($refusals));
	}//end ofStep()

	/**
	 * The refusal for a feature this OpenRegister lacks, or null when it has it.
	 *
	 * @param string $feature The feature.
	 * @param string $where   The route or the step that uses it.
	 *
	 * @return string|null The refusal.
	 */
	private function missing(string $feature, string $where): ?string {
		if ($this->capabilities->offers(feature: $feature) === true) {
			return null;
		}

		return sprintf('%s uses %s, which the installed OpenRegister does not offer, so the route cannot be saved.', $where, $feature);
	}//end missing()
}//end class
