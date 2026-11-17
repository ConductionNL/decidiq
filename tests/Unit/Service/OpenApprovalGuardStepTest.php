<?php

/**
 * Unit tests for the step-closing half of OpenApprovalGuard.
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

use OCA\Decidiq\Service\OpenApprovalGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a recorded verb leaves its action in, and which open items hold a step.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
 */
#[CoversClass(OpenApprovalGuard::class)]
final class OpenApprovalGuardStepTest extends TestCase {

	/**
	 * Each recorded verb ends in its state; an unknown verb in none.
	 *
	 * @return void
	 */
	public function testEachVerbEndsInItsState(): void {
		$guard = new OpenApprovalGuard();
		$states = [];
		foreach (['approved', 'endorsed', 'advised', 'skipped', 'lapsed', 'rejected', 'returned', 'pondered', ''] as $verb) {
			$states[$verb] = $guard->stateForVerb(verb: $verb);
		}

		self::assertSame(
			[
				'approved' => 'granted',
				'endorsed' => 'granted',
				'advised' => 'granted',
				'skipped' => 'granted',
				'lapsed' => 'granted',
				'rejected' => 'refused',
				'returned' => 'refused',
				'pondered' => '',
				'' => '',
			],
			$states
		);

	}//end testEachVerbEndsInItsState()

	/**
	 * The trail of one subject: open items on steps 1 and 2, the actor's own
	 * open item on step 1, a recorded act still reading open, a closed item.
	 *
	 * @return array<int, mixed> The actions.
	 */
	private function trail(): array {
		return [
			['id' => 'o-1', 'step' => 1, 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen'],
			['id' => 'o-2', 'step' => 2, 'action' => '', 'state' => 'open', 'assignee' => 'p.pieters'],
			['id' => 'mine', 'step' => 1, 'action' => '', 'state' => 'open', 'assignee' => 'bob'],
			['id' => 'act', 'step' => 1, 'action' => 'advised', 'state' => 'open', 'assignee' => 'k.klaassen'],
			['id' => 'done', 'step' => 1, 'action' => '', 'state' => 'granted', 'assignee' => 'l.lamers'],
			'not an action',
		];

	}//end trail()

	/**
	 * Only open work items on the stage's own step count, split by whose they are.
	 *
	 * @return void
	 */
	public function testOpenItemsAreSplitIntoOthersAndTheActorsOwn(): void {
		$guard = new OpenApprovalGuard();
		$stage = ['sequence' => 1, 'mandatory' => true];

		self::assertSame(['o-1'], array_column($guard->othersOpenOnStep(stage: $stage, actions: $this->trail(), actor: 'bob'), 'id'));
		self::assertSame(['mine'], array_column($guard->ownOpenOnStep(stage: $stage, actions: $this->trail(), actor: 'bob'), 'id'));
		self::assertSame(['o-2'], array_column($guard->othersOpenOnStep(stage: ['sequence' => 2], actions: $this->trail(), actor: 'bob'), 'id'));

	}//end testOpenItemsAreSplitIntoOthersAndTheActorsOwn()

	/**
	 * Somebody else's open item on a mandatory step refuses the close, naming them.
	 *
	 * @return void
	 */
	public function testSomebodyElsesOpenItemRefusesTheClose(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This subject is waiting on an approval: j.jansen at step 1.');

		(new OpenApprovalGuard())->assertStepMayClose(stage: ['sequence' => 1, 'mandatory' => true], actions: $this->trail(), actor: 'bob');

	}//end testSomebodyElsesOpenItemRefusesTheClose()

	/**
	 * An optional step, or a step whose only open item is the actor's, closes.
	 *
	 * @return void
	 */
	public function testAnOptionalStepOrTheActorsOwnItemDoesNotRefuse(): void {
		$guard = new OpenApprovalGuard();
		$guard->assertStepMayClose(stage: ['sequence' => 1, 'mandatory' => false], actions: $this->trail(), actor: 'bob');
		$guard->assertStepMayClose(stage: ['sequence' => 1, 'mandatory' => true], actions: [$this->trail()[2]], actor: 'bob');
		$guard->assertStepMayClose(stage: ['sequence' => 3, 'mandatory' => true], actions: $this->trail(), actor: 'bob');

		self::assertSame([], $guard->othersOpenOnStep(stage: ['sequence' => 3], actions: $this->trail(), actor: 'bob'));

	}//end testAnOptionalStepOrTheActorsOwnItemDoesNotRefuse()
}//end class
