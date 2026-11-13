<?php

/**
 * How one audit-trail entry becomes a row, and how rows are paged, exported and verified.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\Decidiq\Service\AuditRowFormat;
use OCA\OpenRegister\Db\AuditTrail;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\AuditRowFormat
 * @uses   \OCA\Decidiq\Service\CsvCell
 */
class AuditRowFormatTest extends TestCase {

	/**
	 * An entry with every field set.
	 *
	 * @param array|null $changed The changed block.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(?array $changed): AuditTrail {
		$entry = new AuditTrail();
		$entry->setId(7);
		$entry->setUuid('trail-7');
		$entry->setAction('decidiq.audit.vote.cast');
		$entry->setChanged($changed);
		$entry->setUser('session-user');
		$entry->setObjectUuid('obj-1');
		$entry->setHash('h7');
		$entry->setPreviousHash('h6');
		$entry->setCreated(new DateTime('2026-09-01T12:30:00', new DateTimeZone('Europe/Amsterdam')));

		return $entry;
	}//end entry()

	/**
	 * The changed block's actor and object ids win; the action loses its namespace; time is UTC.
	 *
	 * @return void
	 */
	public function testARowTakesTheActorAndObjectsFromTheChangedBlock(): void {
		$row = (new AuditRowFormat())->mapRow(
			$this->entry(['actorUuid' => 'member-3', 'objectUids' => ['a', 5], 'payload' => ['choice' => 'yes']])
		);

		self::assertSame(
			[
				'id' => 'trail-7',
				'actorUuid' => 'member-3',
				'action' => 'vote.cast',
				'objectUids' => ['a', '5'],
				'timestamp' => '2026-09-01T10:30:00Z',
				'payload' => ['choice' => 'yes'],
				'currentHash' => 'h7',
				'previousHash' => 'h6',
			],
			$row
		);
	}//end testARowTakesTheActorAndObjectsFromTheChangedBlock()

	/**
	 * Without a changed block the row falls back to the entry's own user, object and id.
	 *
	 * @return void
	 */
	public function testWithoutAChangedBlockTheEntryItselfIsUsed(): void {
		$entry = new AuditTrail();
		$entry->setId(9);
		$entry->setAction('openregister.create');
		$entry->setUser('admin');
		$entry->setObjectUuid('obj-9');

		$row = (new AuditRowFormat())->mapRow($entry);

		self::assertSame('9', $row['id']);
		self::assertSame('admin', $row['actorUuid']);
		self::assertSame('openregister.create', $row['action'], 'a foreign action keeps its full name');
		self::assertSame(['obj-9'], $row['objectUids']);
		self::assertSame('', $row['timestamp']);
		self::assertSame([], $row['payload']);
		self::assertSame('', $row['currentHash']);
	}//end testWithoutAChangedBlockTheEntryItselfIsUsed()

	/**
	 * An empty object list in the changed block falls back to the entry's object; none gives none.
	 *
	 * @return void
	 */
	public function testAnEmptyObjectListFallsBackToTheEntryObject(): void {
		$format = new AuditRowFormat();

		self::assertSame(['obj-1'], $format->mapRow($this->entry(['objectUids' => []]))['objectUids']);

		$bare = new AuditTrail();
		$bare->setId(1);
		self::assertSame([], $format->mapRow($bare)['objectUids']);
	}//end testAnEmptyObjectListFallsBackToTheEntryObject()

	/**
	 * The period is inclusive at both ends.
	 *
	 * @return void
	 */
	public function testInPeriodKeepsRowsWithinBothBoundsInclusive(): void {
		$chain = [
			['id' => 'a', 'timestamp' => '2026-01-01T00:00:00Z'],
			['id' => 'b', 'timestamp' => '2026-02-01T00:00:00Z'],
			['id' => 'c', 'timestamp' => '2026-03-01T00:00:00Z'],
			['id' => 'd'],
		];

		$kept = (new AuditRowFormat())->inPeriod($chain, ['2026-02-01T00:00:00Z', '2026-03-01T00:00:00Z']);

		self::assertSame(['b', 'c'], array_column($kept, 'id'));
	}//end testInPeriodKeepsRowsWithinBothBoundsInclusive()

	/**
	 * A CSV export has a header, one neutralised line per row and joins object ids with a pipe.
	 *
	 * @return void
	 */
	public function testCsvExportWritesAHeaderAndOneSafeLinePerRow(): void {
		$result = (new AuditRowFormat())->exportResult(
			'csv',
			[
				[
					'id' => 'r1',
					'timestamp' => 't1',
					'actorUuid' => '=cmd()',
					'action' => 'vote, cast',
					'objectUids' => ['a', 'b'],
					'previousHash' => '',
					'currentHash' => 'h1',
				],
			]
		);

		self::assertTrue($result['success']);
		self::assertSame('csv', $result['format']);
		self::assertSame(1, $result['count']);
		self::assertSame(
			"id,timestamp,actor,action,objectUids,previousHash,currentHash\nr1,t1,'=cmd(),\"vote, cast\",\"a|b\",,h1",
			$result['body']
		);
	}//end testCsvExportWritesAHeaderAndOneSafeLinePerRow()

	/**
	 * Any other format exports JSON with slashes and unicode left as they are.
	 *
	 * @return void
	 */
	public function testOtherFormatsExportJson(): void {
		$result = (new AuditRowFormat())->exportResult('json', [['id' => 'r/1', 'actorUuid' => 'José']]);

		self::assertSame('{"entries":[{"id":"r/1","actorUuid":"José"}]}', $result['body']);
		self::assertSame(1, $result['count']);
		self::assertSame(
			['success' => false, 'format' => 'csv', 'body' => '', 'count' => 0],
			(new AuditRowFormat())->exportFailure('csv')
		);
	}//end testOtherFormatsExportJson()

	/**
	 * Unencodable rows export an empty JSON object.
	 *
	 * @return void
	 */
	public function testUnencodableRowsExportAnEmptyObject(): void {
		$result = (new AuditRowFormat())->exportResult('json', [['id' => "\xB1\x31"]]);

		self::assertSame('{}', $result['body']);
	}//end testUnencodableRowsExportAnEmptyObject()

	/**
	 * A page honours limit and offset, counts every row, and a non-positive limit means 100.
	 *
	 * @return void
	 */
	public function testQueryPageSlicesAndCountsAll(): void {
		$rows = array_map(static fn (int $i): array => ['id' => $i], range(1, 150));
		$format = new AuditRowFormat();

		$page = $format->queryPage($rows, ['limit' => '2', 'offset' => '3']);
		self::assertSame([['id' => 4], ['id' => 5]], $page['entries']);
		self::assertSame(150, $page['count']);
		self::assertTrue($page['success']);

		self::assertCount(100, $format->queryPage($rows, ['limit' => 0])['entries']);
		self::assertCount(100, $format->queryPage($rows, [])['entries']);
	}//end testQueryPageSlicesAndCountsAll()

	/**
	 * A broken chain names the entry it broke at; an intact one names none.
	 *
	 * @return void
	 */
	public function testVerificationNamesTheBrokenEntry(): void {
		$format = new AuditRowFormat();

		self::assertSame(
			['valid' => false, 'checked' => 4, 'tampered' => ['12']],
			$format->verification(['valid' => false, 'entriesVerified' => 4, 'brokenAt' => 12])
		);
		self::assertSame(
			['valid' => true, 'checked' => 9, 'tampered' => []],
			$format->verification(['valid' => true, 'entriesVerified' => 9])
		);
		self::assertSame(['valid' => false, 'checked' => 0, 'tampered' => []], $format->verification([]));
		self::assertSame(['valid' => false, 'checked' => 0, 'tampered' => []], $format->notVerified());
	}//end testVerificationNamesTheBrokenEntry()
}//end class
