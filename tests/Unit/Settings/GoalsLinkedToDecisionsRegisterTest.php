<?php

/**
 * A decision can name the goal it serves, and goal progress counts the live
 * schemas (goals-linked-to-decisions task 1), asserted on the register as it
 * ships: the base descriptor with every fragment merged in the app's own order.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\SettingsService::shippedRegisterDescriptor
 * @uses   \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
class GoalsLinkedToDecisionsRegisterTest extends TestCase {

	private const RETIRED = ['Toezegging', 'toezegging', 'TermijnagendaItem', 'termijnagenda-item'];

	/**
	 * The shipped schemas, keyed by component name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		return (SettingsService::shippedRegisterDescriptor()['components']['schemas'] ?? []);
	}

	public function testADecisionMayNameAGoalAndStaysValidWithout(): void {
		$decision = self::schemas()['Decision'];

		$this->assertSame(
			['title' => 'Goal', 'type' => 'string', 'format' => 'uuid', '$ref' => 'Goal', 'nullable' => true],
			array_intersect_key($decision['properties']['goal'], array_flip(['title', 'type', 'format', '$ref', 'nullable']))
		);
		$this->assertNotContains('goal', ($decision['required'] ?? []));
	}

	public function testGoalProgressNamesNoRetiredSchema(): void {
		$goal = self::schemas()['Goal'];

		foreach (['x-openregister-aggregations', 'x-openregister-aggregate-refs'] as $block) {
			foreach ($goal[$block] as $name => $aggregation) {
				$this->assertNotContains($aggregation['schema'], self::RETIRED, $block . '.' . $name);
			}
		}
	}

	public function testCommitmentsCountOnTheGovernanceCommitmentSchema(): void {
		$goal = self::schemas()['Goal'];

		foreach (['linkedCommitmentCount', 'settledCommitmentCount'] as $name) {
			$this->assertSame('Commitment', $goal['x-openregister-aggregations'][$name]['schema']);
			$this->assertSame('governance-commitment', $goal['x-openregister-aggregate-refs'][$name]['schema']);
		}

		$this->assertSame(['goal' => '@self.id', 'lifecycle' => 'disposed'], $goal['x-openregister-aggregate-refs']['settledCommitmentCount']['filters']);
	}

	public function testPlannedAgendaItemsAndDecisionsAreCountedInBothBlocks(): void {
		$goal = self::schemas()['Goal'];
		$expected = ['linkedPlannedAgendaItemCount' => ['PlannedAgendaItem', 'planned-agenda-item'], 'linkedDecisionCount' => ['Decision', 'decision']];

		foreach ($expected as $name => [$component, $slug]) {
			$this->assertSame(['metric' => 'count', 'schema' => $component, 'filter' => ['goal' => '@self.id']], $goal['x-openregister-aggregations'][$name]);
			$this->assertSame(['metric' => 'count', 'schema' => $slug, 'filters' => ['goal' => '@self.id']], $goal['x-openregister-aggregate-refs'][$name]);
		}
	}

	public function testEveryCountedSchemaExistsAndCarriesTheFilteredProperties(): void {
		$schemas = self::schemas();
		$bySlug = [];
		foreach ($schemas as $schema) {
			$bySlug[(string)($schema['slug'] ?? '')] = $schema;
		}

		$goal = $schemas['Goal'];
		foreach ($goal['x-openregister-aggregations'] as $name => $aggregation) {
			$this->assertArrayHasKey($aggregation['schema'], $schemas, $name);
			foreach (array_keys($aggregation['filter']) as $property) {
				$this->assertArrayHasKey($property, $schemas[$aggregation['schema']]['properties'], $name . ' filters on ' . $property);
			}
		}

		foreach ($goal['x-openregister-aggregate-refs'] as $name => $aggregation) {
			$this->assertArrayHasKey($aggregation['schema'], $bySlug, $name);
		}
	}
}
