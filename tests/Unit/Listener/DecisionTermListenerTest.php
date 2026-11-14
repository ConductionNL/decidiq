<?php

/**
 * A decision whose type declares a term is saved with the day it has to be taken by.
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
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionTermListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The listener reads the type and the contested decision and writes `termEndsAt`.
 */
class DecisionTermListenerTest extends TestCase {

	/**
	 * The register as the listener reads it, by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private const STORE = [
		'decision-template' => [
			'bezwaar-decision' => ['slug' => 'bezwaar-decision', 'termPeriod' => 'P12W', 'termStartsAt' => 'objection-period-end'],
			'subsidie' => ['slug' => 'subsidie', 'termPeriod' => 'P8W', 'termStartsAt' => 'received'],
			'raadsbesluit' => ['slug' => 'raadsbesluit'],
		],
		'decision' => [
			'refused-permit' => [
				'publishedAt' => '2026-06-23T10:00:00+02:00',
				'legalRemedyClause' => ['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college', 'text' => '...'],
			],
		],
	];

	/**
	 * The reads the listener made, as schema and id.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $reads = [];

	/**
	 * The listener over the store; `$broken` makes every read throw.
	 *
	 * @param bool $broken Whether the register fails.
	 *
	 * @return DecisionTermListener
	 */
	private function listener(bool $broken = false): DecisionTermListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) use ($broken): ?ObjectEntity {
				$this->reads[] = [(string)$schema, (string)$id];
				self::assertSame('decidiq', $register);
				self::assertFalse($_rbac);
				if ($broken === true) {
					throw new RuntimeException('register down');
				}

				$row = (self::STORE[(string)$schema][(string)$id] ?? null);
				return $row === null ? null : $this->entity(row: $row);
			}
		);

		return new DecisionTermListener(objectService: $objectService, logger: new NullLogger());
	}//end listener()

	/**
	 * An entity carrying a row.
	 *
	 * @param array<string, mixed> $row The row.
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
	 * Clerk Lisa records the objection by J. Vos against the refused permit:
	 * the term is twelve weeks from the end of its six-week objection period.
	 *
	 * @return void
	 */
	public function testAnObjectionIsSavedWithItsTerm(): void {
		$event = new ObjectCreatingEvent($this->entity(row: ['type' => 'bezwaar-decision', 'contests' => 'refused-permit', 'receivedAt' => '2026-08-04']));

		$this->listener()->handle($event);

		self::assertSame(['termEndsAt' => '2026-10-27'], $event->getModifiedData());
		self::assertSame([['decision-template', 'bezwaar-decision'], ['decision', 'refused-permit']], $this->reads);
	}//end testAnObjectionIsSavedWithItsTerm()

	/**
	 * On an update the new object counts, and a field the save changes too.
	 *
	 * @return void
	 */
	public function testAnUpdateCountsFromTheNewReceivedDay(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(row: ['type' => ['id' => 'subsidie'], 'receivedAt' => '2026-08-04']),
			$this->entity(row: ['type' => 'subsidie', 'receivedAt' => '2026-07-01'])
		);
		$event->setModifiedData(['receivedAt' => '2026-08-11']);

		$this->listener()->handle($event);

		self::assertSame(['receivedAt' => '2026-08-11', 'termEndsAt' => '2026-10-06'], $event->getModifiedData());
	}//end testAnUpdateCountsFromTheNewReceivedDay()

	/**
	 * A type without a term writes an empty term, so a changed type clears an old date.
	 *
	 * @return void
	 */
	public function testATypeWithoutATermLeavesTheTermEmpty(): void {
		$event = new ObjectCreatingEvent($this->entity(row: ['type' => 'raadsbesluit', 'receivedAt' => '2026-08-04', 'termEndsAt' => '2026-09-01']));

		$this->listener()->handle($event);

		self::assertSame(['termEndsAt' => null], $event->getModifiedData());
	}//end testATypeWithoutATermLeavesTheTermEmpty()

	/**
	 * Without a type, with an unknown type, or when the register fails, the
	 * save goes ahead untouched and nothing is refused.
	 *
	 * @return void
	 */
	public function testWithoutAReadableTypeTheSaveIsLeftAlone(): void {
		foreach ([[[], false], [['type' => 'unknown'], false], [['type' => 'bezwaar-decision'], true]] as [$row, $broken]) {
			$event = new ObjectCreatingEvent($this->entity(row: $row));

			$this->listener(broken: $broken)->handle($event);

			self::assertSame([], $event->getModifiedData());
			self::assertFalse($event->isPropagationStopped());
		}
	}//end testWithoutAReadableTypeTheSaveIsLeftAlone()
}//end class
