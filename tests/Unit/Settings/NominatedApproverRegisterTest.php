<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * A route step may take its approver from the requester, inside a group (REQ-DWP-009).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
 */
class NominatedApproverRegisterTest extends TestCase {
	/**
	 * The register with every fragment merged, as the app loads it.
	 *
	 * @return array<string, mixed> The schemas.
	 */
	private function schemas(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/decidesk_register.json'), true);
		$this->assertIsArray(actual: $base);

		[$merged] = (new RegisterFragmentMerger())->mergeDirectory(configData: $base, fragmentDir: $settings . '/register.d');

		return $merged['components']['schemas'];
	}

	/**
	 * The route step declares the bound; the stage carries the bound and who nominated.
	 *
	 * @return void
	 */
	public function testTheStepAndTheStageCarryTheNomination(): void {
		$schemas = $this->schemas();

		$step = $schemas['ApprovalRoute']['properties']['steps']['items']['properties'];
		$stage = $schemas['DecisionStage']['properties'];

		$this->assertSame(expected: 'string', actual: $step['nominationGroup']['type']);
		$this->assertSame(expected: 'string', actual: $stage['nominationGroup']['type']);
		$this->assertSame(expected: 'string', actual: $stage['nominatedBy']['type']);
	}

	/**
	 * Adding the property leaves the step's existing configuration in place.
	 *
	 * @return void
	 */
	public function testTheExistingStepConfigurationSurvives(): void {
		$step = $this->schemas()['ApprovalRoute']['properties']['steps']['items']['properties'];

		foreach (['thresholdKind', 'stepKind', 'committeeBody'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $step, message: $field . ' was lost when the nomination was merged in.');
		}
	}
}
