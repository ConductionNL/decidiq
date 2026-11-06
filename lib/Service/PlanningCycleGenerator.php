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
			if (is_array($templateStep) === false) {
				continue;
			}

			$stepType = trim((string)($templateStep['stepType'] ?? ''));
			$label = trim((string)($templateStep['label'] ?? ''));
			if ($label === '') {
				$label = $stepType;
			}

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
			if ($stepType !== '') {
				$step['stepType'] = $stepType;
			}

			foreach (self::DATE_FIELDS as $field) {
				$date = $this->resolveDate(monthDay: $templateStep[$field] ?? null, year: $year);
				if ($date !== null) {
					$step[$field] = $date;
				}
			}

			$slots = $this->documentSlots(slots: $templateStep['documentSlots'] ?? null);
			if ($slots !== []) {
				$step['documentSlots'] = $slots;
			}

			$steps[] = $step;
		}//end foreach

		return $steps;
	}//end stepsFor()

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
