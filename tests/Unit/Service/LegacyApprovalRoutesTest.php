<?php

/**
 * Tests: routes that run on the old engine are found, marked and listed.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Command\ApprovalRoutesLegacyCommand;
use OCA\Decidiq\Service\LegacyApprovalRoutes;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Decidiq\Service\LegacyApprovalRoutes
 * @covers \OCA\Decidiq\Command\ApprovalRoutesLegacyCommand
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */
class LegacyApprovalRoutesTest extends TestCase {

	/**
	 * @var RegisterObjectStore&MockObject
	 */
	private $store;

	protected function setUp(): void {
		$this->store = $this->createMock(RegisterObjectStore::class);
		$this->store->method('findAll')->willReturnCallback(
			static fn (string $schema, array $filters): array => match ($filters['status']) {
				'active' => [
					['decision' => 'd-parking', 'sequence' => 3, 'label' => 'Commissie Ruimte'],
					['decision' => ['id' => 'd-budget'], 'sequence' => 1, 'label' => 'Ambtelijk advies'],
				],
				'pending' => [
					['decision' => 'd-parking', 'sequence' => 4, 'label' => 'Gemeenteraad'],
					['decision' => '', 'sequence' => 1],
				],
			}
		);
	}//end setUp()

	/**
	 * A decision with open stages is listed once, at its lowest open step.
	 */
	public function testEachDecisionWithAnOpenStageIsListedOnceAtItsOpenStep(): void {
		$this->assertSame(
			[
				['decision' => 'd-budget', 'sequence' => 1, 'label' => 'Ambtelijk advies'],
				['decision' => 'd-parking', 'sequence' => 3, 'label' => 'Commissie Ruimte'],
			],
			(new LegacyApprovalRoutes($this->store))->open()
		);
	}//end testEachDecisionWithAnOpenStageIsListedOnceAtItsOpenStep()

	/**
	 * Scenario: a half-way route is marked legacy; one already marked is not written again.
	 */
	public function testMarkingIsIdempotent(): void {
		$this->store->method('find')->willReturnCallback(
			static fn (string $schema, string $uuid): array => ['id' => $uuid] + ($uuid === 'd-budget' ? ['approvalEngine' => 'legacy'] : [])
		);
		$this->store->expects($this->once())->method('patch')->with('decision', ['approvalEngine' => 'legacy'], 'd-parking')->willReturn([]);

		$this->assertSame(1, (new LegacyApprovalRoutes($this->store))->mark());
	}//end testMarkingIsIdempotent()

	/**
	 * The command lists each decision and its open step.
	 */
	public function testTheCommandListsWhatIsLeft(): void {
		$tester = new CommandTester(new ApprovalRoutesLegacyCommand(new LegacyApprovalRoutes($this->store)));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(
			"d-budget: step 1 (Ambtelijk advies) is open\nd-parking: step 3 (Commissie Ruimte) is open\n2 decision(s) still on the old engine.\n",
			$tester->getDisplay()
		);
	}//end testTheCommandListsWhatIsLeft()

	/**
	 * Scenario: the old engine can go when the command lists no route.
	 */
	public function testTheCommandSaysWhenNothingIsLeft(): void {
		$empty = $this->createMock(RegisterObjectStore::class);
		$empty->method('findAll')->willReturn([]);
		$tester = new CommandTester(new ApprovalRoutesLegacyCommand(new LegacyApprovalRoutes($empty)));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("No approval route runs on the old engine.\n", $tester->getDisplay());
	}//end testTheCommandSaysWhenNothingIsLeft()
}//end class
