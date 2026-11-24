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

use DateTime;
use OCA\Decidiq\Listener\ConfidentialityPublishedListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Tests\Support\LegacyObjectArgs;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A lifted restriction stops awaiting publication once its agenda item or decision is public.
 *
 * @covers \OCA\Decidiq\Listener\ConfidentialityPublishedListener
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */
final class ConfidentialityPublishedListenerTest extends TestCase {

	/**
	 * The moment the clock reads.
	 *
	 * @var string
	 */
	private const NOW = '2026-11-24T12:00:00+00:00';

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
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($row['id'] ?? null);
		return $entity;
	}//end entity()

	/**
	 * The restrictions of the land purchase: one lifted on the decision, one lifted on the agenda item, one long published.
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
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($restrictions): array {
				$filters = array_values($args)[0]['filters'];
				self::assertSame(expected: 'confidentiality-restriction', actual: $filters['schema']);
				unset($filters['register'], $filters['schema']);
				$found = array_filter(
					$restrictions,
					static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
				);
				return array_map(fn (array $row): ObjectEntity => $this->entity(row: $row), array_values($found));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($failSave): ObjectEntity {
				if ($failSave === true) {
					throw new RuntimeException('database gone');
				}

				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				[$object, , $register, $schema, $uuid] = array_values($args);
				self::assertSame(expected: ['decidiq', 'confidentiality-restriction'], actual: [$register, $schema]);
				$this->saved[(string)$uuid] = $object;
				return $this->entity(row: $object);
			}
		);

		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime(self::NOW));

		return new ConfidentialityPublishedListener(objectService: $objects, clock: $clock, logger: new NullLogger());
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
		return new ObjectUpdatedEvent($this->entity(row: $new), $this->entity(row: $old));
	}//end updated()

	/**
	 * Publishing the decision ends the waiting of the restriction lifted on it, and keeps every other field.
	 *
	 * @return void
	 */
	public function testPublishingTheDecisionEndsTheWait(): void {
		$this->listener(restrictions: self::restrictions())->handle(
			$this->updated(
				old: ['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'internal'],
				new: ['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'public']
			)
		);

		self::assertSame(expected: ['r-dec'], actual: array_keys($this->saved));
		$saved = $this->saved['r-dec'];
		self::assertFalse(condition: $saved['awaitingPublication']);
		self::assertSame(expected: self::NOW, actual: $saved['targetPublishedAt']);
		self::assertSame(expected: '2026-11-20', actual: $saved['liftingDate']);
		self::assertSame(expected: 'ground-woo', actual: $saved['ground']);
		self::assertSame(expected: 'dissolved', actual: $saved['lifecycle']);
	}//end testPublishingTheDecisionEndsTheWait()

	/**
	 * Making the agenda item public ends the waiting of the restriction on the item.
	 *
	 * @return void
	 */
	public function testPublishingTheAgendaItemEndsTheWait(): void {
		$this->listener(restrictions: self::restrictions())->handle(
			$this->updated(
				old: ['id' => 'item-1', 'confidentiality' => 'internal'],
				new: ['id' => 'item-1', 'confidentiality' => 'public']
			)
		);

		self::assertSame(expected: ['r-item'], actual: array_keys($this->saved));
		self::assertFalse(condition: $this->saved['r-item']['awaitingPublication']);
	}//end testPublishingTheAgendaItemEndsTheWait()

	/**
	 * A save that leaves the target public, or does not make it public, touches no restriction.
	 *
	 * @return void
	 */
	public function testOnlyTheMomentOfPublishingCounts(): void {
		$listener = $this->listener(restrictions: self::restrictions());
		$listener->handle($this->updated(old: ['id' => 'dec-1', 'isPublished' => 'public'], new: ['id' => 'dec-1', 'isPublished' => 'public', 'title' => 'Nieuw']));
		$listener->handle($this->updated(old: ['id' => 'dec-1', 'isPublished' => 'confidential'], new: ['id' => 'dec-1', 'isPublished' => 'internal']));
		$listener->handle($this->updated(old: ['id' => 'item-1', 'confidentiality' => 'confidential'], new: ['id' => 'item-1', 'confidentiality' => 'internal']));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testOnlyTheMomentOfPublishingCounts()

	/**
	 * Which kind of target became public, read from its classifier.
	 *
	 * @return void
	 */
	public function testTheKindFollowsTheClassifier(): void {
		$listener = $this->listener(restrictions: []);

		self::assertSame(expected: 'decision', actual: $listener->justPublished(new: ['isPublished' => 'public'], old: []));
		self::assertSame(expected: 'item', actual: $listener->justPublished(new: ['confidentiality' => 'public'], old: ['confidentiality' => 'internal']));
		self::assertNull(actual: $listener->justPublished(new: ['isPublished' => 'internal'], old: []));
		self::assertNull(actual: $listener->justPublished(new: ['confidentiality' => 'public'], old: ['confidentiality' => 'public']));
	}//end testTheKindFollowsTheClassifier()

	/**
	 * A save that fails, an unknown kind or an empty target marks nothing; nothing throws.
	 *
	 * @return void
	 */
	public function testNothingIsMarkedWhenItCannotBe(): void {
		$listener = $this->listener(restrictions: self::restrictions(), failSave: true);

		self::assertSame(expected: 0, actual: $listener->publishedOn(kind: 'decision', targetId: 'dec-1'));
		self::assertSame(expected: 0, actual: $listener->publishedOn(kind: 'document', targetId: 'doc-1'));
		self::assertSame(expected: 0, actual: $listener->publishedOn(kind: 'decision', targetId: ''));
	}//end testNothingIsMarkedWhenItCannotBe()

	/**
	 * The listener hears updates of agenda items and decisions, and the restriction carries the two fields.
	 *
	 * @return void
	 */
	public function testItIsSubscribedAndTheFieldsAreDeclared(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);
		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);
		self::assertContains(needle: [ObjectUpdatedEvent::class, ConfidentialityPublishedListener::class], haystack: $subscribed);

		$restriction = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction');
		self::assertSame(expected: 'boolean', actual: $restriction['properties']['awaitingPublication']['type']);
		self::assertTrue(condition: $restriction['properties']['awaitingPublication']['readOnly']);
		self::assertSame(expected: 'date-time', actual: $restriction['properties']['targetPublishedAt']['format']);
	}//end testItIsSubscribedAndTheFieldsAreDeclared()
}//end class
