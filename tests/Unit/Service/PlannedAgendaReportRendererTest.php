<?php

/**
 * Tests for the long-term agenda report document.
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

use OCA\Decidiq\Service\PlannedAgendaReportRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\PlannedAgendaReportRenderer
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportRendererTest extends TestCase {

	/**
	 * A topic leads its tracks, tracks follow by period, and groups order by their first period.
	 *
	 * @return void
	 */
	public function testGroupsTracksUnderTheirTopicByPeriod(): void {
		$rows = [
			['id' => 'res', 'subject' => 'RES 2.0', 'parentItem' => 'topic', 'plannedPeriod' => '2027-Q1'],
			['id' => 'solo', 'subject' => 'Standalone', 'plannedPeriod' => '2026-Q1'],
			['id' => 'warmte', 'subject' => 'Warmtevisie', 'parentItem' => 'topic', 'plannedPeriod' => '2026-Q4'],
			['id' => 'topic', 'subject' => 'Energietransitie', 'plannedPeriod' => '2026-Q3'],
			['id' => 'undated', 'subject' => 'Undated'],
		];

		$groups = (new PlannedAgendaReportRenderer())->group(rows: $rows);

		self::assertSame(['Standalone', 'Energietransitie', 'Undated'], array_column($groups, 'heading'));
		self::assertSame(['topic', 'warmte', 'res'], array_column($groups[1]['rows'], 'id'));
	}//end testGroupsTracksUnderTheirTopicByPeriod()

	/**
	 * A track whose topic is outside the rows groups under the topic's known name; an unknown topic leaves it alone.
	 *
	 * @return void
	 */
	public function testTracksOfATopicOutsideTheRows(): void {
		$rows = [
			['id' => 'a', 'subject' => 'Track A', 'parentItem' => 'known', 'plannedPeriod' => '2026-Q2'],
			['id' => 'b', 'subject' => 'Track B', 'parentItem' => 'known', 'plannedPeriod' => '2026-Q1'],
			['id' => 'c', 'subject' => 'Track C', 'parentItem' => 'hidden', 'plannedPeriod' => '2026-Q3'],
		];

		$groups = (new PlannedAgendaReportRenderer())->group(rows: $rows, topics: ['known' => 'Known topic']);

		self::assertSame(['Known topic', 'Track C'], array_column($groups, 'heading'));
		self::assertSame(['b', 'a'], array_column($groups[0]['rows'], 'id'));
	}//end testTracksOfATopicOutsideTheRows()

	/**
	 * The heading names the filters in plain words, references by name.
	 *
	 * @return void
	 */
	public function testDescribeFiltersInPlainWords(): void {
		self::assertSame(
			'Portfolio holder: De Boer, status: planned',
			(new PlannedAgendaReportRenderer())->describeFilters(filters: ['lifecycle' => 'planned', 'owner' => 'p1'], names: ['p1' => 'De Boer'])
		);
		self::assertSame(
			'Author: S. Visser or unknown-id, topics only',
			(new PlannedAgendaReportRenderer())->describeFilters(filters: ['author' => ['p2', 'unknown-id'], 'parentItem' => 'IS NULL', 'custom' => 'x'], names: ['p2' => 'S. Visser'])
		);
		self::assertSame('No filters: every item you may read.', (new PlannedAgendaReportRenderer())->describeFilters(filters: ['owner' => '']));
	}//end testDescribeFiltersInPlainWords()

	/**
	 * The document carries the title, filters, date and a row per item, escaped, with unknown references as a dash.
	 *
	 * @return void
	 */
	public function testHtmlEscapesAndResolvesNames(): void {
		$groups = [['heading' => 'Energie & <klimaat>', 'rows' => [
			['id' => 'x', 'subject' => 'A "quoted" item', 'governanceBody' => 'b1', 'plannedPeriod' => '2026-Q4', 'owner' => 'p1', 'author' => 'hidden', 'lifecycle' => 'planned'],
		]]];

		$html = (new PlannedAgendaReportRenderer())->html(groups: $groups, filters: 'Status: planned', date: '14 November 2026', names: ['b1' => 'Raad', 'p1' => 'De Boer']);

		self::assertStringContainsString('<h1>Long-term agenda</h1>', $html);
		self::assertStringContainsString('<p class="filters">Status: planned</p>', $html);
		self::assertStringContainsString('<p class="date">14 November 2026</p>', $html);
		self::assertStringContainsString('<h2>Energie &amp; &lt;klimaat&gt;</h2>', $html);
		self::assertStringContainsString('<tr><td>A &quot;quoted&quot; item</td><td>Raad</td><td>2026-Q4</td><td>De Boer</td><td>—</td><td>planned</td></tr>', $html);
		self::assertStringNotContainsString('hidden', $html);
	}//end testHtmlEscapesAndResolvesNames()
}//end class
