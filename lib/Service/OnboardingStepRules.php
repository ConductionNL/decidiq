<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules of a member checklist: which step may still be marked, which
 * steps are mandatory, and when no step is left open.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 * @spec     openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\OnboardingStepRefusedException;

/**
 * Pure checks over MemberOnboarding and MemberOffboarding checklists.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
final class OnboardingStepRules {

	/**
	 * The outcomes a step may be given.
	 */
	public const OUTCOMES = ['completed', 'skipped'];

	/**
	 * Lifecycle states after which nothing changes.
	 */
	private const TERMINAL = ['completed', 'lapsed'];

	/**
	 * The step at an index, when it is still open on a record that is not finished.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param int                  $index  The step's position.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OnboardingStepRefusedException When the record is finished, the step is missing or no longer open.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public static function openStep(array $record, int $index): array {
		if (in_array(($record['lifecycle'] ?? 'started'), self::TERMINAL, true) === true) {
			throw new OnboardingStepRefusedException(message: 'This record is finished; its steps no longer change.');
		}

		$step = ($record['steps'][$index] ?? null);
		if (is_array($step) === false) {
			throw new OnboardingStepRefusedException(message: 'This step does not exist.', status: 404);
		}

		if (in_array(($step['status'] ?? 'open'), self::OUTCOMES, true) === true) {
			throw new OnboardingStepRefusedException(message: 'This step is already ' . $step['status'] . '.');
		}

		return $step;
	}//end openStep()

	/**
	 * A step's type, '' when it declares none.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public static function stepTypeOf(array $step): string {
		$type = ($step['stepType'] ?? '');
		if (is_string($type) === false) {
			return '';
		}

		return $type;
	}//end stepTypeOf()

	/**
	 * Whether a step is mandatory: as declared, default yes; revoke-groups always.
	 *
	 * @param string               $kind `onboarding` or `offboarding`.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public static function isMandatory(string $kind, array $step): bool {
		if ($kind === 'offboarding' && ($step['stepType'] ?? '') === 'revoke-groups') {
			return true;
		}

		return (($step['mandatory'] ?? true) !== false);
	}//end isMandatory()

	/**
	 * Whether every step is done or skipped, and every mandatory one done.
	 *
	 * @param string                   $kind  `onboarding` or `offboarding`.
	 * @param array<int|string, mixed> $steps The checklist.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public static function noStepOpen(string $kind, array $steps): bool {
		foreach ($steps as $step) {
			if (self::isSettled(kind: $kind, step: $step) === false) {
				return false;
			}
		}

		return true;
	}//end noStepOpen()

	/**
	 * Whether a step is settled: completed, or skipped when it may be skipped.
	 *
	 * @param string $kind `onboarding` or `offboarding`.
	 * @param mixed  $step The step.
	 *
	 * @return bool
	 */
	private static function isSettled(string $kind, mixed $step): bool {
		if (is_array($step) === false) {
			return false;
		}

		$status = ($step['status'] ?? 'open');
		if (in_array($status, self::OUTCOMES, true) === false) {
			return false;
		}

		return $status !== 'skipped' || self::isMandatory(kind: $kind, step: $step) === false;
	}//end isSettled()
}//end class
