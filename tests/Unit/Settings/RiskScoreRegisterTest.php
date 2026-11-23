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

use OCA\Decidiq\Service\RiskScoreEvaluator;
use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * A decision type declares a risk expression; a decision carries its assessment (REQ-DWP-008, task 8.1).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
class RiskScoreRegisterTest extends TestCase {
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
	 * The type's expression declares rules over the evaluator's operators, and bands.
	 *
	 * @return void
	 */
	public function testTheTypeDeclaresTheExpression(): void {
		$risk = $this->schemas()['DecisionTemplate']['properties']['riskScore'];
		$rule = $risk['properties']['rules']['items'];

		$this->assertSame(expected: 'object', actual: $risk['type']);
		$this->assertSame(expected: ['property', 'operator', 'points'], actual: $rule['required']);
		$this->assertSame(expected: RiskScoreEvaluator::OPERATORS, actual: $rule['properties']['operator']['enum']);
		$this->assertSame(expected: ['band', 'min'], actual: $risk['properties']['bands']['items']['required']);
	}

	/**
	 * The decision carries the score, the band and the properties it came from.
	 *
	 * @return void
	 */
	public function testTheDecisionCarriesTheAssessment(): void {
		$assessment = $this->schemas()['Decision']['properties']['riskAssessment']['properties'];

		$this->assertSame(expected: 'number', actual: $assessment['score']['type']);
		$this->assertSame(expected: 'string', actual: $assessment['band']['type']);
		$this->assertSame(
			expected: ['property', 'value', 'points'],
			actual: array_keys($assessment['basis']['items']['properties'])
		);
	}

	/**
	 * Adding the expression leaves the type's remedies, and the decision's own fields, in place.
	 *
	 * @return void
	 */
	public function testTheExistingFieldsSurvive(): void {
		$schemas = $this->schemas();

		$this->assertArrayHasKey(key: 'legalRemedies', array: $schemas['DecisionTemplate']['properties']);
		foreach (['legalRemedyClause', 'withdrawn', 'title'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $schemas['Decision']['properties'], message: $field . ' was lost when the assessment was merged in.');
		}
	}
}
