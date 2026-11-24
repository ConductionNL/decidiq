<?php

/**
 * Tests for ConfidentialityPublishedListener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
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

namespace OCA\Decidiq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Decidiq\Listener\ConfidentialityPublishedListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Tests\Support\LegacyObjectArgs;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A lifted restriction stops awaiting publication once its agenda item or decision is public.
 *
 * @covers \OCA\Decidiq\Listener\ConfidentialityPublishedListener
 * @uses   \OCA\Decidiq\Service\SystemObjectRows
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */
final class ConfidentialityPublishedListenerTest extends TestCase {

	/**
	 * The restrictions saved during a test, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

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
		$entity->method('getUuid')->willReturn($row['id'] ?? null);
		return $entity;
	}//end entity()

	/**
	 * The restrictions of the land purchase: one lifted on the decision, one lifted on the agenda item, one still in force.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function restrictions(): array {
		return [
			[
				'id' => 'r-dec', 'scope' => 'decision', 'targetDecision' => 'dec-1', 'lifecycle' => 'dissolved',
				'liftingDate' => '2026-11-20', 'awaitingPublication' => true, 'ground' => 'ground-woo',
			],
			[
				'id' => 'r-item', 'scope' => 'item', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'dissolved',
				'liftingDate' => '2026-11-20', 'awaitingPublication' => true,
			],
			[
				'id' => 'r-dec-old', 'scope' => 'decision', 'targetDecision' => 'dec-1', 'lifecycle' => 'dissolved',
				'awaitingPublication' => false, 'targetPublishedAt' => '2026-01-05T10:00:00+01:00',
			],
		];
	}//end restrictions()

	/**
	 * The listener over the given restrictions.
	 *
	 * @param list<array<string, mixed>> $restrictions The restrictions.
	 * @param bool                       $failSave     Whether every save throws.
	 *
	 * @return ConfidentialityPublishedListener
	 */
	private function listener(array $restrictions, bool $failSave=false): ConfidentialityPublishedListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($restrictions): array {
				$filters = array_values($args)[0]['filters'];
				self::assertSame('confidentiality-restriction', $filters['schema']);
				unset($filters['register'], $filters['schema']);
				$found = array_filter(
					$restrictions,
					static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
				);
				return array_map(fn (array $row): ObjectEntity => $this->entity($row), array_values($found));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($failSave): ObjectEntity {
				if ($failSave === true) {
					throw new RuntimeException('database gone');
				}

				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				[$object, , $register, $schema, $uuid] = array_values($args);
				self::assertSame(['decidiq', 'confidentiality-restriction'], [$register, $schema]);
				$this->saved[(string)$uuid] = $object;
				return $this->entity($object);
			}
		);

		return new ConfidentialityPublishedListener($objects, new NullLogger());
	}//end listener()

	/**
	 * An update of an object from before to after.
	 *
	 * @param array<string, mixed> $old The object before.
	 * @param array<string, mixed> $new The object after.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(array $old, array $new): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent($this->entity($new), $this->entity($old));
	}//end updated()

	/**
	 * Publishing the decision ends the waiting of the restriction lifted on it, and keeps every other field.
	 *
	 * @return void
	 */
	public function testPublishingTheDecisionEndsTheWait(): void {
		$this->listener(self::restrictions())->handle(
			$this->updated(
				['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'internal'],
				['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'public']
			)
		);

		self::assertSame(['r-dec'], array_keys($this->saved));
		$saved = $this->saved['r-dec'];
		self::assertFalse($saved['awaitingPublication']);
		self::assertNotFalse(DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $saved['targetPublishedAt']));
		self::assertSame('2026-11-20', $saved['liftingDate']);
		self::assertSame('ground-woo', $saved['ground']);
		self::assertSame('dissolved', $saved['lifecycle']);
	}//end testPublishingTheDecisionEndsTheWait()

	/**
	 * Making the agenda item public ends the waiting of the restriction on the item.
	 *
	 * @return void
	 */
	public function testPublishingTheAgendaItemEndsTheWait(): void {
		$this->listener(self::restrictions())->handle(
			$this->updated(
				['id' => 'item-1', 'confidentiality' => 'internal'],
				['id' => 'item-1', 'confidentiality' => 'public']
			)
		);

		self::assertSame(['r-item'], array_keys($this->saved));
		self::assertFalse($this->saved['r-item']['awaitingPublication']);
	}//end testPublishingTheAgendaItemEndsTheWait()

	/**
	 * A save that leaves the target public, or does not make it public, touches no restriction.
	 *
	 * @return void
	 */
	public function testOnlyTheMomentOfPublishingCounts(): void {
		$listener = $this->listener(self::restrictions());
		$listener->handle($this->updated(['id' => 'dec-1', 'isPublished' => 'public'], ['id' => 'dec-1', 'isPublished' => 'public', 'title' => 'Nieuw']));
		$listener->handle($this->updated(['id' => 'dec-1', 'isPublished' => 'confidential'], ['id' => 'dec-1', 'isPublished' => 'internal']));
		$listener->handle($this->updated(['id' => 'item-1', 'confidentiality' => 'confidential'], ['id' => 'item-1', 'confidentiality' => 'internal']));

		self::assertSame([], $this->saved);
	}//end testOnlyTheMomentOfPublishingCounts()

	/**
	 * Which kind of target became public, read from its classifier.
	 *
	 * @return void
	 */
	public function testTheKindFollowsTheClassifier(): void {
		$listener = $this->listener([]);

		self::assertSame('decision', $listener->justPublished(new: ['isPublished' => 'public'], old: []));
		self::assertSame('item', $listener->justPublished(new: ['confidentiality' => 'public'], old: ['confidentiality' => 'internal']));
		self::assertNull($listener->justPublished(new: ['isPublished' => 'internal'], old: []));
		self::assertNull($listener->justPublished(new: ['confidentiality' => 'public'], old: ['confidentiality' => 'public']));
	}//end testTheKindFollowsTheClassifier()

	/**
	 * A save that fails is logged and counted as not marked; nothing throws.
	 *
	 * @return void
	 */
	public function testAFailedSaveMarksNothing(): void {
		$marked = $this->listener(self::restrictions(), failSave: true)->publishedOn(kind: 'decision', targetId: 'dec-1', now: new DateTimeImmutable());

		self::assertSame(0, $marked);
	}//end testAFailedSaveMarksNothing()

	/**
	 * The listener hears updates of agenda items and decisions, and the restriction carries the two fields.
	 *
	 * @return void
	 */
	public function testItIsSubscribedAndTheFieldsAreDeclared(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);
		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);
		self::assertContains([ObjectUpdatedEvent::class, ConfidentialityPublishedListener::class], $subscribed);

		$restriction = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction');
		self::assertSame('boolean', $restriction['properties']['awaitingPublication']['type']);
		self::assertTrue($restriction['properties']['awaitingPublication']['readOnly']);
		self::assertSame('date-time', $restriction['properties']['targetPublishedAt']['format']);
	}//end testItIsSubscribedAndTheFieldsAreDeclared()
}//end class
