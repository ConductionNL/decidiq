<?php

/**
 * Tests for answering a written round: WrittenRoundAnswerer with its closer.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Service\VotingResultCalculator;
use OCA\Decidiq\Service\WrittenRoundAnswerer;
use OCA\Decidiq\Service\WrittenRoundCloser;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who may answer, what is stored, and when an answer closes the round.
 *
 * @covers \OCA\Decidiq\Service\WrittenRoundAnswerer
 * @uses   \OCA\Decidiq\Service\WrittenRoundCloser
 * @uses   \OCA\Decidiq\Service\WrittenRoundStore
 * @uses   \OCA\Decidiq\Service\VotingResultCalculator
 * @uses   \OCA\Decidiq\Service\EffectiveVoteRules
 * @uses   \OCA\Decidiq\Service\VoteTally
 */
class WrittenVoteCastTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var WrittenRoundWorld
	 */
	private WrittenRoundWorld $world;

	/**
	 * The clock, as a timestamp.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Set up an open unanimous round of three on a proposed decision.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->now   = (new DateTimeImmutable('2026-03-10T08:45:00Z'))->getTimestamp();
		$this->world = new WrittenRoundWorld(test: $this);
		$this->world->put('decision', ['id' => 'd-auditor', 'title' => 'Appoint the external auditor for 2026', 'lifecycle' => 'proposed']);
		$this->world->put(
			'voting-round',
			[
				'id' => 'round-written-1',
				'procedure' => 'written',
				'decision' => 'd-auditor',
				'governanceBody' => 'board',
				'writtenRule' => 'unanimous',
				'electorate' => [
					['membership' => 'm-janneke', 'person' => 'p-janneke', 'userId' => 'janneke', 'votingWeight' => 1],
					['membership' => 'm-jan', 'person' => 'p-jan', 'userId' => 'jan', 'votingWeight' => 1],
					['membership' => 'm-mark', 'person' => 'p-mark', 'userId' => 'mark', 'votingWeight' => 1],
				],
				'electorateUserIds' => ['janneke', 'jan', 'mark'],
				'openedAt' => '2026-03-09T09:00:00+00:00',
				'votingDeadline' => '2026-03-13T16:00:00+00:00',
				'closedAt' => null,
			]
		);
		$this->world->put('voting-round', ['id' => 'round-meeting', 'openedAt' => '2026-03-09T09:00:00+00:00', 'closedAt' => null]);
	}//end setUp()

	/**
	 * The answerer over the world.
	 *
	 * @return WrittenRoundAnswerer
	 */
	private function answerer(): WrittenRoundAnswerer {
		$store = new WrittenRoundStore(objectService: $this->world->objectService());
		$time  = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new WrittenRoundAnswerer(
			store: $store,
			closer: new WrittenRoundCloser(store: $store, calculator: new VotingResultCalculator(), logger: new NullLogger()),
			time: $time,
		);
	}//end answerer()

	/**
	 * A member agrees: one vote related to their membership, and the round stays open.
	 *
	 * @return void
	 */
	public function testAnEntitledMemberAnswers(): void {
		$result = $this->answerer()->answer(roundId: 'round-written-1', userId: 'janneke', value: 'for');

		$votes = $this->world->savesOf('vote');
		$this->assertCount(1, $votes);
		$this->assertSame('for', $votes[0]['value']);
		$this->assertSame('m-janneke', $votes[0]['membership']);
		$this->assertSame('round-written-1', $votes[0]['votingRound']);
		$this->assertSame('2026-03-10T08:45:00+00:00', $votes[0]['castAt']);
		$this->assertArrayNotHasKey('participant', $votes[0]);
		$this->assertNull($result['round']['closedAt']);
		$this->assertSame([], $this->world->savesOf('voting-round'));
	}//end testAnEntitledMemberAnswers()

	/**
	 * A second answer replaces the first: the round holds one answer from the member.
	 *
	 * @return void
	 */
	public function testASecondAnswerReplacesTheFirst(): void {
		// Under a threshold rule an abstention leaves the round open.
		$this->world->rows['voting-round']['round-written-1']['writtenRule'] = 'threshold';
		$this->world->rows['voting-round']['round-written-1']['voteThreshold'] = 'simple-majority';
		$this->answerer()->answer(roundId: 'round-written-1', userId: 'janneke', value: 'abstain');
		$first = $this->world->savesOf('vote')[0]['id'];

		$this->answerer()->answer(roundId: 'round-written-1', userId: 'janneke', value: 'for');

		$this->assertCount(1, $this->world->rows['vote']);
		$this->assertSame('for', $this->world->rows['vote'][$first]['value']);
		$this->assertSame($first, $this->world->savesOf('vote')[1]['id']);
		$this->assertSame([], $this->world->savesOf('voting-round'));
	}//end testASecondAnswerReplacesTheFirst()

	/**
	 * Someone outside the electorate is refused and nothing is stored, though the round has no meeting.
	 *
	 * @return void
	 */
	public function testSomeoneOutsideTheElectorateIsRefused(): void {
		try {
			$this->answerer()->answer(roundId: 'round-written-1', userId: 'newcomer', value: 'for');
			$this->fail('An outsider answered.');
		} catch (AccessDeniedException $e) {
			$this->assertSame('You are not one of the members this decision was put to.', $e->getMessage());
		}

		$this->assertSame([], $this->world->saves);
	}//end testSomeoneOutsideTheElectorateIsRefused()

	/**
	 * A user id in the flat list without an electorate entry is refused too.
	 *
	 * @return void
	 */
	public function testTheFlatListAloneDoesNotEntitle(): void {
		$this->assertNull(WrittenRoundAnswerer::memberOf(round: ['electorateUserIds' => ['x'], 'electorate' => []], userId: 'x'));
		$this->assertNull(WrittenRoundAnswerer::memberOf(round: ['electorateUserIds' => [], 'electorate' => [['userId' => 'x', 'membership' => 'm']]], userId: 'x'));
		$this->assertSame('m', WrittenRoundAnswerer::memberOf(round: ['electorateUserIds' => ['x'], 'electorate' => [['userId' => 'x', 'membership' => 'm']]], userId: 'x')['membership']);
	}//end testTheFlatListAloneDoesNotEntitle()

	/**
	 * An unknown answer, a closed round, and a round held in a meeting are refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		try {
			$this->answerer()->answer(roundId: 'round-written-1', userId: 'jan', value: 'maybe');
			$this->fail('An unknown answer was stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('value must be one of: for, against, abstain, objection', $e->getMessage());
		}

		try {
			$this->answerer()->answer(roundId: 'round-meeting', userId: 'jan', value: 'objection');
			$this->fail('A meeting round was answered as a written round.');
		} catch (NotFoundException $e) {
			$this->assertSame('Written voting round not found', $e->getMessage());
		}

		$this->world->rows['voting-round']['round-written-1']['closedAt'] = '2026-03-10T08:00:00+00:00';
		try {
			$this->answerer()->answer(roundId: 'round-written-1', userId: 'jan', value: 'for');
			$this->fail('A closed round took an answer.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('This written round is closed.', $e->getMessage());
		}

		$this->assertSame([], $this->world->saves);
		$this->assertNull($this->answerer()->writtenRound(roundId: 'round-meeting'));
		$this->assertNull($this->answerer()->writtenRound(roundId: 'nope'));
	}//end testRefusals()

	/**
	 * The last missing for closes the round with all-answered and decides the decision
	 * dated at that answer (REQ-WRS-004, first scenario).
	 *
	 * @return void
	 */
	public function testTheLastForClosesAndAdopts(): void {
		$this->now = (new DateTimeImmutable('2026-03-09T11:30:00Z'))->getTimestamp();
		$this->answerer()->answer(roundId: 'round-written-1', userId: 'janneke', value: 'for');
		$this->now = (new DateTimeImmutable('2026-03-10T08:45:00Z'))->getTimestamp();
		$this->answerer()->answer(roundId: 'round-written-1', userId: 'jan', value: 'for');
		$this->now = (new DateTimeImmutable('2026-03-11T10:12:00Z'))->getTimestamp();

		$result = $this->answerer()->answer(roundId: 'round-written-1', userId: 'mark', value: 'for');

		$this->assertSame('adopted', $result['round']['result']);
		$this->assertSame('all-answered', $result['round']['closedReason']);
		$this->assertSame(3, $result['round']['votesFor']);
		$this->assertNotNull($result['round']['closedAt']);
		$decision = $this->world->rows['decision']['d-auditor'];
		$this->assertSame('decided', $decision['lifecycle']);
		$this->assertSame('adopted', $decision['outcome']);
		$this->assertSame('2026-03-11T10:12:00+00:00', $decision['decisionDate']);
	}//end testTheLastForClosesAndAdopts()

	/**
	 * The first against settles a unanimous round (second scenario).
	 *
	 * @return void
	 */
	public function testTheFirstAgainstSettlesAUnanimousRound(): void {
		$result = $this->answerer()->answer(roundId: 'round-written-1', userId: 'mark', value: 'against');

		$this->assertSame('rejected', $result['round']['result']);
		$this->assertSame('outcome-settled', $result['round']['closedReason']);
		$this->assertSame(1, $result['round']['votesAgainst']);
		$decision = $this->world->rows['decision']['d-auditor'];
		$this->assertSame('decided', $decision['lifecycle']);
		$this->assertSame('rejected', $decision['outcome']);
		$this->assertArrayNotHasKey('decisionDate', $decision);
	}//end testTheFirstAgainstSettlesAUnanimousRound()

	/**
	 * An objection closes a threshold round at once with reason objection.
	 *
	 * @return void
	 */
	public function testAnObjectionClosesAThresholdRound(): void {
		$this->world->rows['voting-round']['round-written-1']['writtenRule'] = 'threshold';
		$this->world->rows['voting-round']['round-written-1']['voteThreshold'] = 'simple-majority';
		$this->answerer()->answer(roundId: 'round-written-1', userId: 'janneke', value: 'for');

		$result = $this->answerer()->answer(roundId: 'round-written-1', userId: 'jan', value: 'objection');

		$this->assertSame('rejected', $result['round']['result']);
		$this->assertSame('objection', $result['round']['closedReason']);
	}//end testAnObjectionClosesAThresholdRound()
}//end class
