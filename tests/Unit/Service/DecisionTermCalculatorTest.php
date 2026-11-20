<?php

/**
 * Tests for DecisionTermCalculator.
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
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DecisionTermCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The term counts from the moment the type names, for the period the type declares.
 */
class DecisionTermCalculatorTest extends TestCase {

	/**
	 * The refused permit for Kerkstraat 12: published 23 June 2026 with six weeks to object.
	 */
	private const REFUSED_PERMIT = [
		'publishedAt' => '2026-06-23T10:00:00+02:00',
		'decisionDate' => '2026-06-20',
		'legalRemedyClause' => ['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college', 'text' => '...'],
	];

	/**
	 * An objection type: twelve weeks from the end of the objection period.
	 */
	private const OBJECTION_TYPE = ['termPeriod' => 'P12W', 'termStartsAt' => DecisionTermCalculator::FROM_OBJECTION_PERIOD_END];

	/**
	 * The objection period ends six weeks after publication (4 August), and
	 * the decision on the objection is due twelve weeks later (27 October).
	 *
	 * @return void
	 */
	public function testAnObjectionTermCountsFromTheEndOfTheObjectionPeriod(): void {
		$calculator = new DecisionTermCalculator();

		self::assertSame('2026-10-27', $calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: self::OBJECTION_TYPE, contested: self::REFUSED_PERMIT));
	}//end testAnObjectionTermCountsFromTheEndOfTheObjectionPeriod()

	/**
	 * Without a publication the decision date is the day it was made known.
	 *
	 * @return void
	 */
	public function testAnUnpublishedContestedDecisionCountsFromItsDecisionDate(): void {
		$contested = ['decisionDate' => '2026-06-20'] + self::REFUSED_PERMIT;
		unset($contested['publishedAt']);

		self::assertSame('2026-10-24', (new DecisionTermCalculator())->termEnd(decision: [], type: self::OBJECTION_TYPE, contested: $contested));
	}//end testAnUnpublishedContestedDecisionCountsFromItsDecisionDate()

	/**
	 * A type that counts from receipt adds its period to the day it was received.
	 *
	 * @return void
	 */
	public function testATermFromReceiptCountsFromTheReceivedDay(): void {
		$calculator = new DecisionTermCalculator();

		self::assertSame('2026-09-29', $calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: ['termPeriod' => 'P8W', 'termStartsAt' => 'received'], contested: null));
		self::assertSame('2026-08-14', $calculator->termEnd(decision: ['receivedAt' => '2026-08-04T09:30:00+02:00'], type: ['termPeriod' => 'P10D'], contested: null));
		self::assertSame('2027-02-04', $calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: ['termPeriod' => 'P6M'], contested: null));
	}//end testATermFromReceiptCountsFromTheReceivedDay()

	/**
	 * No term, an unreadable term, or a moment that is not known gives no date.
	 *
	 * @return void
	 */
	public function testWithoutATermOrItsMomentThereIsNoDate(): void {
		$calculator = new DecisionTermCalculator();

		self::assertNull($calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: [], contested: null));
		self::assertNull($calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: ['termPeriod' => '12 weeks'], contested: null));
		self::assertNull($calculator->termEnd(decision: ['receivedAt' => '2026-08-04'], type: ['termPeriod' => 'PT12H'], contested: null));
		self::assertNull($calculator->termEnd(decision: [], type: ['termPeriod' => 'P8W'], contested: null));
		self::assertNull($calculator->termEnd(decision: ['receivedAt' => '2026-02-30'], type: ['termPeriod' => 'P8W'], contested: null));
		self::assertNull($calculator->termEnd(decision: [], type: self::OBJECTION_TYPE, contested: null));
		self::assertNull($calculator->termEnd(decision: [], type: self::OBJECTION_TYPE, contested: ['legalRemedyClause' => ['kind' => 'geen', 'termDays' => 0]] + self::REFUSED_PERMIT));
		self::assertNull($calculator->termEnd(decision: [], type: self::OBJECTION_TYPE, contested: ['legalRemedyClause' => ['kind' => 'bezwaar', 'termDays' => 42]]));
	}//end testWithoutATermOrItsMomentThereIsNoDate()
}//end class
