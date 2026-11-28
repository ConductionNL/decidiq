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

use OCA\Decidiq\Service\ClosedAgendaItemNumbers;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the agenda learns about a closed item it cannot read: its number,
 * and its parent only when that parent is readable.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
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
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$found   = null;
		if ($meeting === true) {
			$found = $this->createMock(originalClassName: ObjectEntityInterface::class);
		}

		$objects->method('find')->willReturn($found);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true) use ($asCaller, $all): array {
				$this->reads[] = [$_rbac, $_multitenancy, $config['filters']];
				if ($_rbac === true) {
					return $asCaller;
				}

				return ['results' => $all];
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
		$open   = $this->item(id: 'a', number: 1);
		$closed = $this->item(id: 'b', number: 2, extra: ['public' => false, 'title' => 'Aankoop percelen Lindelaan']);

		$result = $this->service(asCaller: [$open], all: [$open, $closed])->forMeeting(meetingId: 'm-1');

		$this->assertSame(expected: [['orderNumber' => 2, 'parentItem' => null]], actual: $result);
		$this->assertStringNotContainsString(needle: 'Lindelaan', haystack: json_encode(value: $result));
		$this->assertStringNotContainsString(needle: '"b"', haystack: json_encode(value: $result));
		$this->assertSame(expected: [true, true], actual: [$this->reads[0][0], $this->reads[0][1]], message: 'the first read applies the caller rules');
		$this->assertSame(expected: [false, false], actual: [$this->reads[1][0], $this->reads[1][1]], message: 'the second read sees every item');
		$this->assertSame(expected: 'm-1', actual: $this->reads[1][2]['meeting']);
	}//end testAHiddenClosedItemIsListedByNumberOnly()

	/**
	 * A closed item the caller can read, and a public item, are never listed.
	 *
	 * @return void
	 */
	public function testReadableAndPublicItemsAreNotListed(): void {
		$closed = $this->item(id: 'b', number: 2, extra: ['public' => false]);
		$public = $this->item(id: 'c', number: 3, extra: ['public' => true]);
		$legacy = $this->item(id: 'd', number: 4);

		$result = $this->service(asCaller: [$closed], all: [$closed, $public, $legacy])->forMeeting(meetingId: 'm-1');

		$this->assertSame(expected: [], actual: $result);
	}//end testReadableAndPublicItemsAreNotListed()

	/**
	 * A closed sub-item names a readable parent and is left out under a hidden one.
	 *
	 * @return void
	 */
	public function testASubItemNamesOnlyAReadableParent(): void {
		$parent       = $this->item(id: 'p', number: 1);
		$hiddenParent = $this->item(id: 'q', number: 3, extra: ['public' => false]);
		$subOfOpen    = $this->item(id: 's1', number: 2, extra: ['public' => false, 'parentItem' => ['id' => 'p']]);
		$subOfHidden  = $this->item(id: 's2', number: 4, extra: ['public' => false, 'parentItem' => 'q']);

		$result = $this->service(asCaller: [$parent], all: [$subOfHidden, $hiddenParent, $subOfOpen, $parent])->forMeeting(meetingId: 'm-1');

		$this->assertSame(
			expected: [
				['orderNumber' => 2, 'parentItem' => 'p'],
				['orderNumber' => 3, 'parentItem' => null],
			],
			actual: $result
		);
	}//end testASubItemNamesOnlyAReadableParent()

	/**
	 * Rows of another meeting, rows without an id and entities are handled.
	 *
	 * @return void
	 */
	public function testRowsOfAnotherMeetingAreIgnoredAndEntitiesAreRead(): void {
		$other  = $this->item(id: 'x', number: 1, extra: ['public' => false, 'meeting' => 'm-2']);
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

		$result = $this->service(asCaller: ['not a row'], all: [$other, $noId, $entity, 42])->forMeeting(meetingId: 'm-1');

		$this->assertSame(expected: [['orderNumber' => 5, 'parentItem' => null]], actual: $result);
	}//end testRowsOfAnotherMeetingAreIgnoredAndEntitiesAreRead()

	/**
	 * Only a meeting the caller reads is authorized, and authorizing reads no item.
	 *
	 * @return void
	 */
	public function testOnlyAReadableMeetingIsAuthorized(): void {
		$closed = $this->item(id: 'b', number: 2, extra: ['public' => false]);

		$this->assertFalse(condition: $this->service(asCaller: [], all: [$closed], meeting: false)->authorizeMeetingRead(meetingId: 'm-1'));
		$this->assertTrue(condition: $this->service(asCaller: [], all: [$closed])->authorizeMeetingRead(meetingId: 'm-1'));
		$this->assertSame(expected: [], actual: $this->reads, message: 'authorizing reads no item');
	}//end testOnlyAReadableMeetingIsAuthorized()
}//end class
