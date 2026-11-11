<?php

/**
 * Decidiq Planning Cycle Generator
 *
 * Turns a planning cycle template into the steps of one year
 * (planning-cycle-generate-from-template, pla-12): one PlanningCycleStep per
 * template step, in template order, with every MM-DD default resolved to a
 * date in the cycle year and the subject year offset applied. Pure: it
 * writes nothing; PlanningCycleCreatedListener saves what it returns.
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
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Builds the step payloads of a planning cycle from its template.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
class PlanningCycleGenerator {

	/**
	 * Template defaults (MM-DD) and the step date field each resolves into.
	 *
	 * @var array<int, string>
	 */
	private const DATE_FIELDS = [
		'deliveryDeadline',
		'technicalQuestionsStart',
		'technicalQuestionsEnd',
		'committeeDate',
		'handlingDate',
	];

	/**
	 * The step payloads for a cycle, in template order.
	 *
	 * @param string               $cycleId  The cycle's UUID
	 * @param int                  $year     The cycle year
	 * @param array<string, mixed> $template The PlanningCycleTemplate
	 *
	 * @return array<int, array<string, mixed>> One planning-cycle-step payload per template step
	 *
	 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
	 */
	public function stepsFor(string $cycleId, int $year, array $template): array {
		$steps = [];
		foreach (array_values((array)($template['steps'] ?? [])) as $index => $templateStep) {
			$label = self::labelOf(templateStep: $templateStep);
			if ($label === '') {
				continue;
			}

			$step = [
				'cycle' => $cycleId,
				'sequence' => ($index + 1),
				'label' => $label,
				'status' => 'planned',
				'concernsYear' => ($year + (int)($templateStep['subjectYearOffset'] ?? 0)),
			];
			$steps[] = $step + $this->detailsOf(templateStep: $templateStep, year: $year);
		}

		return $steps;
	}//end stepsFor()

	/**
	 * A template step's label, its step type when it has none; empty for an
	 * entry that is not a step or names neither.
	 *
	 * @param mixed $templateStep One template entry
	 *
	 * @return string The label, or '' to skip the entry
	 */
	private static function labelOf(mixed $templateStep): string {
		if (is_array($templateStep) === false) {
			return '';
		}

		$label = trim((string)($templateStep['label'] ?? ''));
		if ($label === '') {
			return trim((string)($templateStep['stepType'] ?? ''));
		}

		return $label;
	}//end labelOf()

	/**
	 * What a step carries beyond its place in the cycle: its type, its dates
	 * and its document slots, each only when the template gives one.
	 *
	 * @param array<string, mixed> $templateStep The template step
	 * @param int                  $year         The cycle year
	 *
	 * @return array<string, mixed> The extra fields, in payload order
	 */
	private function detailsOf(array $templateStep, int $year): array {
		return self::stepTypeOf(templateStep: $templateStep)
			+ $this->datesOf(templateStep: $templateStep, year: $year)
			+ $this->slotsOf(templateStep: $templateStep);
	}//end detailsOf()

	/**
	 * The step type a step carries, when the template names one.
	 *
	 * @param array<string, mixed> $templateStep The template step
	 *
	 * @return array<string, string> ['stepType' => …], or nothing
	 */
	private static function stepTypeOf(array $templateStep): array {
		$stepType = trim((string)($templateStep['stepType'] ?? ''));
		if ($stepType === '') {
			return [];
		}

		return ['stepType' => $stepType];
	}//end stepTypeOf()

	/**
	 * The template's MM-DD defaults resolved in the cycle year, in field
	 * order; a missing, unreadable or impossible date is left out.
	 *
	 * @param array<string, mixed> $templateStep The template step
	 * @param int                  $year         The cycle year
	 *
	 * @return array<string, string> Date field => YYYY-MM-DD
	 */
	private function datesOf(array $templateStep, int $year): array {
		$dates = [];
		foreach (self::DATE_FIELDS as $field) {
			$date = $this->resolveDate(monthDay: $templateStep[$field] ?? null, year: $year);
			if ($date !== null) {
				$dates[$field] = $date;
			}
		}

		return $dates;
	}//end datesOf()

	/**
	 * The step's document slots, when it declares any.
	 *
	 * @param array<string, mixed> $templateStep The template step
	 *
	 * @return array<string, array<int, array{name: string, required: bool}>> ['documentSlots' => …], or nothing
	 */
	private function slotsOf(array $templateStep): array {
		$slots = $this->documentSlots(slots: $templateStep['documentSlots'] ?? null);
		if ($slots === []) {
			return [];
		}

		return ['documentSlots' => $slots];
	}//end slotsOf()

	/**
	 * A MM-DD default as a date in the year, or null when absent or not a day.
	 *
	 * @param mixed $monthDay The template default (MM-DD)
	 * @param int   $year     The cycle year
	 *
	 * @return string|null The date (YYYY-MM-DD)
	 *
	 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
	 */
	public function resolveDate(mixed $monthDay, int $year): ?string {
		if (is_string($monthDay) === false || preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($monthDay), $match) !== 1) {
			return null;
		}

		$month = (int)$match[1];
		$day = (int)$match[2];
		if (checkdate($month, $day, $year) === false) {
			return null;
		}

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}//end resolveDate()

	/**
	 * The document slots to copy, each a name with its required flag.
	 *
	 * @param mixed $slots The template step's slots
	 *
	 * @return array<int, array{name: string, required: bool}>
	 */
	private function documentSlots(mixed $slots): array {
		$out = [];
		foreach ((array)$slots as $slot) {
			if (is_array($slot) === false || trim((string)($slot['name'] ?? '')) === '') {
				continue;
			}

			$out[] = ['name' => trim((string)$slot['name']), 'required' => (bool)($slot['required'] ?? false)];
		}

		return $out;
	}//end documentSlots()
}//end class
