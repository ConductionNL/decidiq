<?php

/**
 * Unit tests for the closed agenda item numbers (agenda-item-confidential-papers).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\ClosedAgendaItemNumbers;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the agenda learns about a closed item it cannot read: its number,
 * and its parent only when that parent is readable.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
class ClosedAgendaItemNumbersTest extends TestCase {

	/**
	 * The findAll calls made, as [rbac, multitenancy, filters].
	 *
	 * @var array<int, array{0: bool, 1: bool, 2: array<string, mixed>}>
	 */
	private array $reads = [];

	/**
	 * Build the service over the caller's rows and every row.
	 *
	 * @param array<int, mixed> $asCaller Rows the caller's read rules let through.
	 * @param array<int, mixed> $all      Every row of the meeting.
	 * @param bool              $meeting  Whether the caller can read the meeting.
	 *
	 * @return ClosedAgendaItemNumbers
	 */
	private function service(array $asCaller, array $all, bool $meeting=true): ClosedAgendaItemNumbers {
		$this->reads = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($meeting === true ? $this->createMock(ObjectEntityInterface::class) : null);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true) use ($asCaller, $all): array {
				$this->reads[] = [$_rbac, $_multitenancy, $config['filters']];
				return $_rbac === true ? $asCaller : ['results' => $all];
			}
		);

		return new ClosedAgendaItemNumbers(objectService: $objects);
	}//end service()

	/**
	 * An agenda item row.
	 *
	 * @param string               $id     Its id.
	 * @param int                  $number Its order number.
	 * @param array<string, mixed> $extra  More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function item(string $id, int $number, array $extra=[]): array {
		return array_merge(['id' => $id, 'meeting' => 'm-1', 'orderNumber' => $number, 'title' => 'Item ' . $id], $extra);
	}//end item()

	/**
	 * A closed item the caller cannot read is listed by number only.
	 *
	 * @return void
	 */
	public function testAHiddenClosedItemIsListedByNumberOnly(): void {
		$open   = $this->item('a', 1);
		$closed = $this->item('b', 2, ['public' => false, 'title' => 'Aankoop percelen Lindelaan']);

		$result = $this->service(asCaller: [$open], all: [$open, $closed])->forMeeting('m-1');

		$this->assertSame([['orderNumber' => 2, 'parentItem' => null]], $result);
		$this->assertStringNotContainsString('Lindelaan', json_encode($result));
		$this->assertStringNotContainsString('"b"', json_encode($result));
		$this->assertSame([true, true], array_slice($this->reads[0], 0, 2), 'the first read applies the caller rules');
		$this->assertSame([false, false], array_slice($this->reads[1], 0, 2), 'the second read sees every item');
		$this->assertSame('m-1', $this->reads[1][2]['meeting']);
	}//end testAHiddenClosedItemIsListedByNumberOnly()

	/**
	 * A closed item the caller can read, and a public item, are never listed.
	 *
	 * @return void
	 */
	public function testReadableAndPublicItemsAreNotListed(): void {
		$closed = $this->item('b', 2, ['public' => false]);
		$public = $this->item('c', 3, ['public' => true]);
		$legacy = $this->item('d', 4);

		$result = $this->service(asCaller: [$closed], all: [$closed, $public, $legacy])->forMeeting('m-1');

		$this->assertSame([], $result);
	}//end testReadableAndPublicItemsAreNotListed()

	/**
	 * A closed sub-item names a readable parent and is left out under a hidden one.
	 *
	 * @return void
	 */
	public function testASubItemNamesOnlyAReadableParent(): void {
		$parent       = $this->item('p', 1);
		$hiddenParent = $this->item('q', 3, ['public' => false]);
		$subOfOpen    = $this->item('s1', 2, ['public' => false, 'parentItem' => ['id' => 'p']]);
		$subOfHidden  = $this->item('s2', 4, ['public' => false, 'parentItem' => 'q']);

		$result = $this->service(asCaller: [$parent], all: [$subOfHidden, $hiddenParent, $subOfOpen, $parent])->forMeeting('m-1');

		$this->assertSame(
			[
				['orderNumber' => 2, 'parentItem' => 'p'],
				['orderNumber' => 3, 'parentItem' => null],
			],
			$result
		);
	}//end testASubItemNamesOnlyAReadableParent()

	/**
	 * Rows of another meeting, rows without an id and entities are handled.
	 *
	 * @return void
	 */
	public function testRowsOfAnotherMeetingAreIgnoredAndEntitiesAreRead(): void {
		$other  = $this->item('x', 1, ['public' => false, 'meeting' => 'm-2']);
		$noId   = ['meeting' => 'm-1', 'orderNumber' => 2, 'public' => false];
		$entity = new class () implements \JsonSerializable {
			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'e'], 'meeting' => ['id' => 'm-1'], 'orderNumber' => '5', 'public' => false];
			}
		};

		$result = $this->service(asCaller: ['not a row'], all: [$other, $noId, $entity, 42])->forMeeting('m-1');

		$this->assertSame([['orderNumber' => 5, 'parentItem' => null]], $result);
	}//end testRowsOfAnotherMeetingAreIgnoredAndEntitiesAreRead()

	/**
	 * Who cannot read the meeting learns nothing about its agenda.
	 *
	 * @return void
	 */
	public function testAnUnreadableMeetingIsRefusedBeforeAnyItemIsRead(): void {
		$service = $this->service(asCaller: [], all: [$this->item('b', 2, ['public' => false])], meeting: false);

		try {
			$service->forMeeting('m-1');
			$this->fail('expected MissingObjectException');
		} catch (MissingObjectException $e) {
			$this->assertSame('Meeting not found.', $e->getMessage());
		}

		$this->assertSame([], $this->reads);
	}//end testAnUnreadableMeetingIsRefusedBeforeAnyItemIsRead()
}//end class
