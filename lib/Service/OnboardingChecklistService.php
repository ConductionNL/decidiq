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
	 * The record schema per kind.
	 */
	public const SCHEMAS = ['onboarding' => 'member-onboarding', 'offboarding' => 'member-offboarding'];

	/**
	 * The outcomes a step may be given.
	 */
	public const OUTCOMES = OnboardingStepRules::OUTCOMES;

	/**
	 * Reads and writes the register and runs the steps' membership effects.
	 *
	 * @var OnboardingStepEffects
	 */
	private readonly OnboardingStepEffects $effects;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
	) {
		$this->effects = new OnboardingStepEffects(objectService: $objectService);
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

		$data = $this->effects->findData(id: $id, schema: $schema);
		if ($data === null) {
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
		$step = OnboardingStepRules::openStep(record: $record, index: $index);
		if ($outcome === 'skipped' && OnboardingStepRules::isMandatory(kind: $kind, step: $step) === true) {
			throw new OnboardingStepRefusedException(message: 'A mandatory step cannot be skipped.');
		}

		if ($outcome === 'completed') {
			$record = $this->effects->apply(record: $record, effect: $kind . ':' . OnboardingStepRules::stepTypeOf(step: $step));
		}

		$record['steps'][$index]['status'] = $outcome;

		return $this->advance(kind: $kind, id: $id, record: $record);
	}//end completeStep()

	/**
	 * Store a record whose step just settled: a started record goes into
	 * execution first, and a record with no step left open is completed.
	 *
	 * @param string               $kind   `onboarding` or `offboarding`.
	 * @param string               $id     The record's id.
	 * @param array<string, mixed> $record The record with the settled step.
	 *
	 * @return array<string, mixed> The stored record.
	 */
	private function advance(string $kind, string $id, array $record): array {
		if (($record['lifecycle'] ?? 'started') === 'started') {
			$record['lifecycle'] = 'in-execution';
			$this->effects->persist(object: $record, schema: self::SCHEMAS[$kind], uuid: $id);
		}

		if (OnboardingStepRules::noStepOpen(kind: $kind, steps: $record['steps']) === true) {
			$record['lifecycle'] = 'completed';
		}

		$this->effects->persist(object: $record, schema: self::SCHEMAS[$kind], uuid: $id);

		return $record;
	}//end advance()
}//end class
