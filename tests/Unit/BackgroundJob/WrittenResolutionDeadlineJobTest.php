<?php

/**
 * Tests for WrittenResolutionDeadlineJob.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\BackgroundJob
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

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\WrittenResolutionDeadlineJob;
use OCA\Decidiq\Service\VotingResultCalculator;
use OCA\Decidiq\Service\WrittenRoundCloser;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The deadline close of written rounds.
 *
 * @covers \OCA\Decidiq\BackgroundJob\WrittenResolutionDeadlineJob
 * @uses   \OCA\Decidiq\Service\WrittenRoundCloser
 * @uses   \OCA\Decidiq\Service\WrittenRoundStore
 * @uses   \OCA\Decidiq\Service\VotingResultCalculator
 * @uses   \OCA\Decidiq\Service\EffectiveVoteRules
 * @uses   \OCA\Decidiq\Service\VoteTally
 */
class WrittenResolutionDeadlineJobTest extends TestCase {

	/**
	 * Run the job once over the world at a time.
	 *
	 * @param WrittenRoundWorld $world The register.
	 * @param string            $now   Now.
	 *
	 * @return void
	 */
	private function runJob(WrittenRoundWorld $world, string $now): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable($now))->getTimestamp());
		$store = new WrittenRoundStore(objectService: $world->objectService());
		$job   = new WrittenResolutionDeadlineJob(
			time: $time,
			store: $store,
			closer: new WrittenRoundCloser(store: $store, calculator: new VotingResultCalculator(), logger: new NullLogger()),
			logger: new NullLogger(),
		);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Past-deadline written rounds close once with reason deadline; others are untouched.
	 *
	 * @return void
	 */
	public function testClosesPastDeadlineWrittenRoundsOnce(): void {
		$world = new WrittenRoundWorld(test: $this);
		$electorate = [['membership' => 'm-1', 'userId' => 'a', 'votingWeight' => 1], ['membership' => 'm-2', 'userId' => 'b', 'votingWeight' => 1]];
		$world->put('voting-round', ['id' => 'past', 'procedure' => 'written', 'electorate' => $electorate, 'votingDeadline' => '2026-03-13T16:00:00+00:00', 'closedAt' => null]);
		$world->put('voting-round', ['id' => 'future', 'procedure' => 'written', 'electorate' => $electorate, 'votingDeadline' => '2026-03-20T16:00:00+00:00', 'closedAt' => null]);
		$world->put('voting-round', ['id' => 'meeting', 'procedure' => 'in-meeting', 'votingDeadline' => '2026-03-01T16:00:00+00:00', 'closedAt' => null]);
		$world->put('voting-round', ['id' => 'legacy', 'votingDeadline' => '2026-03-01T16:00:00+00:00', 'closedAt' => null]);
		$world->put('vote', ['id' => 'v-1', 'votingRound' => 'past', 'membership' => 'm-1', 'value' => 'for', 'castAt' => '2026-03-10T08:00:00+00:00']);

		$this->runJob(world: $world, now: '2026-03-14T08:00:00Z');

		$this->assertSame(['past'], array_column($world->savesOf('voting-round'), 'id'));
		$this->assertSame('deadline', $world->rows['voting-round']['past']['closedReason']);
		$this->assertSame('rejected', $world->rows['voting-round']['past']['result']);
		$this->assertNull($world->rows['voting-round']['future']['closedAt']);
		$this->assertNull($world->rows['voting-round']['meeting']['closedAt']);
		$this->assertSame('written', $world->queries[0]['filters']['procedure']);

		$this->runJob(world: $world, now: '2026-03-14T09:00:00Z');

		$this->assertCount(1, $world->savesOf('voting-round'));
	}//end testClosesPastDeadlineWrittenRoundsOnce()

	/**
	 * The job is registered in info.xml.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		$this->assertContains(WrittenResolutionDeadlineJob::class, $jobs);
	}//end testTheJobIsRegistered()
}//end class
