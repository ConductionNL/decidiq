<?php

/**
 * Tests for ThemeTimelineBuilder: the timeline of a theme, and what of it is published.
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
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ThemeTimelineBuilder;
use OCA\Decidiq\Service\ThemeTimelineDates;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The swimming pool theme of the municipality example set, with five linked items.
 */
class ThemeTimelineBuilderTest extends TestCase {

	/**
	 * Stored objects by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Stored publication records.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $records = [];

	/**
	 * Lookups that throw, by id.
	 *
	 * @var list<string>
	 */
	private array $broken = [];

	/**
	 * The seeded objects: a meeting, an agenda item on it, a motion, a commitment and a planned item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'm-1' => ['title' => 'Gemeenteraad 14 oktober', 'scheduledDate' => '2026-10-14T19:30:00+02:00'],
			'a-1' => ['title' => 'Kredietaanvraag zwembad', 'meeting' => 'm-1'],
			'd-1' => ['title' => 'Motie duurzaam zwembad', 'decisionDate' => '2026-10-15'],
			'c-1' => ['text' => 'Het college informeert de raad over de aanbesteding', 'deadline' => '2026-12-01', 'publicationDate' => '2026-10-16T00:00:00+00:00'],
			'p-1' => ['subject' => 'Besluit over de aanbesteding', 'plannedPeriod' => '2027-Q1'],
		];
		$this->records = [
			['sourceType' => 'agenda', 'sourceObject' => 'm-1', 'status' => 'published', 'payloadObject' => 'pay-agenda'],
			['sourceType' => 'decision', 'sourceObject' => 'd-1', 'status' => 'withdrawn', 'payloadObject' => 'pay-old'],
			['sourceType' => 'decision', 'sourceObject' => 'd-1', 'status' => 'published', 'payloadObject' => 'pay-motion'],
		];
	}//end setUp()

	/**
	 * The builder over the in-memory store.
	 *
	 * @return ThemeTimelineBuilder The builder.
	 */
	private function builder(): ThemeTimelineBuilder {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (in_array($id, $this->broken, true) === true) {
					throw new \RuntimeException('gone');
				}

				if (isset($this->objects[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($this->objects[$id]);
				return $entity;
			}
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				return array_values(
					array_filter(
						$this->records,
						static fn (array $record): bool => $record['sourceObject'] === $filters['sourceObject'] && $record['sourceType'] === $filters['sourceType']
					)
				);
			}
		);

		return new ThemeTimelineBuilder($service, $this->createMock(LoggerInterface::class));
	}//end builder()

	/**
	 * The theme linking all five.
	 *
	 * @return array<string, mixed> The theme.
	 */
	private static function theme(): array {
		return [
			'title' => 'Nieuw zwembad De Hoge Dijk',
			'plannedAgendaItems' => ['p-1'],
			'commitments' => [['id' => 'c-1']],
			'decisions' => ['d-1', 'd-1'],
			'agendaItems' => 'a-1',
			'meetings' => ['m-1'],
		];
	}//end theme()

	/**
	 * Five entries come out oldest first, each with the date of its kind.
	 *
	 * @return void
	 */
	public function testTheSeededThemeGivesFiveEntriesOldestFirst(): void {
		$entries = $this->builder()->entries(self::theme());

		$this->assertSame(
			[
				['2026-10-14T19:30:00+02:00', 'meeting', 'Gemeenteraad 14 oktober', 'm-1', true, 'pay-agenda'],
				['2026-10-14T19:30:00+02:00', 'agendaItem', 'Kredietaanvraag zwembad', 'a-1', true, 'pay-agenda'],
				['2026-10-15', 'decision', 'Motie duurzaam zwembad', 'd-1', true, 'pay-motion'],
				['2026-12-01', 'commitment', 'Het college informeert de raad over de aanbesteding', 'c-1', true, 'c-1'],
				['2027-01-01', 'plannedAgendaItem', 'Besluit over de aanbesteding', 'p-1', false, null],
			],
			array_map(static fn (array $entry): array => array_values($entry), $entries)
		);
	}//end testTheSeededThemeGivesFiveEntriesOldestFirst()

	/**
	 * The published timeline keeps only the items published themselves.
	 *
	 * @return void
	 */
	public function testThePublishedTimelineKeepsOnlyPublishedItems(): void {
		unset($this->records[0]);
		$this->objects['c-1']['depublicationDate'] = '2026-10-20T00:00:00+00:00';

		$entries = $this->builder()->publishedEntries(self::theme());

		$this->assertSame(
			[['date' => '2026-10-15', 'kind' => 'decision', 'title' => 'Motie duurzaam zwembad', 'objectId' => 'd-1', 'publication' => 'pay-motion']],
			$entries
		);
	}//end testThePublishedTimelineKeepsOnlyPublishedItems()

	/**
	 * Linked objects that are gone or unreadable are left out; undated ones come last.
	 *
	 * @return void
	 */
	public function testMissingObjectsAreLeftOutAndUndatedComeLast(): void {
		$this->broken = ['d-1'];
		$this->objects['c-1']['publicationDate'] = '2999-01-01T00:00:00+00:00';
		unset($this->objects['c-1']['deadline']);

		$entries = $this->builder()->entries(['meetings' => ['m-1', 'gone'], 'decisions' => ['d-1'], 'commitments' => ['c-1', 7, ['title' => 'x']]]);

		$this->assertSame(['m-1', 'c-1'], array_column($entries, 'objectId'));
		$this->assertNull($entries[1]['date']);
		$this->assertFalse($entries[1]['published']);
	}//end testMissingObjectsAreLeftOutAndUndatedComeLast()

	/**
	 * A quarter and a month start on their first day; other dates are kept.
	 *
	 * @return void
	 */
	public function testPeriodsSortByTheirFirstDay(): void {
		$this->assertSame('2027-01-01', (new ThemeTimelineDates())->sortable('2027-Q1'));
		$this->assertSame('2027-10-01', (new ThemeTimelineDates())->sortable('2027Q4'));
		$this->assertSame('2027-05-01', (new ThemeTimelineDates())->sortable('2027-05'));
		$this->assertSame('2027-05-17', (new ThemeTimelineDates())->sortable('2027-05-17'));
		$this->assertNull((new ThemeTimelineDates())->sortable(' '));
		$this->assertNull((new ThemeTimelineDates())->sortable(null));
	}//end testPeriodsSortByTheirFirstDay()

	/**
	 * Publication records that cannot be read count as unpublished.
	 *
	 * @return void
	 */
	public function testUnreadableRecordsCountAsUnpublished(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willThrowException(new \RuntimeException('down'));
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($this->objects['d-1']);
		$service->method('find')->willReturn($entity);

		$builder = new ThemeTimelineBuilder($service, $this->createMock(LoggerInterface::class));

		$this->assertSame([], $builder->publishedEntries(['decisions' => ['d-1']]));
	}//end testUnreadableRecordsCountAsUnpublished()
}//end class
