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
 * A stage keeps its step's threshold result and says when the threshold closed it (REQ-DWP-002).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
class StepThresholdResultRegisterTest extends TestCase {
	/**
	 * The DecisionStage properties with every fragment merged, as the app loads them.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function stageProperties(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/decidesk_register.json'), true);
		$this->assertIsArray(actual: $base);

		[$merged] = (new RegisterFragmentMerger())->mergeDirectory(configData: $base, fragmentDir: $settings . '/register.d');

		return $merged['components']['schemas']['DecisionStage']['properties'];
	}

	/**
	 * The cached result carries the outcome and every input it was computed from.
	 *
	 * @return void
	 */
	public function testTheResultCarriesItsInputs(): void {
		$properties = $this->stageProperties();

		$this->assertArrayHasKey(key: 'thresholdResult', array: $properties);
		$this->assertSame(expected: 'object', actual: $properties['thresholdResult']['type']);
		$result = $properties['thresholdResult']['properties'];
		foreach (['outcome', 'granted', 'refused', 'required', 'approvers', 'thresholdKind', 'thresholdValue', 'computedAt'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $result, message: 'thresholdResult does not keep ' . $field . '.');
		}

		$this->assertSame(expected: ['open', 'granted', 'refused'], actual: $result['outcome']['enum']);
	}

	/**
	 * A stage the threshold closed is marked as such, and the stage configuration survives.
	 *
	 * @return void
	 */
	public function testAStageClosedByTheThresholdIsMarked(): void {
		$properties = $this->stageProperties();

		$this->assertSame(expected: 'boolean', actual: $properties['closedByThreshold']['type']);
		$this->assertArrayNotHasKey(key: 'default', array: $properties['closedByThreshold']);
		foreach (['thresholdKind', 'thresholdValue', 'approvalBasis', 'stepKind', 'committeeBody', 'status', 'outcome'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $properties, message: $field . ' was lost when the threshold result was merged in.');
		}

		$this->assertContains(needle: 'skipped', haystack: $properties['status']['enum']);
		$this->assertContains(needle: 'skipped', haystack: $properties['outcome']['enum']);
	}
}
