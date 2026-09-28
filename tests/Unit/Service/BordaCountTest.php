<?php

/**
 * Unit tests for BordaCount (issue #1419, REQ-PRF-003).
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
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\BordaCount;
use PHPUnit\Framework\TestCase;

/**
 * The Borda count on plain arrays.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
 */
class BordaCountTest extends TestCase {

	/**
	 * The three clubhouse options.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private static function options(): array {
		return [
			['key' => 'renoveren', 'label' => 'Renovate the clubhouse'],
			['key' => 'nieuwbouw', 'label' => 'Build a new clubhouse'],
			['key' => 'huren', 'label' => 'Rent a hall'],
		];
	}//end options()

	/**
	 * The spec's clear-winner scenario: 5, 3 and 1 points, renoveren adopted.
	 *
	 * @return void
	 */
	public function testClearWinner(): void {
		$outcome = (new BordaCount())->count(
			options: self::options(),
			ballots: [
				['renoveren', 'nieuwbouw', 'huren'],
				['nieuwbouw', 'renoveren', 'huren'],
				['renoveren', 'huren', 'nieuwbouw'],
			]
		);

		self::assertSame(
			[
				['key' => 'renoveren', 'label' => 'Renovate the clubhouse', 'points' => 5, 'rank' => 1],
				['key' => 'nieuwbouw', 'label' => 'Build a new clubhouse', 'points' => 3, 'rank' => 2],
				['key' => 'huren', 'label' => 'Rent a hall', 'points' => 1, 'rank' => 3],
			],
			$outcome['rankingResult']
		);
		self::assertSame('renoveren', $outcome['winningOption']);
		self::assertSame('adopted', $outcome['result']);
		self::assertSame(3, $outcome['counted']);
	}//end testClearWinner()

	/**
	 * The spec's tie scenario: renoveren and nieuwbouw both 3, no winner.
	 *
	 * @return void
	 */
	public function testTieAtTheTop(): void {
		$outcome = (new BordaCount())->count(
			options: self::options(),
			ballots: [
				['renoveren', 'nieuwbouw', 'huren'],
				['nieuwbouw', 'renoveren', 'huren'],
			]
		);

		self::assertSame('tied', $outcome['result']);
		self::assertSame('', $outcome['winningOption']);
		self::assertSame(['renoveren', 'nieuwbouw'], $outcome['tiedOptions']);
		self::assertSame([1, 1, 3], array_column($outcome['rankingResult'], 'rank'));
	}//end testTieAtTheTop()

	/**
	 * No ballots is invalid.
	 *
	 * @return void
	 */
	public function testNoBallotsIsInvalid(): void {
		$outcome = (new BordaCount())->count(options: self::options(), ballots: []);

		self::assertSame('invalid', $outcome['result']);
		self::assertSame('', $outcome['winningOption']);
	}//end testNoBallotsIsInvalid()

	/**
	 * A partial or duplicated ballot is not counted.
	 *
	 * @return void
	 */
	public function testIncompleteBallotsAreNotCounted(): void {
		$outcome = (new BordaCount())->count(
			options: self::options(),
			ballots: [
				['renoveren', 'nieuwbouw'],
				['renoveren', 'renoveren', 'huren'],
				['huren', 'nieuwbouw', 'onbekend'],
				['huren', 'nieuwbouw', 'renoveren'],
			]
		);

		self::assertSame(1, $outcome['counted']);
		self::assertSame('huren', $outcome['winningOption']);
		self::assertFalse(BordaCount::isFullRanking(ranking: ['a', 'b'], keys: ['a', 'b', 'c']));
		self::assertTrue(BordaCount::isFullRanking(ranking: ['c', 'a', 'b'], keys: ['a', 'b', 'c']));
	}//end testIncompleteBallotsAreNotCounted()
}//end class
