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
 * A committee step names the governance body that decides it (REQ-DWP-010).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeStepBodyRegisterTest extends TestCase {
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
	 * The route step and the running stage both carry `committeeBody` as a body reference.
	 *
	 * @return void
	 */
	public function testTheStepAndTheStageNameTheBody(): void {
		$schemas = $this->schemas();

		$step = $schemas['ApprovalRoute']['properties']['steps']['items']['properties'];
		$stage = $schemas['DecisionStage']['properties'];

		foreach (['route step' => $step, 'stage' => $stage] as $where => $properties) {
			$this->assertArrayHasKey(key: 'committeeBody', array: $properties, message: 'The ' . $where . ' cannot name a committee body.');
			$this->assertSame(expected: 'governance-body', actual: $properties['committeeBody']['$ref']);
			$this->assertSame(expected: 'string', actual: $properties['committeeBody']['type']);
		}

		$this->assertContains(needle: 'committee', haystack: $step['stepKind']['enum']);
		$this->assertContains(needle: 'committee', haystack: $stage['stepKind']['enum']);
	}

	/**
	 * Adding the property leaves the step's existing configuration in place.
	 *
	 * @return void
	 */
	public function testTheExistingStepConfigurationSurvives(): void {
		$step = $this->schemas()['ApprovalRoute']['properties']['steps']['items']['properties'];

		foreach (['thresholdKind', 'thresholdValue', 'approvalBasis', 'stepKind'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $step, message: $field . ' was lost when the committee body was merged in.');
		}
	}
}
