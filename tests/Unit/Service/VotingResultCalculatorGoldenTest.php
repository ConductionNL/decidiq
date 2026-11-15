<?php

/**
 * Golden tests for VotingResultCalculator.
 *
 * One row per branch of the rule-aware result: empty rounds, ties under each
 * tie-break rule and casting vote, each threshold on both sides of its
 * boundary, both abstention modes, and the fail-closed fallback for unknown
 * stored rules.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingResultCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every result the calculator can give, with its metadata.
 */
final class VotingResultCalculatorGoldenTest extends TestCase {
	/**
	 * Counts, round and expected result plus base.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: array<string, mixed>, 4: string, 5: int}> The cases.
	 */
	public static function cases(): array {
		$chair = ['tieBreakRule' => 'chair-decides'];
		return [
			'nothing cast' => [0, 0, 0, [], 'invalid', 0],
			'nothing cast, counted' => [0, 0, 0, ['abstentionHandling' => 'count'], 'invalid', 0],
			'only abstentions' => [0, 0, 3, [], 'rejected', 0],
			'only abstentions, counted' => [0, 0, 3, ['abstentionHandling' => 'count'], 'rejected', 3],
			'tie, default rule' => [2, 2, 1, [], 'rejected', 4],
			'tie, revote' => [2, 2, 0, ['tieBreakRule' => 'revote'], 'tied', 4],
			'tie, chair without a casting vote' => [2, 2, 0, $chair, 'tied', 4],
			'tie, chair casts for' => [2, 2, 0, $chair + ['chairCastingVote' => 'for'], 'adopted', 4],
			'tie, chair casts against' => [2, 2, 0, $chair + ['chairCastingVote' => 'against'], 'rejected', 4],
			'tie, chair casts abstain' => [2, 2, 0, $chair + ['chairCastingVote' => 'abstain'], 'tied', 4],
			'casting vote without a tie' => [3, 2, 0, $chair + ['chairCastingVote' => 'against'], 'adopted', 5],
			'simple majority carries' => [3, 2, 0, [], 'adopted', 5],
			'simple majority misses' => [2, 3, 0, [], 'rejected', 5],
			'simple majority, counted abstentions' => [3, 2, 1, ['abstentionHandling' => 'count'], 'rejected', 6],
			'tie under counted abstentions' => [2, 2, 2, ['abstentionHandling' => 'count', 'tieBreakRule' => 'revote'], 'tied', 6],
			'two thirds met' => [2, 1, 0, ['voteThreshold' => 'qualified-majority-two-thirds'], 'adopted', 3],
			'two thirds, a tie is not a tie' => [2, 2, 0, ['voteThreshold' => 'qualified-majority-two-thirds', 'tieBreakRule' => 'revote'], 'rejected', 4],
			'three quarters met' => [3, 1, 0, ['voteThreshold' => 'qualified-majority-three-quarters'], 'adopted', 4],
			'three quarters missed' => [2, 1, 0, ['voteThreshold' => 'qualified-majority-three-quarters'], 'rejected', 3],
			'unanimous' => [3, 0, 1, ['voteThreshold' => 'unanimous'], 'adopted', 3],
			'unanimous, counted abstention' => [3, 0, 1, ['voteThreshold' => 'unanimous', 'abstentionHandling' => 'count'], 'rejected', 4],
			'unanimous, one against' => [3, 1, 0, ['voteThreshold' => 'unanimous'], 'rejected', 4],
		];
	}//end cases()

	/**
	 * Each case gives its result, base and the effective rules.
	 *
	 * @param int                  $for     For-votes.
	 * @param int                  $against Against-votes.
	 * @param int                  $abstain Abstentions.
	 * @param array<string, mixed> $round   The round.
	 * @param string               $result  The expected result.
	 * @param int                  $base    The expected base.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testTheResult(int $for, int $against, int $abstain, array $round, string $result, int $base): void {
		$this->assertSame(
			[
				'result' => $result,
				'base' => $base,
				'voteThreshold' => ($round['voteThreshold'] ?? 'simple-majority'),
				'abstentionHandling' => ($round['abstentionHandling'] ?? 'exclude'),
				'tieBreakRule' => ($round['tieBreakRule'] ?? 'rejected'),
			],
			(new VotingResultCalculator())->compute(for: $for, against: $against, abstain: $abstain, round: $round)
		);
	}//end testTheResult()

	/**
	 * Unknown or mistyped stored rules fall back to the strictest sane default.
	 *
	 * @return void
	 */
	public function testUnknownRulesFallBack(): void {
		$round = ['voteThreshold' => 'bogus', 'abstentionHandling' => 1, 'tieBreakRule' => 'coin-toss', 'chairCastingVote' => 'for'];

		$this->assertSame(
			['result' => 'rejected', 'base' => 4, 'voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'],
			(new VotingResultCalculator())->compute(for: 2, against: 2, abstain: 5, round: $round)
		);
	}//end testUnknownRulesFallBack()
}//end class
