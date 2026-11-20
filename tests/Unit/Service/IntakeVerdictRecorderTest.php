<?php

/**
 * Unit tests for IntakeVerdictRecorder.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AdmissibilityVerdictService;
use OCA\Decidiq\Service\ApprovalRouteAdvancer;
use OCA\Decidiq\Service\IntakeVerdict;
use OCA\Decidiq\Service\IntakeVerdictRecorder;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers how an admissibility verdict moves the route and reaches the decision.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */
#[CoversClass(IntakeVerdictRecorder::class)]
#[UsesClass(AdmissibilityVerdictService::class)]
#[UsesClass(ApprovalRouteAdvancer::class)]
#[UsesClass(IntakeVerdict::class)]
class IntakeVerdictRecorderTest extends TestCase {
	/**
	 * The patches the store received, as [schema, uuid, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $patches = [];

	/**
	 * The recorder over a store that notes every patch.
	 *
	 * @return IntakeVerdictRecorder The recorder.
	 */
	private function recorder(): IntakeVerdictRecorder {
		$store = $this->createMock(RegisterObjectStore::class);
		$store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid): array {
				$this->patches[] = [$schema, $uuid, $data];
				return $data;
			}
		);

		return new IntakeVerdictRecorder(store: $store, advancer: new ApprovalRouteAdvancer(store: $store));
	}

	/**
	 * Two stages of a decision's route, the first an intake step.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stages(): array {
		return [
			['id' => 's1', 'sequence' => 1, 'status' => 'active', 'stepKind' => 'intake', 'decision' => 'd1', 'note' => 'decision'],
			['id' => 's2', 'sequence' => 2, 'status' => 'pending', 'decision' => 'd1', 'note' => 'decision'],
		];
	}

	/**
	 * Only a stage of kind intake is an intake stage; a stage without a kind is an approval step.
	 *
	 * @return void
	 */
	public function testOnlyAnIntakeKindIsAnIntakeStage(): void {
		$this->assertTrue(IntakeVerdictRecorder::isIntakeStage(stage: ['stepKind' => 'intake']));
		$this->assertFalse(IntakeVerdictRecorder::isIntakeStage(stage: ['stepKind' => 'approval']));
		$this->assertFalse(IntakeVerdictRecorder::isIntakeStage(stage: []));
	}

	/**
	 * A refusal ends the route at intake, resets the later stage and lands on the decision.
	 *
	 * @return void
	 */
	public function testARefusalClosesTheRouteAndWritesTheVerdict(): void {
		$stages = $this->stages();

		$verdict = $this->recorder()->conclude(
			action: ['actor' => 'alice', 'action' => 'niet-ontvankelijk', 'comment' => 'Onvolledig'],
			stages: $stages,
			active: $stages[0],
		);

		$this->assertSame('ended-at-intake', $verdict['routeOutcome']);
		$this->assertSame('Onvolledig', $verdict['ground']);
		$this->assertSame(['decision-stage', 's1'], [$this->patches[0][0], $this->patches[0][1]]);
		$this->assertSame('ended-at-intake', $this->patches[0][2]['outcome']);
		$this->assertSame(['decision-stage', 's2', ['status' => 'pending', 'outcome' => null, 'decidedAt' => null]], $this->patches[1]);
		$this->assertSame(
			['decision', 'd1', ['ontvankelijkheid' => 'niet-ontvankelijk', 'ontvankelijkheidGround' => 'Onvolledig', 'ontvankelijkheidDecidedBy' => 'alice']],
			$this->patches[2]
		);
		$this->assertCount(3, $this->patches);
	}

	/**
	 * An explicit ground wins over the comment.
	 *
	 * @return void
	 */
	public function testAGroundWinsOverTheComment(): void {
		$stages = $this->stages();

		$verdict = $this->recorder()->conclude(
			action: ['actor' => 'alice', 'action' => 'ontvankelijk', 'ground' => 'Compleet', 'comment' => 'Opmerking'],
			stages: $stages,
			active: $stages[0],
		);

		$this->assertSame('Compleet', $verdict['ground']);
		$this->assertTrue($verdict['advances']);
		$this->assertSame(['status' => 'active'], $this->patches[1][2]);
	}

	/**
	 * An unknown verdict is refused as a runtime refusal, before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownVerdictIsRefusedBeforeAnyWrite(): void {
		$stages = $this->stages();

		try {
			$this->recorder()->conclude(action: ['actor' => 'alice', 'action' => 'approved'], stages: $stages, active: $stages[0]);
			$this->fail('An unknown verdict was accepted.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Unknown verdict "approved"', $e->getMessage());
		}

		$this->assertSame([], $this->patches);
	}
}//end class
