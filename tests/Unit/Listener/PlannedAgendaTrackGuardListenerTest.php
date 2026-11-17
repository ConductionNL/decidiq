<?php

/**
 * The tracks of the long-term agenda stay one level deep.
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
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-002-tracks-are-one-level-deep
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PlannedAgendaTrackGuardListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the real before-save events, the listener and its subscription.
 *
 * @covers \OCA\Decidiq\Listener\PlannedAgendaTrackGuardListener
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 */
class PlannedAgendaTrackGuardListenerTest extends TestCase {

	/**
	 * The stored items by id: a topic, one of its tracks, and a standalone item.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const STORED = [
		'energie' => ['id' => 'energie', 'subject' => 'Energietransitie'],
		'warmte' => ['id' => 'warmte', 'subject' => 'Warmtevisie 2.0', 'parentItem' => 'energie'],
		'wonen' => ['id' => 'wonen', 'subject' => 'Woonvisie'],
	];

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
	 * The listener over the stored items.
	 *
	 * @param bool $broken Whether reading the topic fails.
	 *
	 * @return PlannedAgendaTrackGuardListener
	 */
	private function listener(bool $broken = false): PlannedAgendaTrackGuardListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('find')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('find')->willReturnCallback(
				fn (mixed ...$args): ?ObjectEntity => isset(self::STORED[$args[0]]) ? $this->entity(self::STORED[$args[0]]) : null
			);
		}

		$objects->method('findAll')->willReturnCallback(
			static function (array $config): array {
				$parent = ($config['filters']['parentItem'] ?? '');
				return array_values(array_filter(self::STORED, static fn (array $item): bool => ($item['parentItem'] ?? '') === $parent));
			}
		);

		return new PlannedAgendaTrackGuardListener($objects, new NullLogger());
	}//end listener()

	/**
	 * A track under a topic is saved.
	 *
	 * @return void
	 */
	public function testATrackUnderATopicIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'planned-agenda-item', 'subject' => 'Regionale energiestrategie 2.0', 'parentItem' => 'energie']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testATrackUnderATopicIsSaved()

	/**
	 * A track under a track is refused, naming the track it was put under.
	 *
	 * @return void
	 */
	public function testATrackUnderATrackIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['subject' => 'Warmtenet Noord', 'parentItem' => 'warmte']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'The item was not saved: "Warmtevisie 2.0" is itself a track, so it cannot have tracks. Add this track to its topic instead.'],
			$event->getErrors()
		);
	}//end testATrackUnderATrackIsRefused()

	/**
	 * A topic with tracks cannot become a track, on update.
	 *
	 * @return void
	 */
	public function testATopicWithTracksCannotBecomeATrack(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['subject' => 'Energietransitie', 'parentItem' => 'wonen'], 'energie'),
			$this->entity(['subject' => 'Energietransitie'], 'energie')
		);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'The item was not saved: "Energietransitie" has tracks of its own, so it cannot become a track of "Woonvisie".'],
			$event->getErrors()
		);
	}//end testATopicWithTracksCannotBecomeATrack()

	/**
	 * An item cannot be a track of itself.
	 *
	 * @return void
	 */
	public function testASelfReferenceIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity(['id' => 'wonen', 'parentItem' => 'wonen']), $this->entity([], 'wonen'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'The item was not saved: it cannot be a track of itself.'], $event->getErrors());
	}//end testASelfReferenceIsRefused()

	/**
	 * An item without a topic, another schema, and a topic that does not exist are left alone.
	 *
	 * @return void
	 */
	public function testWhatIsNotATrackIsLeftAlone(): void {
		foreach ([['subject' => 'Woonvisie'], ['_schemaSlug' => 'decision', 'parentItem' => 'warmte'], ['parentItem' => 'missing']] as $row) {
			$event = new ObjectCreatingEvent($this->entity($row));
			$this->listener()->handle($event);
			self::assertFalse($event->isPropagationStopped(), json_encode($row));
		}
	}//end testWhatIsNotATrackIsLeftAlone()

	/**
	 * A topic that cannot be read stops the save.
	 *
	 * @return void
	 */
	public function testAnUnreadableTopicStopsTheSave(): void {
		$event = new ObjectCreatingEvent($this->entity(['parentItem' => 'energie']));

		$this->listener(broken: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('could not be checked', (string) $event->getErrors()['message']);
	}//end testAnUnreadableTopicStopsTheSave()

	/**
	 * The registrar subscribes the guard to creates and updates.
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

		self::assertContains([ObjectCreatingEvent::class, PlannedAgendaTrackGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, PlannedAgendaTrackGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuard()
}//end class
