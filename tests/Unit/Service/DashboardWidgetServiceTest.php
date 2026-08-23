<?php

/**
 * Unit tests for DashboardWidgetService.
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
 * @spec openspec/specs/dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DashboardWidgetService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests per-user pending-votes resolution, next-meeting resolution, the
 * no-participant-record zero case, and fail-soft on a broken register.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DashboardWidgetServiceTest extends TestCase {

	/**
	 * Fixed "now": 2026-06-13T12:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1781352000;

	/**
	 * Build the service over fixture collections.
	 *
	 * When $throw is true the fake ObjectService throws on every findAll, to
	 * exercise the fail-soft path.
	 *
	 * @param array<int, array<string, mixed>> $participants Participant rows
	 * @param array<int, array<string, mixed>> $rounds voting-round rows
	 * @param array<int, array<string, mixed>> $votes vote rows
	 * @param array<int, array<string, mixed>> $meetings meeting rows
	 * @param bool $throw Force findAll to throw
	 *
	 * @return DashboardWidgetService
	 */
	private function makeService(
		array $participants = [],
		array $rounds = [],
		array $votes = [],
		array $meetings = [],
		bool $throw = false,
	): DashboardWidgetService {
		$objectService = new class($participants, $rounds, $votes, $meetings, $throw) {

			/**
			 * @param array<int, array<string, mixed>> $participants Participant rows
			 * @param array<int, array<string, mixed>> $rounds voting-round rows
			 * @param array<int, array<string, mixed>> $votes vote rows
			 * @param array<int, array<string, mixed>> $meetings meeting rows
			 * @param bool $throw Throw flag
			 */
			public function __construct(
				private array $participants,
				private array $rounds,
				private array $votes,
				private array $meetings,
				private bool $throw,
			) {
			}

			/**
			 * Schema-routed findAll fixture.
			 *
			 * @param array<string, mixed> $config Query config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				if ($this->throw === true) {
					throw new \RuntimeException('register unavailable');
				}

				return match ($config['schema'] ?? '') {
					'participant' => $this->participants,
					'voting-round' => $this->rounds,
					'vote' => $this->votes,
					'meeting' => $this->meetings,
					default => [],
				};

			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new DashboardWidgetService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * Open rounds the user has not voted in are counted; voted ones excluded.
	 *
	 * @return void
	 */
	public function testCountsOnlyOpenRoundsNotYetVoted(): void {
		$service = $this->makeService(
			participants: [['id' => 'p1', 'nextcloudUserId' => 'alice']],
			rounds: [
				['id' => 'r1', 'lifecycle' => 'open'],
				['id' => 'r2', 'lifecycle' => 'open'],
				['id' => 'r3', 'closedAt' => '2026-06-01T00:00:00Z'],
			],
			votes: [['id' => 'v1', 'participant' => 'p1', 'votingRound' => 'r1']],
		);

		$summary = $service->getUserSummary(userId: 'alice', now: self::NOW);

		// r1 voted, r2 pending, r3 closed ⇒ 1 pending.
		$this->assertSame(1, $summary['pendingVotes']);

	}//end testCountsOnlyOpenRoundsNotYetVoted()

	/**
	 * A user with no participant record sees zero pending votes.
	 *
	 * @return void
	 */
	public function testNoParticipantRecordYieldsZero(): void {
		$service = $this->makeService(
			participants: [['id' => 'p1', 'nextcloudUserId' => 'bob']],
			rounds: [['id' => 'r1', 'lifecycle' => 'open']],
			votes: [],
		);

		$summary = $service->getUserSummary(userId: 'alice', now: self::NOW);

		$this->assertSame(0, $summary['pendingVotes']);
		$this->assertNull($summary['nextMeeting']);

	}//end testNoParticipantRecordYieldsZero()

	/**
	 * The next meeting is the soonest future scheduled meeting the user is in.
	 *
	 * @return void
	 */
	public function testNextMeetingIsSoonestFutureParticipated(): void {
		$service = $this->makeService(
			participants: [
				['id' => 'p1', 'nextcloudUserId' => 'alice', 'meeting' => 'm2'],
				['id' => 'p2', 'nextcloudUserId' => 'alice', 'meeting' => 'm3'],
			],
			meetings: [
				['id' => 'm1', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-06-14T10:00:00Z', 'title' => 'Not mine'],
				['id' => 'm2', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-06-20T10:00:00Z', 'title' => 'Later'],
				['id' => 'm3', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-06-15T10:00:00Z', 'title' => 'Soonest mine'],
				['id' => 'm4', 'lifecycle' => 'concluded', 'scheduledDate' => '2026-06-13T13:00:00Z', 'title' => 'Concluded'],
			],
		);

		$summary = $service->getUserSummary(userId: 'alice', now: self::NOW);

		$this->assertIsArray($summary['nextMeeting']);
		$this->assertSame('m3', $summary['nextMeeting']['id']);
		$this->assertSame('Soonest mine', $summary['nextMeeting']['title']);

	}//end testNextMeetingIsSoonestFutureParticipated()

	/**
	 * Past scheduled meetings are ignored even when the user participates.
	 *
	 * @return void
	 */
	public function testPastMeetingsAreIgnored(): void {
		$service = $this->makeService(
			participants: [['id' => 'p1', 'nextcloudUserId' => 'alice', 'meeting' => 'm1']],
			meetings: [
				['id' => 'm1', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-06-01T10:00:00Z', 'title' => 'Past'],
			],
		);

		$summary = $service->getUserSummary(userId: 'alice', now: self::NOW);

		$this->assertNull($summary['nextMeeting']);

	}//end testPastMeetingsAreIgnored()

	/**
	 * A broken register fails soft to a zero/empty summary, no exception.
	 *
	 * @return void
	 */
	public function testFailsSoftOnBrokenRegister(): void {
		$service = $this->makeService(throw: true);

		$summary = $service->getUserSummary(userId: 'alice', now: self::NOW);

		$this->assertSame(0, $summary['pendingVotes']);
		$this->assertNull($summary['nextMeeting']);

	}//end testFailsSoftOnBrokenRegister()

	/**
	 * An empty user id short-circuits to a zero/empty summary.
	 *
	 * @return void
	 */
	public function testEmptyUserIdYieldsEmptySummary(): void {
		$service = $this->makeService(
			participants: [['id' => 'p1', 'nextcloudUserId' => 'alice']],
			rounds: [['id' => 'r1', 'lifecycle' => 'open']],
		);

		$summary = $service->getUserSummary(userId: '', now: self::NOW);

		$this->assertSame(0, $summary['pendingVotes']);
		$this->assertNull($summary['nextMeeting']);

	}//end testEmptyUserIdYieldsEmptySummary()
}//end class
