<?php

/**
 * Decidiq intake verdict recorder.
 *
 * Records an admissibility verdict on a running intake stage. The verdict is
 * validated by AdmissibilityVerdictService before anything is written, so a
 * `niet-ontvankelijk` verdict without a ground, or a verb that is no verdict,
 * leaves no trace. An `ontvankelijk` verdict completes the stage and the route
 * walks on; a `niet-ontvankelijk` verdict closes the route with outcome
 * `ended-at-intake`, and no later step becomes live. When the subject is a
 * decision, the verdict, its ground and who gave it are written onto it, where
 * the consuming case app reads it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Applies an admissibility verdict to the route it was given on.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */
final class IntakeVerdictRecorder {
	/**
	 * The schema slug of a decision subject.
	 *
	 * @var string
	 */
	private const DECISION_SCHEMA = 'decision';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore         $store    Writes the verdict onto a decision.
	 * @param ApprovalRouteAdvancer       $advancer Completes or closes the route.
	 * @param AdmissibilityVerdictService $verdicts Validates the verdict.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalRouteAdvancer $advancer,
		private readonly AdmissibilityVerdictService $verdicts = new AdmissibilityVerdictService(),
	) {
	}//end __construct()

	/**
	 * Whether a stage is an intake step, whose act is a verdict rather than a sign-off.
	 *
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return bool True for an intake stage.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public static function isIntakeStage(array $stage): bool {
		return (string)($stage['stepKind'] ?? '') === AdmissibilityVerdictService::STEP_KIND_INTAKE;
	}//end isIntakeStage()

	/**
	 * Record the verdict an action carries on the intake stage and move the route.
	 *
	 * @param array<string, mixed>             $action The action: the verdict as its verb, the actor, and a `ground` (or `comment`).
	 * @param array<int, array<string, mixed>> $stages All stages of the subject, in order.
	 * @param array<string, mixed>             $active The intake stage.
	 *
	 * @return array{verdict: string, ground: string, decidedBy: string,
	 *         decidedAt: string, routeOutcome: ?string, advances: bool} The verdict.
	 *
	 * @throws RuntimeException When the verdict is refused; nothing is written then.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function conclude(array $action, array $stages, array $active): array {
		$verdict = $this->verdictOf(action: $action, active: $active);

		if ($verdict['advances'] === true) {
			$this->advancer->completeWithOutcome(stages: $stages, active: $active, outcome: $verdict['verdict']);
		} else {
			$this->advancer->concludeWithOutcome(stages: $stages, active: $active, outcome: (string)$verdict['routeOutcome']);
		}

		$this->writeOntoDecision(active: $active, verdict: $verdict);

		return $verdict;
	}//end conclude()

	/**
	 * The validated verdict of an action.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $active The intake stage.
	 *
	 * @return array{verdict: string, ground: string, decidedBy: string,
	 *         decidedAt: string, routeOutcome: ?string, advances: bool} The verdict.
	 *
	 * @throws RuntimeException When the verdict is unknown or a refusal names no ground.
	 */
	private function verdictOf(array $action, array $active): array {
		$ground = (string)($action['ground'] ?? '');
		if (trim($ground) === '') {
			$ground = (string)($action['comment'] ?? '');
		}

		try {
			return $this->verdicts->record(
				step: $active,
				verdict: new IntakeVerdict(
					verdict: (string)($action['action'] ?? ''),
					ground: $ground,
					decidedBy: (string)($action['actor'] ?? ''),
				),
			);
		} catch (InvalidArgumentException $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
	}//end verdictOf()

	/**
	 * Write the verdict onto the decision the route travels, when it travels one.
	 *
	 * @param array<string, mixed> $active  The intake stage.
	 * @param array<string, mixed> $verdict The recorded verdict.
	 *
	 * @return void
	 */
	private function writeOntoDecision(array $active, array $verdict): void {
		$decision = (string)($active['decision'] ?? '');
		if ($decision === '' || (string)($active['note'] ?? '') !== self::DECISION_SCHEMA) {
			return;
		}

		$this->store->patch(
			schema: self::DECISION_SCHEMA,
			data: [
				'ontvankelijkheid' => $verdict['verdict'],
				'ontvankelijkheidGround' => $verdict['ground'],
				'ontvankelijkheidDecidedBy' => $verdict['decidedBy'],
			],
			uuid: $decision,
		);
	}//end writeOntoDecision()
}//end class
