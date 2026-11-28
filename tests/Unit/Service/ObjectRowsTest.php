<?php

/**
 * ObjectRows: a findAll answer, paged or bare, becomes rows with their uuid under `id`.
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
 * @spec openspec/changes/archive/2026-11-24-live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ObjectRows;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectRows.
 */
#[CoversClass(ObjectRows::class)]
final class ObjectRowsTest extends TestCase {

	/**
	 * A paged answer yields its results with the uuid under `id`.
	 *
	 * @return void
	 */
	public function testAPagedAnswerYieldsItsResultsWithTheirId(): void {
		$rows = ObjectRows::of(found: ['results' => [$this->entity(id: 'a', data: ['title' => 'One']), $this->entity(id: 'b', data: ['title' => 'Two'])], 'total' => 2]);

		self::assertSame([['id' => 'a', 'title' => 'One'], ['id' => 'b', 'title' => 'Two']], $rows);
	}//end testAPagedAnswerYieldsItsResultsWithTheirId()

	/**
	 * A bare list is read as the results themselves.
	 *
	 * @return void
	 */
	public function testABareListIsReadAsTheResults(): void {
		self::assertSame([['id' => 'c', 'status' => 'open']], ObjectRows::of(found: [$this->entity(id: 'c', data: ['status' => 'open'])]));
	}//end testABareListIsReadAsTheResults()

	/**
	 * The uuid wins over an `id` in the object's data, and non-entities are left out.
	 *
	 * @return void
	 */
	public function testTheUuidWinsAndNonEntitiesAreLeftOut(): void {
		$rows = ObjectRows::of(found: ['results' => [['id' => 'raw'], 'text', $this->entity(id: 'uuid-1', data: ['id' => 'stale', 'n' => 1])]]);

		self::assertSame([['id' => 'uuid-1', 'n' => 1]], $rows);
	}//end testTheUuidWinsAndNonEntitiesAreLeftOut()

	/**
	 * An empty answer gives no rows.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerGivesNoRows(): void {
		self::assertSame([], ObjectRows::of(found: ['results' => []]));
		self::assertSame([], ObjectRows::of(found: []));
	}//end testAnEmptyAnswerGivesNoRows()

	/**
	 * An object entity double.
	 *
	 * @param string               $id   The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $id, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
