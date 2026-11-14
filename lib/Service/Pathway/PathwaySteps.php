<?php

/**
 * Reading and completing one step of a member onboarding or offboarding.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Pathway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

/**
 * The steps live in one array on the record and a save replaces the whole
 * array, so completing a step returns the full list with only that step
 * changed: the other steps' status and notes are carried forward untouched.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
 */
final class PathwaySteps {

	/**
	 * The status of a finished step.
	 *
	 * @var string
	 */
	public const COMPLETED = 'completed';

	/**
	 * The steps of a record, as a list of arrays.
	 *
	 * @param array<string,mixed> $record The onboarding or offboarding.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	public static function of(array $record): array {
		$steps = ($record['steps'] ?? []);
		if (is_array($steps) === false) {
			return [];
		}

		$list = [];
		foreach ($steps as $step) {
			if (is_array($step) === true) {
				$list[] = $step;
			}
		}

		return $list;
	}//end of()

	/**
	 * The position of the first step of a type, or null when the record has none.
	 *
	 * @param list<array<string,mixed>> $steps    The steps.
	 * @param string                    $stepType The step type.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	public static function indexOf(array $steps, string $stepType): ?int {
		foreach ($steps as $index => $step) {
			if (($step['stepType'] ?? null) === $stepType) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * Whether the step at a position is already completed.
	 *
	 * @param list<array<string,mixed>> $steps The steps.
	 * @param int                       $index The position.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	public static function isCompleted(array $steps, int $index): bool {
		return (($steps[$index]['status'] ?? null) === self::COMPLETED);
	}//end isCompleted()

	/**
	 * The full list with one step completed and every other step unchanged.
	 *
	 * @param list<array<string,mixed>> $steps       The steps.
	 * @param int                       $index       The position of the step to complete.
	 * @param string                    $completedBy Who completed it.
	 * @param string                    $completedAt When, as an ISO-8601 date-time.
	 * @param string                    $note        What was done.
	 * @param string|null               $reference   What the step produced; null keeps the old value.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	public static function complete(
		array $steps,
		int $index,
		string $completedBy,
		string $completedAt,
		string $note,
		?string $reference=null
	): array {
		$step = $steps[$index];
		$step['status']      = self::COMPLETED;
		$step['completedBy'] = $completedBy;
		$step['completedAt'] = $completedAt;
		$step['note']        = $note;
		if ($reference !== null) {
			$step['reference'] = $reference;
		}

		$steps[$index] = $step;
		return array_values($steps);
	}//end complete()

	/**
	 * The full list with a note on one step and its status unchanged.
	 *
	 * @param list<array<string,mixed>> $steps The steps.
	 * @param int                       $index The position.
	 * @param string                    $note  The note.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	public static function annotate(array $steps, int $index, string $note): array {
		$steps[$index]['note'] = $note;
		return array_values($steps);
	}//end annotate()

	/**
	 * The id of a relation value: a uuid string or an object with an id or uuid.
	 *
	 * @param mixed $value The stored relation.
	 *
	 * @return string Empty when there is none.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-001-onboardingtraject-schema-on-openregister
	 */
	public static function relationId(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid'] as $key) {
				if (is_string($value[$key] ?? null) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end relationId()

}//end class
