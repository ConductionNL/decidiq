<?php

/**
 * Unit tests for the MoveAgendaItemStatusToPhase repair step.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MoveAgendaItemStatusToPhase;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An agenda item's phase moves from the undeclared `status` to `phase`, once.
 */
class MoveAgendaItemStatusToPhaseTest extends TestCase {

	private ObjectServiceInterface&MockObject $objects;

	private IOutput&MockObject $output;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @return MoveAgendaItemStatusToPhase
	 */
	private function step(): MoveAgendaItemStatusToPhase {
		return new MoveAgendaItemStatusToPhase(objectService: $this->objects, logger: $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * An entity double.
	 *
	 * @param array<string, mixed> $data The object
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * An item whose phase is still in status gets it in phase, with a phase-only patch.
	 *
	 * @return void
	 */
	public function testAPhaseInStatusIsMovedToPhase(): void {
		$this->objects->method('findAll')->willReturn([$this->entity(['id' => 'ai-1', 'title' => 'Begroting', 'status' => 'besluitvorming'])]);
		$this->objects->expects($this->once())->method('patchObject')->with(
			objectId: 'ai-1',
			data: ['phase' => 'besluitvorming'],
			register: 'decidiq',
			schema: 'agenda-item',
		);
		$this->objects->expects($this->never())->method('saveObject');
		$this->output->expects($this->once())->method('info')->with('MoveAgendaItemStatusToPhase: 1 moved, 0 failed.');

		$this->step()->run($this->output);
	}//end testAPhaseInStatusIsMovedToPhase()

	/**
	 * A second run changes nothing: the item now has a phase.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$store = ['id' => 'ai-1', 'status' => 'besluitvorming'];
		$this->objects->method('findAll')->willReturnCallback(
			function () use (&$store): array {
				return [$this->entity($store)];
			}
		);
		$this->objects->expects($this->once())->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) use (&$store): ObjectEntity {
				$store = array_merge($store, $data);
				return $this->entity($store);
			}
		);

		$this->step()->run($this->output);
		$this->step()->run($this->output);

		$this->assertSame('besluitvorming', $store['phase']);
	}//end testASecondRunChangesNothing()

	/**
	 * Items with a phase already, no status, a status that is no phase, or no id are left alone.
	 *
	 * @return void
	 */
	public function testOnlyAPhaseInStatusOnAnItemWithoutPhaseIsMoved(): void {
		$this->objects->method('findAll')->willReturn(
			[
				$this->entity(['id' => 'has-phase', 'phase' => 'oordeelsvorming', 'status' => 'beeldvorming']),
				$this->entity(['id' => 'no-status']),
				$this->entity(['id' => 'other-status', 'status' => 'open']),
				$this->entity(['status' => 'beeldvorming']),
				$this->entity(['@self' => ['id' => 'self-id'], 'phase' => '', 'status' => 'voorstel']),
			]
		);
		$this->objects->expects($this->once())->method('patchObject')->with(
			objectId: 'self-id',
			data: ['phase' => 'voorstel'],
			register: 'decidiq',
			schema: 'agenda-item',
		);

		$this->step()->run($this->output);
	}//end testOnlyAPhaseInStatusOnAnItemWithoutPhaseIsMoved()

	/**
	 * A write that fails is counted and the rest still move.
	 *
	 * @return void
	 */
	public function testOneFailedWriteDoesNotStopTheRest(): void {
		$this->objects->method('findAll')->willReturn(
			[
				$this->entity(['id' => 'ai-1', 'status' => 'beeldvorming']),
				$this->entity(['id' => 'ai-2', 'status' => 'oordeelsvorming']),
			]
		);
		$written = [];
		$this->objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) use (&$written): ObjectEntity {
				if ($objectId === 'ai-1') {
					throw new RuntimeException('validation failed');
				}

				$written[$objectId] = $data;
				return $this->entity($data);
			}
		);
		$this->output->expects($this->once())->method('info')->with('MoveAgendaItemStatusToPhase: 1 moved, 1 failed.');

		$this->step()->run($this->output);

		$this->assertSame(['ai-2' => ['phase' => 'oordeelsvorming']], $written);
	}//end testOneFailedWriteDoesNotStopTheRest()

	/**
	 * Agenda items that cannot be listed (no register yet) are not an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableListIsNothingToDo(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('no such schema'));
		$this->objects->expects($this->never())->method('patchObject');
		$this->output->expects($this->once())->method('info')->with($this->stringContains('nothing to do'));

		$this->step()->run($this->output);
	}//end testAnUnreadableListIsNothingToDo()

	/**
	 * A full page reads the next one; a short page is the last.
	 *
	 * @return void
	 */
	public function testItPagesUntilAShortPage(): void {
		$offsets = [];
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$offsets): array {
				$offsets[] = $config['offset'];
				$count = ($config['offset'] === 0) ? 500 : 1;
				return array_fill(0, $count, ['id' => 'x', 'phase' => 'beeldvorming']);
			}
		);

		$this->step()->run($this->output);

		$this->assertSame([0, 500], $offsets);
	}//end testItPagesUntilAShortPage()
}//end class
