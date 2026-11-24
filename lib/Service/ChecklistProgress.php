<?php

/**
 * Reads where a record with a step checklist stands.
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
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * A checklist record (an onboarding or an offboarding) carries `steps`, each
 * with a title, a status, whether it is mandatory and an optional due date,
 * and a `lifecycle` whose `completed` and `lapsed` states are final.
 *
 * Pure: reads the record it is given, writes nothing.
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
 */
class ChecklistProgress {

	/**
	 * Lifecycle states after which the record does not change state again.
	 *
	 * @var list<string>
	 */
	public const FINAL_STATES = ['completed', 'lapsed'];

	/**
	 * Step types that are mandatory whatever the step says: access is always taken away.
	 *
	 * @var list<string>
	 */
	private const ALWAYS_MANDATORY = ['revoke-groups'];

	/**
	 * Step statuses that still need work.
	 *
	 * @var list<string>
	 */
	private const OPEN = ['open', 'in-execution'];

	/**
	 * Why a save is refused, or null when it may go ahead.
	 *
	 * A record may not reach `completed` while a mandatory step is not
	 * completed, and may not leave `completed` or `lapsed` once there.
	 *
	 * @param array<string, mixed>      $record The record as it is being saved.
	 * @param array<string, mixed>|null $stored The record before this save, null on create.
	 *
	 * @return string|null The refusal, naming the open mandatory steps.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
	 */
	public function refusal(array $record, ?array $stored): ?string {
		$before = (string)($stored['lifecycle'] ?? '');
		$after  = (string)($record['lifecycle'] ?? '');
		if (in_array($before, self::FINAL_STATES, true) === true && $after !== $before) {
			return sprintf('This record is %s and its state no longer changes.', $before);
		}

		if ($after !== 'completed') {
			return null;
		}

		$open = $this->openMandatory(record: $record);
		if ($open === []) {
			return null;
		}

		return 'This record cannot be completed while mandatory steps are open: '.implode(', ', $open).'.';
	}//end refusal()

	/**
	 * The titles of the mandatory steps that are not completed.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-003-declarative-traject-lifecycle
	 */
	public function openMandatory(array $record): array {
		$open = [];
		foreach ($this->steps(record: $record) as $step) {
			if ($this->isMandatory(step: $step) === true && ($step['status'] ?? 'open') !== 'completed') {
				$open[] = (string)($step['title'] ?? ($step['stepType'] ?? 'step'));
			}
		}

		return $open;
	}//end openMandatory()

	/**
	 * The open step that is due first, as the fields the reminders read.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array{nextStepDueDate: string|null, nextStepTitle: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-010-declarative-step-reminders
	 */
	public function nextDue(array $record): array {
		$next = ['nextStepDueDate' => null, 'nextStepTitle' => null];
		foreach ($this->steps(record: $record) as $step) {
			$due = trim((string)($step['dueDate'] ?? ''));
			if ($due === '' || in_array(($step['status'] ?? 'open'), self::OPEN, true) === false) {
				continue;
			}

			if ($next['nextStepDueDate'] === null || strcmp($due, $next['nextStepDueDate']) < 0) {
				$next = ['nextStepDueDate' => $due, 'nextStepTitle' => (string)($step['title'] ?? '')];
			}
		}

		return $next;
	}//end nextDue()

	/**
	 * Whether a step must be completed before the record is.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return bool
	 */
	private function isMandatory(array $step): bool {
		return in_array(($step['stepType'] ?? null), self::ALWAYS_MANDATORY, true) === true
			|| ($step['mandatory'] ?? true) !== false;
	}//end isMandatory()

	/**
	 * The record's steps that are arrays.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function steps(array $record): array {
		return array_values(array_filter((array)($record['steps'] ?? []), 'is_array'));
	}//end steps()
}//end class
