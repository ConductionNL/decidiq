<?php

/**
 * Unit tests for ClosedAgendaItemRestamp.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ClosedAgendaItemRestamp;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Only a non-public item with a meeting and without a body is saved again.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
 */
class ClosedAgendaItemRestampTest extends TestCase {

	/**
	 * A stored item.
	 *
	 * @param string               $uuid    Its id.
	 * @param array<string, mixed> $payload Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function item(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end item()

	/**
	 * The closed items without a body are saved unchanged, as the system; the rest are skipped.
	 *
	 * @return void
	 */
	public function testOnlyClosedItemsWithoutABodyAreSavedAgain(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects(self::once())->method('findAll')
			->with(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'agenda-item', 'public' => false], 'limit' => 5000],
				_rbac: false,
				_multitenancy: false
			)
			->willReturn([
				self::item(uuid: 'item-1', payload: ['title' => 'Grondaankoop', 'meeting' => 'meeting-1', 'public' => false]),
				self::item(uuid: 'item-2', payload: ['title' => 'Al gestempeld', 'meeting' => 'meeting-1', 'public' => false, 'governanceBody' => 'body-1']),
				self::item(uuid: 'item-3', payload: ['title' => 'Zonder vergadering', 'public' => false]),
				self::item(uuid: 'item-4', payload: ['title' => 'Openbaar', 'meeting' => 'meeting-1', 'public' => true]),
			]);
		$saved = [];
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac=true, bool $_multitenancy=true) use (&$saved): ObjectEntity {
				$saved[] = [(string)$options->uuid, (string)$options->target->schema, $object, $_rbac, $_multitenancy];
				return new ObjectEntity();
			}
		);

		$count = (new ClosedAgendaItemRestamp(objectService: $objects))->restamp();

		self::assertSame(expected: 1, actual: $count);
		self::assertSame(
			expected: [['item-1', 'agenda-item', ['id' => 'item-1', 'title' => 'Grondaankoop', 'meeting' => 'meeting-1', 'public' => false], false, false]],
			actual: $saved
		);
	}//end testOnlyClosedItemsWithoutABodyAreSavedAgain()
}//end class
