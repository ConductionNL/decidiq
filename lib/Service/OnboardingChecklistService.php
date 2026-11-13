<?php

/**
 * Decidiq Onboarding Checklist Service
 *
 * Walks a member onboarding or offboarding through its checklist: marks a
 * step done or skipped, runs the two steps decidiq can run itself (the
 * installation creates the membership, the end step end-dates it), and moves
 * the record through its declared lifecycle until no step is open.
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
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The checklist walk of MemberOnboarding and MemberOffboarding records.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
class OnboardingChecklistService {

	/**
	 * The register.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The record schema per kind.
	 */
	public const SCHEMAS = ['onboarding' => 'member-onboarding', 'offboarding' => 'member-offboarding'];

	/**
	 * The outcomes a step may be given.
	 */
	public const OUTCOMES = ['completed', 'skipped'];

	/**
	 * Lifecycle states after which nothing changes.
	 */
	private const TERMINAL = ['completed', 'lapsed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The record of a kind, as stored.
	 *
	 * @param string $kind `onboarding` or `offboarding`.
	 * @param string $id   The record's uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OnboardingStepRefusedException When the kind is unknown (400) or the record does not exist (404).
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public function record(string $kind, string $id): array {
		$schema = (self::SCHEMAS[$kind] ?? null);
		if ($schema === null) {
			throw new OnboardingStepRefusedException(message: 'Unknown checklist kind.', status: 400);
		}

		$entity = null;
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			$entity = null;
		}

		$data = $entity?->getObject();
		if (is_array($data) === false) {
			throw new OnboardingStepRefusedException(message: 'This record does not exist.', status: 404);
		}

		return $data;
	}//end record()

	/**
	 * The governance body a record belongs to, for the caller's scope check.
	 *
	 * @param string               $kind   `onboarding` or `offboarding`.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The body uuid, or '' when the record names none.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public static function bodyOf(string $kind, array $record): string {
		$body = $record['targetBody'] ?? null;
		if ($kind === 'offboarding') {
			$body = $record['body'] ?? null;
		}

		if (is_string($body) === false) {
			return '';
		}

		return $body;
	}//end bodyOf()

	/**
	 * Mark one step done or skipped, run its membership effect, and move the record on.
	 *
	 * @param string $kind    `onboarding` or `offboarding`.
	 * @param string $id      The record's uuid.
	 * @param int    $index   The step's position in the checklist.
	 * @param string $outcome `completed` or `skipped`.
	 *
	 * @return array<string, mixed> The record as saved.
	 *
	 * @throws OnboardingStepRefusedException When the step cannot be marked so.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public function completeStep(string $kind, string $id, int $index, string $outcome): array {
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			throw new OnboardingStepRefusedException(message: 'A step is marked completed or skipped.', status: 400);
		}

		$record = $this->record(kind: $kind, id: $id);
		$step = $this->openStep(record: $record, index: $index);
		if ($outcome === 'skipped' && self::isMandatory(kind: $kind, step: $step) === true) {
			throw new OnboardingStepRefusedException(message: 'A mandatory step cannot be skipped.');
		}

		if ($outcome === 'completed') {
			$record = $this->runEffect(kind: $kind, record: $record, step: $step);
		}

		$record['steps'][$index]['status'] = $outcome;
		if (($record['lifecycle'] ?? 'started') === 'started') {
			$record['lifecycle'] = 'in-execution';
			$record = $this->save(kind: $kind, id: $id, record: $record);
		}

		if (self::noStepOpen(kind: $kind, steps: $record['steps']) === true) {
			$record['lifecycle'] = 'completed';
		}

		return $this->save(kind: $kind, id: $id, record: $record);
	}//end completeStep()

	/**
	 * The step at an index, when it is still open on a record that is not finished.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param int                  $index  The step's position.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OnboardingStepRefusedException When the record is finished, the step is missing or no longer open.
	 */
	private function openStep(array $record, int $index): array {
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
	 * Run the membership effect of an installation or end step; other steps have none.
	 *
	 * @param string               $kind   `onboarding` or `offboarding`.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $step   The step being completed.
	 *
	 * @return array<string, mixed> The record, with the membership it now refers to.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	private function runEffect(string $kind, array $record, array $step): array {
		$type = ($step['stepType'] ?? '');
		if ($kind === 'onboarding' && $type === 'installation') {
			return $this->createMembership(record: $record);
		}

		if ($kind === 'offboarding' && $type === 'end-membership') {
			$this->endMembership(record: $record);
		}

		return $record;
	}//end runEffect()

	/**
	 * Create the membership an installation starts, once.
	 *
	 * @param array<string, mixed> $record The onboarding.
	 *
	 * @return array<string, mixed> The onboarding, referring to the membership.
	 *
	 * @throws OnboardingStepRefusedException When the installation is not recorded yet.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	private function createMembership(array $record): array {
		foreach (['installedOn', 'installationType', 'installationMeeting'] as $field) {
			if (is_string($record[$field] ?? null) === false || trim($record[$field]) === '') {
				throw new OnboardingStepRefusedException(message: 'Record the installation date, type and meeting before completing the installation.');
			}
		}

		if (is_string($record['membership'] ?? null) === true && $record['membership'] !== '') {
			return $record;
		}

		$saved = $this->objectService->saveObject(
			object: [
				'person' => $record['person'],
				'governanceBody' => $record['targetBody'],
				'role' => $record['targetRole'],
				'startDate' => $record['installedOn'],
			],
			register: self::REGISTER,
			schema: 'membership',
			_rbac: false,
			_multitenancy: false
		);
		$record['membership'] = (string)$saved->getUuid();

		return $record;
	}//end createMembership()

	/**
	 * Set the membership's end date to the offboarding's.
	 *
	 * @param array<string, mixed> $record The offboarding.
	 *
	 * @return void
	 *
	 * @throws OnboardingStepRefusedException When the end date or the membership is missing.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership
	 */
	private function endMembership(array $record): void {
		$endDate = ($record['endDate'] ?? null);
		if (is_string($endDate) === false || $endDate === '') {
			throw new OnboardingStepRefusedException(message: 'Record the end date before ending the membership.');
		}

		$membershipId = (string)($record['membership'] ?? '');
		$membership = null;
		try {
			$membership = $this->objectService->find(id: $membershipId, register: self::REGISTER, schema: 'membership', _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			$membership = null;
		}

		$data = $membership?->getObject();
		if ($membershipId === '' || is_array($data) === false) {
			throw new OnboardingStepRefusedException(message: 'The membership to end does not exist.');
		}

		$data['endDate'] = $endDate;
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: 'membership',
			uuid: $membershipId,
			_rbac: false,
			_multitenancy: false
		);
	}//end endMembership()

	/**
	 * Save the record.
	 *
	 * @param string               $kind   `onboarding` or `offboarding`.
	 * @param string               $id     The record's uuid.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed> The record as saved.
	 */
	private function save(string $kind, string $id, array $record): array {
		$this->objectService->saveObject(
			object: $record,
			register: self::REGISTER,
			schema: self::SCHEMAS[$kind],
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		return $record;
	}//end save()

	/**
	 * Whether a step is mandatory: as declared, default yes; revoke-groups always.
	 *
	 * @param string               $kind `onboarding` or `offboarding`.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return bool
	 */
	private static function isMandatory(string $kind, array $step): bool {
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
	 */
	private static function noStepOpen(string $kind, array $steps): bool {
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				return false;
			}

			$status = ($step['status'] ?? 'open');
			if (in_array($status, self::OUTCOMES, true) === false) {
				return false;
			}

			if ($status === 'skipped' && self::isMandatory(kind: $kind, step: $step) === true) {
				return false;
			}
		}

		return true;
	}//end noStepOpen()
}//end class
