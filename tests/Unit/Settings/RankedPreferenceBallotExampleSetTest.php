<?php

/**
 * The association example set carries one closed ranked preference round
 * and its three ballots, and the round's stored Borda count is what the
 * ballots actually give.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-voting-ranked-preference-ballot/tasks.md#task-1-declare-options-ranking-and-the-ranked-result-fields
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\BordaCount;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RankedPreferenceBallotExampleSetTest extends TestCase {

	private const PROFILE = __DIR__ . '/../../../lib/Settings/profiles/association.json';

	private const ROUND = 'stemming-voorkeur-clubhuis';

	/**
	 * The seed objects by schema.
	 *
	 * @return array<string, list<array<string, mixed>>> The objects
	 */
	private static function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::PROFILE), true, 512, JSON_THROW_ON_ERROR);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The seeded ranked round.
	 *
	 * @return array<string, mixed> The round
	 */
	private static function round(): array {
		foreach (self::seeds()['voting-round'] ?? [] as $round) {
			if ($round['slug'] === self::ROUND) {
				return $round;
			}
		}

		self::fail(message: 'The association example set has no ranked round ' . self::ROUND);
	}//end round()

	/**
	 * The ballots cast in the seeded ranked round.
	 *
	 * @return list<array<string, mixed>> The votes
	 */
	private static function ballots(): array {
		return array_values(
			array_filter(
				self::seeds()['vote'] ?? [],
				static fn (array $vote): bool => ($vote['votingRound'] ?? '') === self::ROUND
			)
		);
	}//end ballots()

	/**
	 * The round is a closed, open-ballot ranked round over three options.
	 *
	 * @return void
	 */
	public function testTheRankedRoundIsClosedWithThreeOptions(): void {
		$round = self::round();

		$this->assertSame(expected: 'ranked-choice', actual: $round['votingMethod']);
		$this->assertFalse(condition: $round['isSecret']);
		$this->assertNotEmpty(actual: $round['closedAt'] ?? '');
		$this->assertSame(
			expected: ['renoveren', 'nieuwbouw', 'huren'],
			actual: array_column($round['options'], 'key')
		);
		$this->assertSame(expected: 'adopted', actual: $round['result']);
		$this->assertSame(expected: 'renoveren', actual: $round['winningOption']);
	}//end testTheRankedRoundIsClosedWithThreeOptions()

	/**
	 * Three members cast a full ranking each, as ranked votes.
	 *
	 * @return void
	 */
	public function testThreeMembersCastAFullRanking(): void {
		$keys    = array_column(self::round()['options'], 'key');
		$ballots = self::ballots();
		$counter = new BordaCount();

		$this->assertCount(expectedCount: 3, haystack: $ballots);
		$this->assertCount(expectedCount: 3, haystack: array_unique(array_column($ballots, 'participant')));
		foreach ($ballots as $ballot) {
			$this->assertSame(expected: 'ranked', actual: $ballot['value']);
			$this->assertNotEmpty(actual: $ballot['castAt'] ?? '');
			$this->assertTrue(condition: $counter->isFullRanking(ranking: $ballot['ranking'], keys: $keys));
		}
	}//end testThreeMembersCastAFullRanking()

	/**
	 * Recounting the seeded ballots gives the seeded ranking: 5, 3 and 1 points.
	 *
	 * @return void
	 */
	public function testTheSeededRankingIsWhatTheBallotsGive(): void {
		$round   = self::round();
		$outcome = (new BordaCount())->count(
			options: $round['options'],
			ballots: array_column(self::ballots(), 'ranking')
		);

		$this->assertSame(expected: $outcome['rankingResult'], actual: $round['rankingResult']);
		$this->assertSame(expected: [5, 3, 1], actual: array_column($round['rankingResult'], 'points'));
		$this->assertSame(expected: $outcome['winningOption'], actual: $round['winningOption']);
		$this->assertSame(expected: $outcome['result'], actual: $round['result']);
	}//end testTheSeededRankingIsWhatTheBallotsGive()
}//end class
