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

use PHPUnit\Framework\TestCase;

/**
 * REQ-OAC-008: an objection runs as a decision of the `bezwaar-decision` type
 * through the route that type configures, and the decision term is
 * configuration of the type. Nothing here is code named after objections: the
 * municipality example set configures it, and another organisation can
 * configure a route without a hearing.
 */
class ObjectionRouteConfigurationTest extends TestCase {
	/**
	 * A decoded JSON file under lib/Settings.
	 *
	 * @param string $path The path below lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $path), true);
		$this->assertIsArray($decoded, $path . ' did not decode.');

		return $decoded;
	}

	/**
	 * The municipality example set's seed objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seeds(string $schema): array {
		return $this->json('profiles/municipality.json')['x-openregister']['seedData']['objects'][$schema];
	}

	/**
	 * One seed by slug.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The seed slug.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(string $schema, string $slug): array {
		foreach ($this->seeds($schema) as $object) {
			if (($object['slug'] ?? null) === $slug) {
				return $object;
			}
		}

		$this->fail('No ' . $schema . ' seed ' . $slug);
	}

	/**
	 * The step item properties of ApprovalRoute, every register.d patch merged.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stepProperties(): array {
		$merged = [];
		foreach (glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$schema = (json_decode((string)file_get_contents($file), true)['components']['schemas']['ApprovalRoute'] ?? []);
			$merged = array_merge($merged, ($schema['properties']['steps']['items']['properties'] ?? []));
		}

		return $merged;
	}

	/**
	 * The decision type declares its term as an ISO 8601 period and what it counts from.
	 *
	 * @return void
	 */
	public function testTheDecisionTypeDeclaresItsTerm(): void {
		$properties = $this->json('register.d/132-decision-type-term-period.json')['components']['schemas']['DecisionTemplate']['properties'];
		$pattern = '/' . $properties['termPeriod']['pattern'] . '/';

		$this->assertSame(1, preg_match($pattern, 'P12W'));
		$this->assertSame(1, preg_match($pattern, 'P6W'));
		$this->assertSame(1, preg_match($pattern, 'P1Y2M'));
		$this->assertSame(0, preg_match($pattern, 'P'));
		$this->assertSame(0, preg_match($pattern, '12 weeks'));
		$this->assertSame(['received', 'objection-period-end'], $properties['termStartsAt']['enum']);
		$this->assertSame('received', $properties['termStartsAt']['default']);
	}

	/**
	 * The objection type carries a twelve-week term from the end of the objection period.
	 *
	 * @return void
	 */
	public function testTheObjectionTypeCarriesItsTerm(): void {
		$type = $this->seed('decision-template', 'bezwaar-decision');

		$this->assertSame('bezwaar-decision', $type['decisionType']);
		$this->assertSame('P12W', $type['termPeriod']);
		$this->assertSame('objection-period-end', $type['termStartsAt']);
		$this->assertContains($type['context'], ['association', 'corporate', 'legislative', 'operations', 'citizen']);
	}

	/**
	 * The objection route is the type's default route and walks the five steps in order.
	 *
	 * @return void
	 */
	public function testTheObjectionRouteWalksFiveSteps(): void {
		$route = $this->seed('approval-route', 'bezwaar-standaard');
		$steps = $route['steps'];

		$this->assertSame('bezwaar-decision', $route['subjectType']);
		$this->assertTrue($route['isDefault']);
		$this->assertSame([1, 2, 3, 4, 5], array_column($steps, 'order'));
		$this->assertSame(
			['Ontvangst en ontvankelijkheid', 'Hoorzitting', 'Advies bezwaaradviescommissie', 'Beslissing op bezwaar', 'Verzending'],
			array_column($steps, 'label')
		);
		$this->assertSame('intake', $steps[0]['stepKind'], 'receipt yields the admissibility verdict');
		$this->assertSame('decisive', $steps[3]['stageType']);
	}

	/**
	 * The advice step asks the seeded independent objection advisory committee.
	 *
	 * @return void
	 */
	public function testTheAdviceIsAskedOfTheAdvisoryCommittee(): void {
		$advice = $this->seed('approval-route', 'bezwaar-standaard')['steps'][2];
		$committee = $this->seed('governance-body', $advice['actor']);

		$this->assertSame('advisory', $advice['stageType']);
		$this->assertSame('body', $advice['actorType']);
		$this->assertSame('advisory-body', $committee['bodyType']);
		$this->assertStringContainsString('7:13', $committee['statutoryBasis']);
		$this->assertGreaterThanOrEqual(2, $committee['quorum']);
	}

	/**
	 * Every step value is one the route schema accepts.
	 *
	 * @return void
	 */
	public function testEveryStepValueIsDeclared(): void {
		$properties = $this->stepProperties();
		foreach ($this->seed('approval-route', 'bezwaar-standaard')['steps'] as $step) {
			foreach (['stageType', 'actorType', 'stepKind'] as $field) {
				if (isset($step[$field]) === true) {
					$this->assertContains($step[$field], $properties[$field]['enum'], $field . ' of step ' . $step['order']);
				}
			}
		}
	}
}
