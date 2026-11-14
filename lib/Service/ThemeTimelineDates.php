<?php

/**
 * Dates on a theme timeline: sortable forms of periods, their order, and whether an object is public.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Pure date helpers of {@see ThemeTimelineBuilder}.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */
class ThemeTimelineDates {

	/**
	 * Whether an object's publication date has passed and it is not depublished.
	 *
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return bool True when public.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	public function isPublic(array $object): bool {
		$now = time();
		$published = strtotime((string)($object['publicationDate'] ?? ''));
		if ($published === false || $published > $now) {
			return false;
		}

		$depublished = strtotime((string)($object['depublicationDate'] ?? ''));
		return $depublished === false || $depublished > $now;
	}//end isPublic()

	/**
	 * A date as a sortable `YYYY-MM-DD...` string; a quarter or month period starts on its first day.
	 *
	 * @param string|null $value The date, date-time, `YYYY-Qn` or `YYYY-MM`.
	 *
	 * @return string|null The date, null when there is none.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	public function sortable(?string $value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^(\d{4})-?Q([1-4])$/i', $value, $quarter) === 1) {
			return sprintf('%s-%02d-01', $quarter[1], ((((int)$quarter[2]) - 1) * 3) + 1);
		}

		if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
			return $value . '-01';
		}

		return $value;
	}//end sortable()

	/**
	 * Order two dates oldest first, with undated last.
	 *
	 * @param string|null $first  The first date.
	 * @param string|null $second The second date.
	 *
	 * @return int The comparison.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	public function compare(?string $first, ?string $second): int {
		if ($first === $second) {
			return 0;
		}

		if ($first === null) {
			return 1;
		}

		if ($second === null) {
			return -1;
		}

		$byDay = strcmp(substr($first, 0, 10), substr($second, 0, 10));
		if ($byDay !== 0) {
			return $byDay;
		}

		return strcmp($first, $second);
	}//end compare()
}//end class
