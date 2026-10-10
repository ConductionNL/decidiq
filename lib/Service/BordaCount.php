<?php

/**
 * Decidiq Borda Count
 *
 * Counts the ballots of a ranked preference round (voting-ranked-preference-
 * ballot, REQ-PRF-003, issue #1419). With N options a first place earns N
 * minus 1 points and a last place 0. The option with the most points wins;
 * when two or more share the top score the round is tied, and a round with no
 * ballots is invalid.
 *
 * Pure on purpose: no OpenRegister, no container. The tally hands it the
 * round's options and ballots, and it answers with the points, ranks and
 * result.
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
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * A Borda count over full rankings.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
 */
class BordaCount {

	/**
	 * Count the ballots.
	 *
	 * A ballot that is not a full ranking of the round's options (it leaves
	 * one out, names one twice, or names one the round does not have) is
	 * skipped: the cast endpoint already refuses such a ballot, so one here
	 * can only have been written around it, and counting it would change
	 * what the others mean.
	 *
	 * @param array<int, array{key: string, label?: string}> $options The round's options.
	 * @param array<int, array<int, string>> $ballots Each ballot's ranking, first preference first.
	 *
	 * @return array{
	 *     rankingResult: array<int, array{key: string, label: string, points: int, rank: int}>,
	 *     winningOption: string,
	 *     tiedOptions: array<int, string>,
	 *     result: string,
	 *     counted: int
	 * } The ranking, the winner ('' on a tie or with no ballots) and the result.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
	 */
	public function count(array $options, array $ballots): array {
		$labels = [];
		$points = [];
		foreach ($options as $option) {
			$key = (string)$option['key'];
			$labels[$key] = (string)($option['label'] ?? $key);
			$points[$key] = 0;
		}

		$optionCount = count($points);
		$counted = 0;
		foreach ($ballots as $ranking) {
			if ($this->isFullRanking(ranking: $ranking, keys: array_keys($points)) === false) {
				continue;
			}

			$counted++;
			foreach (array_values($ranking) as $position => $key) {
				$points[$key] += ($optionCount - 1 - $position);
			}
		}

		return $this->result(labels: $labels, points: $points, counted: $counted);
	}//end count()

	/**
	 * Whether a ranking names every option exactly once and nothing else.
	 *
	 * @param mixed $ranking The ranking.
	 * @param array<int, string> $keys The round's option keys.
	 *
	 * @return bool True for a full ranking.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function isFullRanking(mixed $ranking, array $keys): bool {
		if (is_array($ranking) === false || count($ranking) !== count($keys)) {
			return false;
		}

		$named = [];
		foreach (array_values($ranking) as $key) {
			if (is_scalar($key) === false) {
				return false;
			}

			$named[] = (string)$key;
		}

		if (count(array_unique($named)) !== count($named)) {
			return false;
		}

		return array_diff($named, $keys) === [] && array_diff($keys, $named) === [];
	}//end isFullRanking()

	/**
	 * Rank the options by points and decide the result.
	 *
	 * @param array<string, string> $labels The label per option key.
	 * @param array<string, int> $points The points per option key.
	 * @param int $counted How many ballots were counted.
	 *
	 * @return array{
	 *     rankingResult: array<int, array{key: string, label: string, points: int, rank: int}>,
	 *     winningOption: string,
	 *     tiedOptions: array<int, string>,
	 *     result: string,
	 *     counted: int
	 * } The outcome.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
	 */
	private function result(array $labels, array $points, int $counted): array {
		// Stable: equal points keep the order the options were declared in.
		$declared = array_flip(array_keys($points));
		$order = array_keys($points);
		usort(
			$order,
			static function (string $left, string $right) use ($points, $declared): int {
				$byPoints = ($points[$right] <=> $points[$left]);
				if ($byPoints !== 0) {
					return $byPoints;
				}

				return ($declared[$left] <=> $declared[$right]);
			}
		);

		$ranking = [];
		$rank = 0;
		$previous = null;
		foreach ($order as $position => $key) {
			if ($points[$key] !== $previous) {
				$rank = ($position + 1);
				$previous = $points[$key];
			}

			$ranking[] = ['key' => $key, 'label' => $labels[$key], 'points' => $points[$key], 'rank' => $rank];
		}

		$outcome = ['rankingResult' => $ranking, 'winningOption' => '', 'tiedOptions' => [], 'result' => 'invalid', 'counted' => $counted];
		if ($counted === 0 || $ranking === []) {
			return $outcome;
		}

		$top = array_values(array_filter($ranking, static fn (array $row): bool => $row['rank'] === 1));
		if (count($top) > 1) {
			$outcome['tiedOptions'] = array_column($top, 'key');
			$outcome['result'] = 'tied';
			return $outcome;
		}

		$outcome['winningOption'] = $top[0]['key'];
		$outcome['result'] = 'adopted';

		return $outcome;
	}//end result()
}//end class
