<?php

/**
 * Tests for WrittenRoundCloser.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\VotingResultCalculator;
use OCA\Decidiq\Service\WrittenRoundCloser;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Settling, the deadline close, and what a close writes.
 *
 * @covers \OCA\Decidiq\Service\WrittenRoundCloser
 * @uses   \OCA\Decidiq\Service\WrittenRoundStore
 * @uses   \OCA\Decidiq\Service\VotingResultCalculator
 * @uses   \OCA\Decidiq\Service\EffectiveVoteRules
 * @uses   \OCA\Decidiq\Service\VoteTally
 */
class WrittenRoundCloserTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var WrittenRoundWorld
	 */
	private WrittenRoundWorld $world;

	/**
	 * Set up a unanimous round of three where two answered for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = new WrittenRoundWorld(test: $this);
		$this->world->put('decision', ['id' => 'd-1', 'lifecycle' => 'proposed']);
		$this->world->put('decision-stage', ['id' => 'stage-1', 'status' => 'active', 'method' => 'vote']);
		$this->world->put('voting-round', $this->round());
		$this->vote(membership: 'm-1', value: 'for', at: '2026-03-09T11:30:00+00:00');
		$this->vote(membership: 'm-2', value: 'for', at: '2026-03-10T08:45:00+00:00');
	}//end setUp()

	/**
	 * The round.
	 *
	 * @return array<string, mixed>
	 */
	private function round(): array {
		return [
			'id' => 'r-1',
			'procedure' => 'written',
			'decision' => 'd-1',
			'decisionStage' => 'stage-1',
			'writtenRule' => 'unanimous',
			'electorate' => [
				['membership' => 'm-1', 'userId' => 'a', 'votingWeight' => 1],
				['membership' => 'm-2', 'userId' => 'b', 'votingWeight' => 1],
				['membership' => 'm-3', 'userId' => 'c', 'votingWeight' => 1],
			],
			'votingDeadline' => '2026-03-13T16:00:00+00:00',
			'closedAt' => null,
		];
	}//end round()

	/**
	 * Store an answer.
	 *
	 * @param string $membership Membership.
	 * @param string $value      Answer.
	 * @param string $at         Time.
	 *
	 * @return void
	 */
	private function vote(string $membership, string $value, string $at): void {
		$this->world->put('vote', ['id' => 'v-' . $membership, 'votingRound' => 'r-1', 'membership' => $membership, 'value' => $value, 'castAt' => $at]);
	}//end vote()

	/**
	 * The closer over the world.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return WrittenRoundCloser
	 */
	private function closer(?LoggerInterface $logger=null): WrittenRoundCloser {
		return new WrittenRoundCloser(
			store: new WrittenRoundStore(objectService: $this->world->objectService()),
			calculator: new VotingResultCalculator(),
			logger: ($logger ?? new NullLogger()),
		);
	}//end closer()

	/**
	 * While an answer is missing and the outcome can still be met, settle changes nothing.
	 *
	 * @return void
	 */
	public function testSettleLeavesAnUndecidedRoundOpen(): void {
		$round = $this->closer()->settle(round: $this->round());

		$this->assertNull($round['closedAt']);
		$this->assertSame([], $this->world->saves);
	}//end testSettleLeavesAnUndecidedRoundOpen()

	/**
	 * Silence at the deadline rejects, and a second run changes nothing (third scenario).
	 *
	 * @return void
	 */
	public function testSilenceAtTheDeadlineRejectsOnce(): void {
		$closer = $this->closer();

		$this->assertFalse($closer->closeAtDeadline(round: $this->round(), now: new DateTimeImmutable('2026-03-13T15:59:00Z')));
		$this->assertSame([], $this->world->saves);

		$this->assertTrue($closer->closeAtDeadline(round: $this->round(), now: new DateTimeImmutable('2026-03-13T16:00:00Z')));
		$round = $this->world->rows['voting-round']['r-1'];
		$this->assertSame('rejected', $round['result']);
		$this->assertSame('deadline', $round['closedReason']);
		$this->assertSame(2, $round['votesFor']);
		$this->assertSame('rejected', $this->world->rows['decision']['d-1']['outcome']);
		$this->assertSame('decided', $this->world->rows['decision']['d-1']['lifecycle']);
		$saves = count($this->world->saves);

		$this->assertFalse($closer->closeAtDeadline(round: $round, now: new DateTimeImmutable('2026-03-13T17:00:00Z')));
		$this->assertCount($saves, $this->world->saves);
	}//end testSilenceAtTheDeadlineRejectsOnce()

	/**
	 * A threshold round whose for weight is met at the deadline is adopted, dated at its last answer.
	 *
	 * @return void
	 */
	public function testAThresholdMetAtTheDeadlineAdopts(): void {
		$round = array_merge($this->round(), ['writtenRule' => 'threshold', 'voteThreshold' => 'simple-majority']);

		$this->assertTrue($this->closer()->closeAtDeadline(round: $round, now: new DateTimeImmutable('2026-03-14T00:00:00Z')));

		$this->assertSame('adopted', $this->world->rows['voting-round']['r-1']['result']);
		$this->assertSame('2026-03-10T08:45:00+00:00', $this->world->rows['decision']['d-1']['decisionDate']);
	}//end testAThresholdMetAtTheDeadlineAdopts()

	/**
	 * An in-meeting round with a deadline is never closed as written.
	 *
	 * @return void
	 */
	public function testAnInMeetingRoundIsUntouched(): void {
		$round = array_merge($this->round(), ['procedure' => 'in-meeting']);

		$this->assertFalse($this->closer()->closeAtDeadline(round: $round, now: new DateTimeImmutable('2027-01-01T00:00:00Z')));
		$this->assertSame($round, $this->closer()->settle(round: $round));
		$this->assertSame([], $this->world->saves);
		$this->assertFalse(WrittenRoundCloser::isOpen(round: ['closedAt' => null, 'id' => 'x']));
	}//end testAnInMeetingRoundIsUntouched()

	/**
	 * The active vote stage the round resolves is decided with the same outcome;
	 * a stage that is not active is left alone.
	 *
	 * @return void
	 */
	public function testTheActiveStageIsDecidedWithTheOutcome(): void {
		$this->vote(membership: 'm-3', value: 'for', at: '2026-03-11T10:12:00+00:00');

		$round = $this->closer()->settle(round: $this->round());

		$this->assertSame('all-answered', $round['closedReason']);
		$this->assertSame('decided', $this->world->rows['decision-stage']['stage-1']['status']);
		$this->assertSame('adopted', $this->world->rows['decision-stage']['stage-1']['outcome']);
		$this->assertSame('2026-03-11T10:12:00+00:00', $this->world->rows['decision']['d-1']['decisionDate']);

		$this->world->put('decision-stage', ['id' => 'stage-1', 'status' => 'pending']);
		$this->world->rows['voting-round']['r-1']['closedAt'] = null;
		$this->closer()->settle(round: $this->round());
		$this->assertSame('pending', $this->world->rows['decision-stage']['stage-1']['status']);
	}//end testTheActiveStageIsDecidedWithTheOutcome()

	/**
	 * Answers are read per membership and only from this round.
	 *
	 * @return void
	 */
	public function testAnswersOf(): void {
		$this->world->put('vote', ['id' => 'v-other', 'votingRound' => 'r-2', 'membership' => 'm-3', 'value' => 'against', 'castAt' => '2026-03-10T00:00:00+00:00']);
		$this->world->put('vote', ['id' => 'v-none', 'votingRound' => 'r-1', 'value' => 'against']);

		$this->assertSame(
			['m-1' => ['value' => 'for', 'castAt' => '2026-03-09T11:30:00+00:00'], 'm-2' => ['value' => 'for', 'castAt' => '2026-03-10T08:45:00+00:00']],
			$this->closer()->answersOf(roundId: 'r-1')
		);
	}//end testAnswersOf()

	/**
	 * A decision that cannot be written leaves the round closed and is logged.
	 *
	 * @return void
	 */
	public function testADecisionWriteFailureIsLoggedNotThrown(): void {
		$this->vote(membership: 'm-3', value: 'against', at: '2026-03-11T10:12:00+00:00');
		unset($this->world->rows['decision-stage']);
		$objects = $this->world->objectService();
		$world   = $this->world;
		$failing = $this->getMockBuilder(\OCA\OpenRegister\Contract\ObjectServiceInterface::class)->getMock();
		$failing->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($objects) {
				if ($schema === 'decision') {
					throw new \RuntimeException('register offline');
				}

				return $objects->find(id: $id, register: $register, schema: $schema);
			}
		);
		$failing->method('findAll')->willReturnCallback(static fn (array $config=[]): array => $objects->findAll(config: $config));
		$failing->method('saveObject')->willReturnCallback(static fn (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null) => $objects->saveObject(object: $object, register: $register, schema: $schema));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Decidiq: written round closed but the decision could not be marked decided');

		$closer = new WrittenRoundCloser(store: new WrittenRoundStore(objectService: $failing), calculator: new VotingResultCalculator(), logger: $logger);
		$round  = $closer->settle(round: $this->round());

		$this->assertSame('rejected', $round['result']);
		$this->assertSame('proposed', $world->rows['decision']['d-1']['lifecycle']);
	}//end testADecisionWriteFailureIsLoggedNotThrown()
}//end class
