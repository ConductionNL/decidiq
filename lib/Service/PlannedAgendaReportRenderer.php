<?php

/**
 * Decidiq PlannedAgendaReportRenderer
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Turns the long-term agenda rows a report holds into the HTML document
 * filinq renders: tracks grouped under their topic, groups and tracks ordered
 * by planned period, headed with the filters in plain words and the date.
 *
 * Pure, and without state: every name it prints is passed in, so it never reads anything and a
 * reference it was not given a name for prints as a dash rather than as an id.
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportRenderer {
	/**
	 * The report's title.
	 */
	public const TITLE = 'Long-term agenda';

	/**
	 * The filters the report heading names, in the order it names them.
	 */
	public const FILTER_LABELS = [
		'owner'          => 'Portfolio holder',
		'author'         => 'Author',
		'lifecycle'      => 'Status',
		'governanceBody' => 'Governance body',
		'plannedPeriod'  => 'Planned period',
		'expectedType'   => 'Expected type',
		'ownerType'      => 'Owner type',
		'parentItem'     => 'Topic',
	];

	/**
	 * The filters whose values are references, printed by the name they resolve to.
	 */
	public const REFERENCE_FILTERS = ['owner', 'author', 'governanceBody', 'parentItem'];

	/**
	 * The value OpenRegister reads as "this field is empty".
	 */
	public const EMPTY_FILTER = 'IS NULL';

	/**
	 * The rows grouped by topic: each group a heading and its rows.
	 *
	 * A topic in the rows leads its group, followed by its tracks. A track whose
	 * topic is not in the rows but whose name is known groups under that name.
	 * A track whose topic the reader may not see stands alone, so the report
	 * never names an item the list would hide. Groups are ordered by their
	 * first period, tracks by their own.
	 *
	 * @param list<array<string,mixed>> $rows   The rows the filters selected.
	 * @param array<string,string>      $topics Topic names by id, for topics outside the rows.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return list<array{heading:string,rows:list<array<string,mixed>>}>
	 */
	public function group(array $rows, array $topics=[]): array {
		$byId = [];
		foreach ($rows as $row) {
			$byId[self::idFrom(row: $row)] = $row;
		}

		$groups = [];
		foreach ($rows as $row) {
			$key = self::groupKey(row: $row, byId: $byId, topics: $topics);
			if (isset($groups[$key]) === false) {
				$groups[$key] = ['heading' => self::heading(key: $key, row: $row, byId: $byId, topics: $topics), 'rows' => []];
			}

			$groups[$key]['rows'][] = $row;
		}

		foreach ($groups as $key => $group) {
			$groups[$key]['rows'] = self::ordered(rows: $group['rows'], topicId: (string)$key);
		}

		$groups = array_values($groups);
		usort(
			$groups,
			static fn (array $a, array $b): int => self::comparePeriods(
				a: (string)($a['rows'][0]['plannedPeriod'] ?? ''),
				b: (string)($b['rows'][0]['plannedPeriod'] ?? '')
			)
		);

		return $groups;
	}//end group()

	/**
	 * The filters in plain words: "Portfolio holder: De Boer, status: planned".
	 *
	 * @param array<string,mixed>  $filters The filters the report was asked for.
	 * @param array<string,string> $names   Names by id, for reference filters.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return string The heading line, or a sentence saying nothing was filtered.
	 */
	public function describeFilters(array $filters, array $names=[]): string {
		$parts = [];
		foreach (self::FILTER_LABELS as $key => $label) {
			if (array_key_exists($key, $filters) === false) {
				continue;
			}

			$part = self::describeFilter(key: $key, label: $label, value: $filters[$key], names: $names);
			if ($part !== '') {
				$parts[] = $part;
			}
		}

		if ($parts === []) {
			return 'No filters: every item you may read.';
		}

		$first = array_shift($parts);
		$rest  = array_map(static fn (string $part): string => lcfirst($part), $parts);

		return implode(', ', array_merge([$first], $rest));
	}//end describeFilters()

	/**
	 * The HTML document.
	 *
	 * @param list<array{heading:string,rows:list<array<string,mixed>>}> $groups  The grouped rows.
	 * @param string                                                     $filters The filters in plain words.
	 * @param string                                                     $date    The date the report was made.
	 * @param array<string,string>                                       $names   Names by id, for the reference columns.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return string
	 */
	public function html(array $groups, string $filters, string $date, array $names=[]): string {
		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . self::escape(text: self::TITLE) . '</title></head><body>';
		$html .= '<h1>' . self::escape(text: self::TITLE) . '</h1>';
		$html .= '<p class="filters">' . self::escape(text: $filters) . '</p>';
		$html .= '<p class="date">' . self::escape(text: $date) . '</p>';

		foreach ($groups as $group) {
			$html .= '<h2>' . self::escape(text: $group['heading']) . '</h2>';
			$html .= '<table><thead><tr><th>Subject</th><th>Governance body</th><th>Planned period</th>'
				. '<th>Portfolio holder</th><th>Author</th><th>Status</th></tr></thead><tbody>';
			foreach ($group['rows'] as $row) {
				$html .= self::rowHtml(row: $row, names: $names);
			}

			$html .= '</tbody></table>';
		}

		return $html . '</body></html>';
	}//end html()

	/**
	 * A row's id.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
	 *
	 * @return string
	 */
	public function idOf(array $row): string {
		return self::idFrom(row: $row);
	}//end idOf()

	/**
	 * A row's id, for the static helpers.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idFrom(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? $row['uuid'] ?? '');
	}//end idFrom()

	/**
	 * The group a row belongs to: its topic's id, or its own.
	 *
	 * @param array<string,mixed>                $row    The row.
	 * @param array<string,array<string,mixed>> $byId   The rows by id.
	 * @param array<string,string>               $topics Known topic names by id.
	 *
	 * @return string
	 */
	private static function groupKey(array $row, array $byId, array $topics): string {
		$parent = (string)($row['parentItem'] ?? '');
		if ($parent !== '' && (isset($byId[$parent]) === true || isset($topics[$parent]) === true)) {
			return $parent;
		}

		return self::idFrom(row: $row);
	}//end groupKey()

	/**
	 * A group's heading: the topic's subject.
	 *
	 * @param string                             $key    The group's key.
	 * @param array<string,mixed>                $row    The row that opened the group.
	 * @param array<string,array<string,mixed>> $byId   The rows by id.
	 * @param array<string,string>               $topics Known topic names by id.
	 *
	 * @return string
	 */
	private static function heading(string $key, array $row, array $byId, array $topics): string {
		if (isset($byId[$key]) === true) {
			return (string)($byId[$key]['subject'] ?? '');
		}

		return (string)($topics[$key] ?? ($row['subject'] ?? ''));
	}//end heading()

	/**
	 * A group's rows: the topic first, then its tracks by period.
	 *
	 * @param list<array<string,mixed>> $rows    The group's rows.
	 * @param string                    $topicId The group's topic.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function ordered(array $rows, string $topicId): array {
		usort(
			$rows,
			static function (array $a, array $b) use ($topicId): int {
				$aTopic = (self::idFrom(row: $a) === $topicId);
				$bTopic = (self::idFrom(row: $b) === $topicId);
				if ($aTopic !== $bTopic) {
					return ($bTopic <=> $aTopic);
				}

				return self::comparePeriods(a: (string)($a['plannedPeriod'] ?? ''), b: (string)($b['plannedPeriod'] ?? ''));
			}
		);

		return $rows;
	}//end ordered()

	/**
	 * Earlier periods first; an item without a period last.
	 *
	 * @param string $a One period.
	 * @param string $b The other.
	 *
	 * @return int
	 */
	private static function comparePeriods(string $a, string $b): int {
		if ($a === '' || $b === '') {
			return (($a === '') <=> ($b === ''));
		}

		return strcmp($a, $b);
	}//end comparePeriods()

	/**
	 * One filter in plain words.
	 *
	 * @param string               $key   The filter's key.
	 * @param string               $label Its label.
	 * @param mixed                $value Its value or values.
	 * @param array<string,string> $names Names by id.
	 *
	 * @return string The part, or empty when the filter selects nothing.
	 */
	private static function describeFilter(string $key, string $label, mixed $value, array $names): string {
		if ($key === 'parentItem' && $value === self::EMPTY_FILTER) {
			return 'Topics only';
		}

		$values = array_values(array_filter(array_map('strval', (array)$value), static fn (string $v): bool => $v !== ''));
		if ($values === []) {
			return '';
		}

		if (in_array($key, self::REFERENCE_FILTERS, true) === true) {
			$values = array_map(static fn (string $v): string => ($names[$v] ?? $v), $values);
		}

		return $label . ': ' . implode(' or ', $values);
	}//end describeFilter()

	/**
	 * One table row.
	 *
	 * @param array<string,mixed>  $row   The item.
	 * @param array<string,string> $names Names by id.
	 *
	 * @return string
	 */
	private static function rowHtml(array $row, array $names): string {
		$cells = [
			(string)($row['subject'] ?? ''),
			self::nameOf(id: $row['governanceBody'] ?? null, names: $names),
			(string)($row['plannedPeriod'] ?? ''),
			self::nameOf(id: $row['owner'] ?? null, names: $names),
			self::nameOf(id: $row['author'] ?? null, names: $names),
			(string)($row['lifecycle'] ?? ''),
		];

		$html = '<tr>';
		foreach ($cells as $cell) {
			if ($cell === '') {
				$cell = '—';
			}

			$html .= '<td>' . self::escape(text: $cell) . '</td>';
		}

		return $html . '</tr>';
	}//end rowHtml()

	/**
	 * A reference's name, or empty when it is unset or not known.
	 *
	 * @param mixed                $id    The reference.
	 * @param array<string,string> $names Names by id.
	 *
	 * @return string
	 */
	private static function nameOf(mixed $id, array $names): string {
		if (is_string($id) === false || $id === '') {
			return '';
		}

		return (string)($names[$id] ?? '');
	}//end nameOf()

	/**
	 * Escaped for HTML.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private static function escape(string $text): string {
		return htmlspecialchars($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
	}//end escape()
}//end class
