<?php

/**
 * Decidiq DecisionTermCalculator
 *
 * The day by which a decision has to be taken, from the term its decision
 * type declares (`termPeriod`, an ISO 8601 duration) and the moment the type
 * counts it from (`termStartsAt`): the day the request was received, or the
 * end of the period to object to the decision it contests. That period ends
 * the remedy term of the contested decision's own legal remedy clause after
 * the day it was made known.
 *
 * Nothing here is named after a procedure: the term, the moment and the
 * remedy are configuration of the types, so an organisation with another
 * route or another term changes configuration, not code.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Works out the day a decision has to be taken by.
 *
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */
final class DecisionTermCalculator {

	/**
	 * The moment a term counts from when the type names the end of the objection period.
	 */
	public const FROM_OBJECTION_PERIOD_END = 'objection-period-end';

	/**
	 * The day the decision has to be taken by, as YYYY-MM-DD, or null when
	 * the type declares no term or the moment it counts from is not known.
	 *
	 * @param array<string, mixed>      $decision  The decision.
	 * @param array<string, mixed>      $type      Its decision type.
	 * @param array<string, mixed>|null $contested The decision it contests, when it names one.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
	 */
	public function termEnd(array $decision, array $type, ?array $contested): ?string {
		$period = self::period(value: ($type['termPeriod'] ?? null));
		if ($period === null) {
			return null;
		}

		$start = $this->startOf(decision: $decision, startsAt: (string)($type['termStartsAt'] ?? 'received'), contested: $contested);
		if ($start === null) {
			return null;
		}

		return $start->add($period)->format('Y-m-d');
	}//end termEnd()

	/**
	 * The day the term counts from.
	 *
	 * @param array<string, mixed>      $decision  The decision.
	 * @param string                    $startsAt  The moment the type names.
	 * @param array<string, mixed>|null $contested The contested decision.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function startOf(array $decision, string $startsAt, ?array $contested): ?DateTimeImmutable {
		if ($startsAt === self::FROM_OBJECTION_PERIOD_END) {
			return self::objectionPeriodEnd(contested: $contested);
		}

		return self::day(value: ($decision['receivedAt'] ?? null));
	}//end startOf()

	/**
	 * The end of the period to object to a decision: its remedy term in days
	 * after the day it was made known (published, else decided).
	 *
	 * @param array<string, mixed>|null $contested The contested decision.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function objectionPeriodEnd(?array $contested): ?DateTimeImmutable {
		$days = ($contested['legalRemedyClause']['termDays'] ?? null);
		$known = self::day(value: ($contested['publishedAt'] ?? ($contested['decisionDate'] ?? null)));
		if (is_numeric($days) === false || (int)$days < 1 || $known === null) {
			return null;
		}

		return $known->add(new DateInterval('P' . (int)$days . 'D'));
	}//end objectionPeriodEnd()

	/**
	 * An ISO 8601 date-period, or null when the value is not one.
	 *
	 * @param mixed $value The declared term.
	 *
	 * @return DateInterval|null
	 */
	private static function period(mixed $value): ?DateInterval {
		if (is_string($value) === false || preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?$/', $value) !== 1) {
			return null;
		}

		try {
			return new DateInterval($value);
		} catch (Throwable) {
			return null;
		}
	}//end period()

	/**
	 * The day of a date or timestamp, or null when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function day(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
		if ($day === false || $day->format('Y-m-d') !== substr($value, 0, 10)) {
			return null;
		}

		return $day;
	}//end day()
}//end class
