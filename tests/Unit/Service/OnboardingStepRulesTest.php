<?php

/**
 * Which checklist steps may still change, and the status each refusal answers with.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\Decidiq\Service\OnboardingStepRules;
use PHPUnit\Framework\TestCase;

/**
 * An open step on a running record is handed back; a finished record, a
 * missing step and a step that already has an outcome are refused, with
 * 404 for the missing step and 409 otherwise.
 *
 * @covers \OCA\Decidiq\Service\OnboardingStepRules
 * @covers \OCA\Decidiq\Exception\OnboardingStepRefusedException
 */
final class OnboardingStepRulesTest extends TestCase {

	/**
	 * A started record with one open and one completed step.
	 *
	 * @param string $lifecycle The record's lifecycle.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $lifecycle='started'): array {
		return [
			'lifecycle' => $lifecycle,
			'steps' => [
				['title' => 'Oath or affirmation', 'type' => 'installation', 'status' => 'open'],
				['title' => 'Integrity briefing', 'status' => 'completed'],
			],
		];
	}//end record()

	/**
	 * The refusal openStep() throws.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param int                  $index  The step's position.
	 *
	 * @return OnboardingStepRefusedException
	 */
	private function refusal(array $record, int $index): OnboardingStepRefusedException {
		try {
			OnboardingStepRules::openStep(record: $record, index: $index);
		} catch (OnboardingStepRefusedException $e) {
			return $e;
		}

		self::fail('openStep() did not refuse');
	}//end refusal()

	/**
	 * An open step of a running record is returned as it is.
	 *
	 * @return void
	 */
	public function testAnOpenStepIsReturned(): void {
		self::assertSame(
			['title' => 'Oath or affirmation', 'type' => 'installation', 'status' => 'open'],
			OnboardingStepRules::openStep(record: $this->record(), index: 0)
		);
		self::assertSame('Integrity briefing', OnboardingStepRules::openStep(record: ['steps' => [['title' => 'Integrity briefing']]], index: 0)['title']);
	}//end testAnOpenStepIsReturned()

	/**
	 * A completed or lapsed record refuses every step with 409.
	 *
	 * @return void
	 */
	public function testAFinishedRecordIsRefusedWithConflict(): void {
		foreach (['completed', 'lapsed'] as $lifecycle) {
			$refusal = $this->refusal(record: $this->record(lifecycle: $lifecycle), index: 0);
			self::assertSame(409, $refusal->getStatus(), $lifecycle);
			self::assertSame('This record is finished; its steps no longer change.', $refusal->getMessage());
		}
	}//end testAFinishedRecordIsRefusedWithConflict()

	/**
	 * A step that does not exist is refused with 404.
	 *
	 * @return void
	 */
	public function testAMissingStepIsRefusedWithNotFound(): void {
		$refusal = $this->refusal(record: $this->record(), index: 7);

		self::assertSame(404, $refusal->getStatus());
		self::assertSame('This step does not exist.', $refusal->getMessage());
	}//end testAMissingStepIsRefusedWithNotFound()

	/**
	 * A step that already has an outcome is refused with 409, naming it.
	 *
	 * @return void
	 */
	public function testAStepWithAnOutcomeIsRefusedWithConflict(): void {
		$refusal = $this->refusal(record: $this->record(), index: 1);

		self::assertSame(409, $refusal->getStatus());
		self::assertSame('This step is already completed.', $refusal->getMessage());
	}//end testAStepWithAnOutcomeIsRefusedWithConflict()
}//end class
