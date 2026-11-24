<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\StepThresholdSettler;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Settling a step at its threshold (REQ-DWP-002).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
class StepThresholdSettlerTest extends TestCase {
	/**
	 * The patches written, as [uuid => data].
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The settler over a store that records its patches.
	 *
	 * @return StepThresholdSettler The settler.
	 */
	private function settler(): StepThresholdSettler {
		$store = $this->createMock(originalClassName: RegisterObjectStore::class);
		$store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid): array {
				$this->assertSame(expected: 'decision-stage', actual: $schema);
				$this->patches[$uuid] = $data;

				return $data + ['id' => $uuid];
			}
		);

		return new StepThresholdSettler(store: $store);
	}

	/**
	 * One stage of a step.
	 *
	 * @param string $id      The stage id.
	 * @param string $status  Its status.
	 * @param string $outcome Its outcome.
	 * @param string $route   Its route.
	 *
	 * @return array<string, mixed> The stage.
	 */
	private static function stage(string $id, string $status, string $outcome = '', string $route = 'r1'): array {
		$stage = ['id' => $id, 'route' => $route, 'sequence' => 1, 'status' => $status, 'thresholdKind' => 'count', 'thresholdValue' => 2];
		if ($outcome !== '') {
			$stage['outcome'] = $outcome;
		}

		return $stage;
	}

	/**
	 * Only `count` and `share` close at a threshold.
	 *
	 * @return void
	 */
	public function testOnlyCountAndShareDeclareAThreshold(): void {
		$this->assertTrue(condition: StepThresholdSettler::declares(stage: ['thresholdKind' => 'count']));
		$this->assertTrue(condition: StepThresholdSettler::declares(stage: ['thresholdKind' => 'share']));
		$this->assertFalse(condition: StepThresholdSettler::declares(stage: ['thresholdKind' => 'all']));
		$this->assertFalse(condition: StepThresholdSettler::declares(stage: []));
	}

	/**
	 * A step without a threshold writes nothing.
	 *
	 * @return void
	 */
	public function testAStepWithoutAThresholdWritesNothing(): void {
		$active = ['id' => 's1', 'route' => 'r1', 'sequence' => 1, 'status' => 'decided', 'outcome' => 'approved'];

		$this->assertNull(actual: $this->settler()->settle(stages: [$active], active: $active));
		$this->assertSame(expected: [], actual: $this->patches);
	}

	/**
	 * The group is the stages of the same route and sequence, nothing else.
	 *
	 * @return void
	 */
	public function testTheGroupIsTheSameRouteAndSequence(): void {
		$stages = [
			self::stage(id: 's1', status: 'active'),
			self::stage(id: 's2', status: 'active'),
			self::stage(id: 'other', status: 'active', route: 'r2'),
			['id' => 'later', 'route' => 'r1', 'sequence' => 2, 'status' => 'pending'],
		];

		$group = $this->settler()->groupOf(stages: $stages, stage: $stages[0]);

		$this->assertSame(expected: ['s1', 's2'], actual: array_column($group, 'id'));
	}

	/**
	 * A returned stage counts as a refusal: two of three refusing a count of two leaves it refused.
	 *
	 * @return void
	 */
	public function testAReturnedStageCountsAsARefusal(): void {
		$group = [
			self::stage(id: 's1', status: 'decided', outcome: 'returned'),
			self::stage(id: 's2', status: 'decided', outcome: 'returned'),
			self::stage(id: 's3', status: 'active'),
		];

		$result = $this->settler()->resultOf(stage: $group[0], group: $group);

		$this->assertSame(expected: 'refused', actual: $result['outcome']);
		$this->assertSame(expected: 2, actual: $result['refused']);
		$this->assertSame(expected: 0, actual: $result['granted']);
	}

	/**
	 * Reaching the count closes the waiting siblings and caches the result on every stage, the stage of another route untouched.
	 *
	 * @return void
	 */
	public function testReachingTheCountClosesTheWaitingSiblings(): void {
		$stages = [
			self::stage(id: 's1', status: 'decided', outcome: 'approved'),
			self::stage(id: 's2', status: 'active'),
			self::stage(id: 's3', status: 'active'),
			self::stage(id: 'other', status: 'active', route: 'r2'),
		];
		$active = (['status' => 'decided', 'outcome' => 'approved'] + $stages[1]);

		$result = $this->settler()->settle(stages: $stages, active: $active);

		$this->assertSame(expected: 'granted', actual: $result['outcome']);
		$this->assertSame(expected: ['s1', 's2', 's3'], actual: array_keys($this->patches));
		$this->assertSame(expected: 'skipped', actual: $this->patches['s3']['status']);
		$this->assertTrue(condition: $this->patches['s3']['closedByThreshold']);
		$this->assertArrayNotHasKey(key: 'status', array: $this->patches['s1']);
		$this->assertArrayNotHasKey(key: 'status', array: $this->patches['s2']);
		$this->assertSame(expected: $result, actual: $this->patches['s1']['thresholdResult']);
	}
}
