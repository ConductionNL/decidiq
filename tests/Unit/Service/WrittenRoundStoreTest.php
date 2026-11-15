<?php

/**
 * Unit tests for WrittenRoundStore: the OpenRegister reads and writes of a written round.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Find, page, filter, save and reference resolution of the written-round store.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */
#[CoversClass(WrittenRoundStore::class)]
class WrittenRoundStoreTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The store under test.
	 *
	 * @var WrittenRoundStore
	 */
	private WrittenRoundStore $store;

	/**
	 * Build the store over a mocked object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->store         = new WrittenRoundStore(objectService: $this->objectService);
	}//end setUp()

	/**
	 * Wrap an array as an entity double.
	 *
	 * @param array<string, mixed> $data The serialised payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * An empty id is never looked up.
	 *
	 * @return void
	 */
	public function testFindWithEmptyIdReturnsNullWithoutLookup(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertNull($this->store->find(id: '', schema: 'voting-round'));
	}//end testFindWithEmptyIdReturnsNullWithoutLookup()

	/**
	 * A found entity comes back as an array, its id taken from @self when absent.
	 *
	 * @return void
	 */
	public function testFindReturnsArrayWithIdFromSelf(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with(...FindCallShape::findArgs('round-1', [], false, 'decidiq', 'voting-round', false, false))
			->willReturn($this->entity(['title' => 'R1', '@self' => ['id' => 'round-1']]));

		$this->assertSame(
			['title' => 'R1', '@self' => ['id' => 'round-1'], 'id' => 'round-1'],
			$this->store->find(id: 'round-1', schema: 'voting-round')
		);
	}//end testFindReturnsArrayWithIdFromSelf()

	/**
	 * An existing id is kept, and a missing @self id adds nothing.
	 *
	 * @return void
	 */
	public function testFindKeepsExistingIdAndLeavesIdlessRowAlone(): void {
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->entity(['id' => 'own', '@self' => ['id' => 'other']]),
			$this->entity(['title' => 'no id', '@self' => ['id' => '  ']])
		);

		$this->assertSame(['id' => 'own', '@self' => ['id' => 'other']], $this->store->find(id: 'a', schema: 's'));
		$this->assertSame(['title' => 'no id', '@self' => ['id' => '  ']], $this->store->find(id: 'b', schema: 's'));
	}//end testFindKeepsExistingIdAndLeavesIdlessRowAlone()

	/**
	 * A missing object is null.
	 *
	 * @return void
	 */
	public function testFindReturnsNullWhenObjectMissing(): void {
		$this->objectService->expects($this->once())->method('find')->willReturn(null);

		$this->assertNull($this->store->find(id: 'gone', schema: 'voting-round'));
	}//end testFindReturnsNullWhenObjectMissing()

	/**
	 * findAll pages by 500 until a short page, and re-applies the filters itself.
	 *
	 * @return void
	 */
	public function testFindAllPagesAndFiltersRows(): void {
		$fullPage = [];
		for ($i = 0; $i < 500; $i++) {
			$round = 'round-2';
			if ($i === 7) {
				$round = 'round-1';
			}

			$fullPage[] = ['id' => 'b-'.$i, 'round' => $round];
		}

		$calls = [];
		$this->objectService->expects($this->exactly(2))
			->method('findAll')
			->willReturnCallback(
				function (array $config, bool $_rbac, bool $_multitenancy) use (&$calls, $fullPage): array {
					$calls[] = [$config, $_rbac, $_multitenancy];
					if ($config['offset'] === 0) {
						return ['results' => $fullPage];
					}

					return [
						$this->entity(['round' => ['id' => 'round-1'], '@self' => ['id' => 'b-x']]),
						'not a row',
						['round' => 'round-3'],
					];
				}
			);

		$rows = $this->store->findAll(schema: 'written-ballot', filters: ['round' => 'round-1']);

		$this->assertSame(
			[
				['id' => 'b-7', 'round' => 'round-1'],
				['round' => ['id' => 'round-1'], '@self' => ['id' => 'b-x'], 'id' => 'b-x'],
			],
			$rows
		);
		$this->assertSame(
			[
				[['filters' => ['register' => 'decidiq', 'schema' => 'written-ballot', 'round' => 'round-1'], 'limit' => 500, 'offset' => 0], false, false],
				[['filters' => ['register' => 'decidiq', 'schema' => 'written-ballot', 'round' => 'round-1'], 'limit' => 500, 'offset' => 500], false, false],
			],
			$calls
		);
	}//end testFindAllPagesAndFiltersRows()

	/**
	 * Without filters every array row is returned from a single short page.
	 *
	 * @return void
	 */
	public function testFindAllWithoutFiltersReturnsEveryRow(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->willReturn([['id' => 'a'], ['id' => 'b']]);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $this->store->findAll(schema: 's', filters: []));
	}//end testFindAllWithoutFiltersReturnsEveryRow()

	/**
	 * Save returns the stored object as an array.
	 *
	 * @return void
	 */
	public function testSaveReturnsStoredObject(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['title' => 'x'], [], 'decidiq', 'voting-round', null, false, false)
			->willReturn($this->entity(['title' => 'x', '@self' => ['id' => 'new-1']]));

		$this->assertSame(
			['title' => 'x', '@self' => ['id' => 'new-1'], 'id' => 'new-1'],
			$this->store->save(schema: 'voting-round', object: ['title' => 'x'])
		);
	}//end testSaveReturnsStoredObject()

	/**
	 * A stored entity that cannot be serialised falls back to the object given.
	 *
	 * @return void
	 */
	public function testSaveFallsBackToGivenObjectWhenEntityIsNotSerialisable(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->createMock(ObjectEntityInterface::class));

		$this->assertSame(['title' => 'y'], $this->store->save(schema: 'voting-round', object: ['title' => 'y']));
	}//end testSaveFallsBackToGivenObjectWhenEntityIsNotSerialisable()

	/**
	 * The id a reference holds, for strings and every expanded shape.
	 *
	 * @return void
	 */
	public function testIdOfResolvesEveryReferenceShape(): void {
		$this->assertSame('u-1', WrittenRoundStore::idOf(value: 'u-1'));
		$this->assertSame('i-1', WrittenRoundStore::idOf(value: ['id' => 'i-1', 'uuid' => 'x']));
		$this->assertSame('u-2', WrittenRoundStore::idOf(value: ['uuid' => 'u-2']));
		$this->assertSame('s-1', WrittenRoundStore::idOf(value: ['@self' => ['id' => 's-1']]));
		$this->assertNull(WrittenRoundStore::idOf(value: ['title' => 'none']));
		$this->assertNull(WrittenRoundStore::idOf(value: '   '));
		$this->assertNull(WrittenRoundStore::idOf(value: 42));
		$this->assertNull(WrittenRoundStore::idOf(value: null));
	}//end testIdOfResolvesEveryReferenceShape()
}//end class
