<?php

/**
 * Tests for SystemObjectStore: one decidiq object read or written in system context.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SystemObjectStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\SystemObjectStore
 */
class SystemObjectStoreTest extends TestCase {

	/**
	 * An empty id reads nothing and asks OpenRegister nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsNull(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('find');

		self::assertNull(SystemObjectStore::read(objectService: $objects, schema: 'meeting', id: ''));
		self::assertNull(SystemObjectStore::readData(objectService: $objects, schema: 'meeting', id: ''));
	}//end testAnEmptyIdIsNull()

	/**
	 * The read goes to the decidiq register and schema, past RBAC and multitenancy, and returns the serialised object.
	 *
	 * @return void
	 */
	public function testReadReturnsTheSerialisedObjectInSystemContext(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('find')->with(
			'm-1',
			self::callback(
				static fn (ObjectFindOptions $o): bool => $o->register === 'decidiq' && $o->schema === 'meeting'
			),
			false,
			false
		)->willReturn($this->entity(data: ['id' => 'm-1', '@self' => ['x' => 1], 'title' => 'Raad']));

		self::assertSame(
			['id' => 'm-1', '@self' => ['x' => 1], 'title' => 'Raad'],
			SystemObjectStore::read(objectService: $objects, schema: 'meeting', id: 'm-1')
		);
	}//end testReadReturnsTheSerialisedObjectInSystemContext()

	/**
	 * readData() drops the id and @self keys; an unknown object is null.
	 *
	 * @return void
	 */
	public function testReadDataDropsIdAndSelf(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntity => $id === 'm-1' ? $this->entity(data: ['id' => 'm-1', '@self' => [], 'title' => 'Raad']) : null
		);

		self::assertSame(['title' => 'Raad'], SystemObjectStore::readData(objectService: $objects, schema: 'meeting', id: 'm-1'));
		self::assertNull(SystemObjectStore::readData(objectService: $objects, schema: 'meeting', id: 'gone'));
	}//end testReadDataDropsIdAndSelf()

	/**
	 * The write targets the decidiq register and schema with the uuid, in system
	 * context, and returns the stored object with its uuid as id and without @self.
	 *
	 * @return void
	 */
	public function testWriteReturnsTheStoredObjectWithItsUuid(): void {
		$saved = $this->entity(data: ['@self' => ['owner' => 'x'], 'title' => 'Dossier'], uuid: 'd-9');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('saveObject')->with(
			['title' => 'Dossier'],
			self::callback(
				static fn (ObjectSaveOptions $o): bool => $o->target->register === 'decidiq'
					&& $o->target->schema === 'archival-dossier'
					&& $o->uuid === 'd-9'
			),
			false,
			false
		)->willReturn($saved);

		self::assertSame(
			['id' => 'd-9', 'title' => 'Dossier'],
			SystemObjectStore::write(objectService: $objects, schema: 'archival-dossier', object: ['title' => 'Dossier'], uuid: 'd-9')
		);
	}//end testWriteReturnsTheStoredObjectWithItsUuid()

	/**
	 * An ObjectEntity double serialising to the data, with the uuid.
	 *
	 * @param array<string, mixed> $data The serialised object.
	 * @param string|null          $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, ?string $uuid=null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()
}//end class
