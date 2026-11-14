<?php

/**
 * A planned-agenda item that breaks its owner or lapse rule is stopped before
 * it is saved.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PlannedAgendaItemRulesGuardListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the listener: both value-dependent rules refuse on create and on
 * update, a valid item passes, and the registrar subscribes the guard.
 *
 * @covers \OCA\Decidiq\Listener\PlannedAgendaItemRulesGuardListener
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 */
class PlannedAgendaItemRulesGuardListenerTest extends TestCase {

	/**
	 * An item as the planned-agenda schema stores it.
	 *
	 * @param array<string, mixed> $fields Fields to set over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function item(array $fields=[]): array {
		return array_merge(
			[
				'_schemaSlug'    => 'planned-agenda-item',
				'subject'        => 'Herziening parkeerbeleid binnenstad',
				'governanceBody' => 'body-raad',
				'plannedPeriod'  => '2026-Q4',
				'expectedType'   => 'council-proposal',
				'ownerType'      => 'executive-board',
				'owner'          => null,
				'lifecycle'      => 'planned',
			],
			$fields
		);
	}//end item()

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * A portfolio holder as owner type without a person is refused on create.
	 *
	 * @return void
	 */
	public function testAPortfolioHolderWithoutAPersonIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity($this->item(['ownerType' => 'portfolio-holder', 'owner' => '  '])));

		(new PlannedAgendaItemRulesGuardListener())->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'The item was not saved: a portfolio holder as owner needs the person who holds the portfolio.'],
			$event->getErrors()
		);
	}//end testAPortfolioHolderWithoutAPersonIsRefused()

	/**
	 * With the person, and for any other owner type without one, it passes.
	 *
	 * @return void
	 */
	public function testAPortfolioHolderWithAPersonAndOtherOwnerTypesPass(): void {
		$named = new ObjectCreatingEvent($this->entity($this->item(['ownerType' => 'portfolio-holder', 'owner' => 'person-wethouder'])));
		$board = new ObjectCreatingEvent($this->entity($this->item()));

		(new PlannedAgendaItemRulesGuardListener())->handle($named);
		(new PlannedAgendaItemRulesGuardListener())->handle($board);

		self::assertFalse($named->isPropagationStopped());
		self::assertSame([], $named->getErrors());
		self::assertFalse($board->isPropagationStopped());
	}//end testAPortfolioHolderWithAPersonAndOtherOwnerTypesPass()

	/**
	 * An update that clears the person of a portfolio-holder item is refused.
	 *
	 * @return void
	 */
	public function testClearingThePersonOnUpdateIsRefused(): void {
		$old   = $this->entity($this->item(['ownerType' => 'portfolio-holder', 'owner' => 'person-wethouder']));
		$event = new ObjectUpdatingEvent($this->entity($this->item(['ownerType' => 'portfolio-holder', 'owner' => null])), $old);

		(new PlannedAgendaItemRulesGuardListener())->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testClearingThePersonOnUpdateIsRefused()

	/**
	 * Marking an item lapsed without a reason is refused; with one it passes.
	 *
	 * @return void
	 */
	public function testLapsingNeedsAReason(): void {
		$old     = $this->entity($this->item());
		$without = new ObjectUpdatingEvent($this->entity($this->item(['lifecycle' => 'lapsed', 'reasonExpired' => ''])), $old);
		$with    = new ObjectUpdatingEvent($this->entity($this->item(['lifecycle' => 'lapsed', 'reasonExpired' => 'Onderwerp opgegaan in omgevingsvisie'])), $old);

		(new PlannedAgendaItemRulesGuardListener())->handle($without);
		(new PlannedAgendaItemRulesGuardListener())->handle($with);

		self::assertTrue($without->isPropagationStopped());
		self::assertSame(['message' => 'The item was not marked as lapsed: give the reason it lapsed.'], $without->getErrors());
		self::assertFalse($with->isPropagationStopped());
	}//end testLapsingNeedsAReason()

	/**
	 * A new item created straight into lapsed needs the reason as well.
	 *
	 * @return void
	 */
	public function testCreatingALapsedItemWithoutAReasonIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity($this->item(['lifecycle' => 'lapsed'])));

		(new PlannedAgendaItemRulesGuardListener())->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testCreatingALapsedItemWithoutAReasonIsRefused()

	/**
	 * A row that was already lapsed is not judged on the reason again.
	 *
	 * @return void
	 */
	public function testAnAlreadyLapsedRowIsNotJudgedOnTheReason(): void {
		$guard = new PlannedAgendaItemRulesGuardListener();

		self::assertNull($guard->refusal(row: $this->item(['lifecycle' => 'lapsed']), old: $this->item(['lifecycle' => 'lapsed'])));
		self::assertNotNull($guard->refusal(row: $this->item(['lifecycle' => 'lapsed']), old: $this->item(['lifecycle' => 'postponed'])));
	}//end testAnAlreadyLapsedRowIsNotJudgedOnTheReason()

	/**
	 * The superseded slug is judged; another schema and another event are not.
	 *
	 * @return void
	 */
	public function testOnlyPlannedAgendaSavesAreJudged(): void {
		$legacy  = new ObjectCreatingEvent($this->entity($this->item(['_schemaSlug' => 'termijnagenda-item', 'ownerType' => 'portfolio-holder'])));
		$another = new ObjectCreatingEvent($this->entity($this->item(['_schemaSlug' => 'decision', 'ownerType' => 'portfolio-holder'])));
		$other   = $this->createMock(Event::class);
		$other->expects(self::never())->method('stopPropagation');

		$guard = new PlannedAgendaItemRulesGuardListener();
		$guard->handle($legacy);
		$guard->handle($another);
		$guard->handle($other);

		self::assertTrue($legacy->isPropagationStopped());
		self::assertFalse($another->isPropagationStopped());
	}//end testOnlyPlannedAgendaSavesAreJudged()

	/**
	 * The guard is subscribed to creates and updates: a guard with no call
	 * site guards nothing.
	 *
	 * @return void
	 */
	public function testTheRegistrarSubscribesTheGuard(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, PlannedAgendaItemRulesGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, PlannedAgendaItemRulesGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuard()
}//end class
