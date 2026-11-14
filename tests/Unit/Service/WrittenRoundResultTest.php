<?php

/**
 * Tests for the written-round result of VotingResultCalculator.
 *
 * Counted against the whole electorate, never the answers received, with the
 * close reason the round closes on (voting-written-resolution, task 4).
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingResultCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The scenarios of REQ-WRS-003 and REQ-WRS-004, and their edges.
 *
 * @covers \OCA\Decidiq\Service\VotingResultCalculator
 */
class WrittenRoundResultTest extends TestCase {

	/**
	 * A written round over members m1..mN of weight 1.
	 *
	 * @param int                  $members How many members.
	 * @param array<string, mixed> $rule    The round's rule fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function round(int $members, array $rule = []): array {
		$electorate = [];
		for ($i = 1; $i <= $members; $i++) {
			$electorate[] = ['membership' => 'm' . $i, 'person' => 'p' . $i, 'userId' => 'u' . $i, 'votingWeight' => 1];
		}

		return ['procedure' => 'written', 'electorate' => $electorate] + $rule;
	}//end round()

	/**
	 * Result and close reason of a round with these answers.
	 *
	 * @param array<string, mixed>  $round   The round.
	 * @param array<string, string> $answers The answers.
	 *
	 * @return array{0: string, 1: string|null}
	 */
	private static function outcome(array $round, array $answers): array {
		$computed = (new VotingResultCalculator())->computeWritten(round: $round, answers: $answers);
		return [$computed['result'], $computed['closeReason']];
	}//end outcome()

	/**
	 * Unanimous: two for and one abstention is rejected (REQ-WRS-003).
	 *
	 * @return void
	 */
	public function testTwoOfThreeAgreeAndOneAbstains(): void {
		$this->assertSame(
			expected: ['rejected', 'all-answered'],
			actual: self::outcome(round: self::round(members: 3), answers: ['m1' => 'for', 'm2' => 'for', 'm3' => 'abstain'])
		);
	}//end testTwoOfThreeAgreeAndOneAbstains()

	/**
	 * Threshold, simple majority of five: three for and two silent is adopted at the deadline.
	 *
	 * @return void
	 */
	public function testAMajorityOfTheWholeElectorateAdopts(): void {
		$round = self::round(members: 5, rule: ['writtenRule' => 'threshold', 'voteThreshold' => 'simple-majority']);

		$this->assertSame(
			expected: ['adopted', null],
			actual: self::outcome(round: $round, answers: ['m1' => 'for', 'm2' => 'for', 'm3' => 'for']),
			message: 'stays open: an objection could still come'
		);
		$this->assertSame(
			expected: ['rejected', null],
			actual: self::outcome(round: $round, answers: ['m1' => 'for', 'm2' => 'for']),
			message: 'two of five is no majority of the whole electorate'
		);
	}//end testAMajorityOfTheWholeElectorateAdopts()

	/**
	 * One objection rejects a threshold round that the for answers would carry.
	 *
	 * @return void
	 */
	public function testOneMemberWantsAMeeting(): void {
		$round = self::round(members: 5, rule: ['writtenRule' => 'threshold', 'voteThreshold' => 'simple-majority']);
		$answers = ['m1' => 'for', 'm2' => 'for', 'm3' => 'for', 'm4' => 'for', 'm5' => 'objection'];

		$this->assertSame(expected: ['rejected', 'objection'], actual: self::outcome(round: $round, answers: $answers));
		$this->assertSame(
			expected: ['rejected', 'objection'],
			actual: self::outcome(round: self::round(members: 3), answers: ['m1' => 'objection']),
			message: 'an objection closes at once'
		);
	}//end testOneMemberWantsAMeeting()

	/**
	 * Unanimous: the last for answer closes the round adopted.
	 *
	 * @return void
	 */
	public function testEveryoneAgreesBeforeTheDeadline(): void {
		$round = self::round(members: 3);

		$this->assertSame(
			expected: ['rejected', null],
			actual: self::outcome(round: $round, answers: ['m1' => 'for', 'm2' => 'for']),
			message: 'open while one is missing'
		);
		$this->assertSame(
			expected: ['adopted', 'all-answered'],
			actual: self::outcome(round: $round, answers: ['m1' => 'for', 'm2' => 'for', 'm3' => 'for'])
		);
	}//end testEveryoneAgreesBeforeTheDeadline()

	/**
	 * Unanimous: the first answer that is not for settles the round.
	 *
	 * @return void
	 */
	public function testTheFirstRefusalSettlesAUnanimousRound(): void {
		$this->assertSame(
			expected: ['rejected', 'outcome-settled'],
			actual: self::outcome(round: self::round(members: 3), answers: ['m3' => 'against'])
		);
		$this->assertSame(
			expected: ['rejected', 'outcome-settled'],
			actual: self::outcome(round: self::round(members: 3), answers: ['m2' => 'abstain'])
		);
	}//end testTheFirstRefusalSettlesAUnanimousRound()

	/**
	 * Silence at the deadline is not consent: the result read then is rejected.
	 *
	 * @return void
	 */
	public function testSilenceAtTheDeadline(): void {
		$this->assertSame(
			expected: ['rejected', null],
			actual: self::outcome(round: self::round(members: 3), answers: ['m1' => 'for', 'm2' => 'for'])
		);
		$this->assertSame(expected: ['rejected', null], actual: self::outcome(round: self::round(members: 3), answers: []));
	}//end testSilenceAtTheDeadline()

	/**
	 * A threshold round that can no longer reach its threshold settles rejected.
	 *
	 * @return void
	 */
	public function testAThresholdOutOfReachSettles(): void {
		$round = self::round(members: 4, rule: ['writtenRule' => 'threshold', 'voteThreshold' => 'qualified-majority-three-quarters']);

		$this->assertSame(
			expected: ['rejected', 'outcome-settled'],
			actual: self::outcome(round: $round, answers: ['m1' => 'against', 'm2' => 'abstain'])
		);
		$this->assertSame(
			expected: ['rejected', null],
			actual: self::outcome(round: $round, answers: ['m1' => 'against']),
			message: 'three of four can still answer for'
		);
	}//end testAThresholdOutOfReachSettles()

	/**
	 * Weights count under threshold; an answer from outside the electorate does not count.
	 *
	 * @return void
	 */
	public function testWeightsAndOutsidersAreHandled(): void {
		$round = [
			'writtenRule' => 'threshold',
			'voteThreshold' => 'simple-majority',
			'electorate' => [
				['membership' => 'heavy', 'votingWeight' => 3],
				['membership' => 'light-1', 'votingWeight' => 1],
				['membership' => 'light-2', 'votingWeight' => 1],
				'not a member',
			],
		];

		$computed = (new VotingResultCalculator())->computeWritten(round: $round, answers: ['heavy' => 'for', 'outsider' => 'for']);

		$this->assertSame(expected: 'adopted', actual: $computed['result']);
		$this->assertSame(
			expected: ['answered' => 1, 'electorate' => 3, 'forWeight' => 3.0, 'base' => 5.0],
			actual: array_intersect_key($computed, array_flip(array: ['answered', 'electorate', 'forWeight', 'base']))
		);
		$this->assertSame(
			expected: ['rejected', 'outcome-settled'],
			actual: self::outcome(round: ['electorate' => []], answers: ['x' => 'for']),
			message: 'an empty electorate can never adopt'
		);
	}//end testWeightsAndOutsidersAreHandled()
}//end class
