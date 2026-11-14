<?php

/**
 * The toedeling write path calls the ondermandaat guard before a toedeling is saved.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\OndermandaatGuardListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Service\OndermandaatGuardService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the listener and the real guard; deleting the call site or the
 * subscription turns a test red, not only deleting the guard.
 *
 * @covers \OCA\Decidiq\Listener\OndermandaatGuardListener
 * @covers \OCA\Decidiq\Service\OndermandaatGuardService
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 * @covers \OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions
 */
class OndermandaatGuardListenerTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row  The record.
	 * @param string|null          $uuid Its uuid, when it has one.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row, ?string $uuid = null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * The listener over a permitting mandaat and a forbidding delegatie.
	 *
	 * @param bool $broken Whether reading a parent fails.
	 *
	 * @return OndermandaatGuardListener
	 */
	private function listener(bool $broken = false): OndermandaatGuardListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('find')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('find')->willReturnCallback(
				fn (mixed ...$args): ObjectEntity => $this->entity(
					[
						'mandaat' => ['id' => 'mandaat', 'subMandatePermitted' => true],
						'delegatie' => ['id' => 'delegatie', 'subMandatePermitted' => false],
						'child' => ['id' => 'child', 'subMandatePermitted' => true, 'parentAllocation' => 'mandaat'],
					][$args[0]]
				)
			);
		}

		return new OndermandaatGuardListener(new OndermandaatGuardService($objects), new NullLogger());
	}//end listener()

	public function testAnOndermandaatUnderAForbiddingParentIsStoppedBeforeItIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'bevoegdheidstoedeling', 'parentAllocation' => 'delegatie']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'The toedeling was not saved: its parent does not permit ondermandaat.'], $event->getErrors());
	}//end testAnOndermandaatUnderAForbiddingParentIsStoppedBeforeItIsSaved()

	public function testReparentingAStoredToedelingIntoALoopIsStoppedOnUpdate(): void {
		$event = new ObjectUpdatingEvent($this->entity(['parentAllocation' => 'child'], 'mandaat'), $this->entity([], 'mandaat'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('may not loop', (string)$event->getErrors()['message']);
	}//end testReparentingAStoredToedelingIntoALoopIsStoppedOnUpdate()

	public function testAPermittedOndermandaatIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['parentAllocation' => 'mandaat']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAPermittedOndermandaatIsSaved()

	public function testAnUnreadableParentStopsTheSave(): void {
		$event = new ObjectCreatingEvent($this->entity(['parentAllocation' => 'mandaat']));

		$this->listener(broken: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUnreadableParentStopsTheSave()

	/**
	 * The renamed schema is guarded, and so is the old Dutch slug.
	 *
	 * @return void
	 */
	public function testBothTheRenamedAndTheOldSchemaAreGuarded(): void {
		foreach (['authority-delegation', 'Authority-Delegation', 'bevoegdheidstoedeling'] as $slug) {
			$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => $slug, 'parentAllocation' => 'delegatie']));

			$this->listener()->handle($event);

			self::assertTrue($event->isPropagationStopped(), $slug);
		}
	}//end testBothTheRenamedAndTheOldSchemaAreGuarded()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'parentAllocation' => 'delegatie']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	public function testTheRegistrarSubscribesTheGuardToToedelingSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, OndermandaatGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, OndermandaatGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuardToToedelingSaves()

	/**
	 * The subscription names the renamed schema, so saves on it reach the guard.
	 *
	 * @return void
	 */
	public function testTheSubscriptionCoversTheRenamedSchema(): void {
		$guarded = array_values(
			array_filter(
				SaveGuardSubscriptions::ALL,
				static fn (array $row): bool => $row['listener'] === OndermandaatGuardListener::class
			)
		);

		self::assertCount(2, $guarded);
		foreach ($guarded as $row) {
			self::assertSame(OndermandaatGuardService::SCHEMAS, $row['schemas']);
		}
	}//end testTheSubscriptionCoversTheRenamedSchema()
}//end class
