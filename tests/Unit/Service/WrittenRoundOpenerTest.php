<?php

/**
 * Tests for WrittenRoundOpener.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\WrittenElectorateResolver;
use OCA\Decidiq\Service\WrittenRoundOpener;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who may put a decision in writing, on which decision, and what the round stores.
 *
 * @covers \OCA\Decidiq\Service\WrittenRoundOpener
 * @uses   \OCA\Decidiq\Service\WrittenRoundStore
 * @uses   \OCA\Decidiq\Service\WrittenElectorateResolver
 * @uses   \OCA\Decidiq\Service\GovernanceScopeGuard
 */
class WrittenRoundOpenerTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var WrittenRoundWorld
	 */
	private WrittenRoundWorld $world;

	/**
	 * Group checks asked: [user, group].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $groupChecks = [];

	/**
	 * Set up the board, its members and a proposed decision without a meeting.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = new WrittenRoundWorld(test: $this);
		foreach (['janneke' => 'chair', 'jan' => 'secretary', 'mark' => 'member'] as $user => $role) {
			$this->world->put('person', ['id' => 'p-' . $user, 'name' => ucfirst($user), 'nextcloudUserId' => $user]);
			$this->world->put('membership', ['id' => 'm-' . $user, 'governanceBody' => 'board', 'person' => 'p-' . $user, 'role' => $role, 'votingWeight' => 1, 'startDate' => '2025-01-01']);
		}

		$this->world->put('decision', ['id' => 'd-auditor', 'title' => 'Appoint the external auditor for 2026', 'lifecycle' => 'proposed']);
	}//end setUp()

	/**
	 * The opener, with `jan` the only signatory of the board, at 9 March 2026 09:00 UTC.
	 *
	 * @return WrittenRoundOpener
	 */
	private function opener(): WrittenRoundOpener {
		$objects = $this->world->objectService();
		$groups  = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $user, string $group): bool {
				$this->groupChecks[] = [$user, $group];
				return $user === 'jan' && $group === 'decidesk:body:board:signatory';
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-03-09T09:00:00Z'))->getTimestamp());
		$store = new WrittenRoundStore(objectService: $objects);

		return new WrittenRoundOpener(
			store: $store,
			electorate: new WrittenElectorateResolver(store: $store),
			scopes: new GovernanceScopeGuard(groupManager: $groups, logger: new NullLogger(), objectService: $objects),
			time: $time,
		);
	}//end opener()

	/**
	 * A valid request.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(array $extra=[]): array {
		return array_merge(['procedure' => 'written', 'decisionId' => 'd-auditor', 'governanceBodyId' => 'board', 'votingDeadline' => '2026-03-13T17:00:00+01:00'], $extra);
	}//end request()

	/**
	 * The secretary sends a resolution to the board (REQ-WRS-001, first scenario).
	 *
	 * @return void
	 */
	public function testTheSecretaryOpensAWrittenRound(): void {
		$round = $this->opener()->open(params: self::request(), userId: 'jan');

		$this->assertSame([['jan', 'decidesk:body:board:signatory']], $this->groupChecks);
		$this->assertSame('written', $round['procedure']);
		$this->assertSame('d-auditor', $round['decision']);
		$this->assertSame('board', $round['governanceBody']);
		$this->assertSame('unanimous', $round['writtenRule']);
		$this->assertSame('2026-03-13T17:00:00+01:00', $round['votingDeadline']);
		$this->assertSame('2026-03-09T09:00:00+00:00', $round['openedAt']);
		$this->assertNull($round['closedAt']);
		$this->assertSame(['janneke', 'jan', 'mark'], $round['electorateUserIds']);
		$this->assertSame(
			['membership' => 'm-janneke', 'person' => 'p-janneke', 'userId' => 'janneke', 'votingWeight' => 1.0],
			$round['electorate'][0]
		);
		$this->assertArrayNotHasKey('decisionStage', $round);
		$this->assertCount(1, $this->world->savesOf('voting-round'));
	}//end testTheSecretaryOpensAWrittenRound()

	/**
	 * An ordinary member is refused and nothing is stored (second scenario).
	 *
	 * @return void
	 */
	public function testAMemberOutsideTheSignatoryScopeIsRefused(): void {
		try {
			$this->opener()->open(params: self::request(), userId: 'mark');
			$this->fail('An ordinary member opened a written round.');
		} catch (AccessDeniedException $e) {
			$this->assertStringContainsString('chair, vice-chair or secretary', $e->getMessage());
		}

		$this->assertSame([], $this->world->saves);
	}//end testAMemberOutsideTheSignatoryScopeIsRefused()

	/**
	 * A member without a Nextcloud account blocks the open and is named (third scenario).
	 *
	 * @return void
	 */
	public function testAMemberWhoCannotAnswerBlocksTheOpen(): void {
		$this->world->put('person', ['id' => 'p-mark', 'name' => 'Mark van den Berg']);

		try {
			$this->opener()->open(params: self::request(), userId: 'jan');
			$this->fail('A round opened with an unreachable member.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('These members have no Nextcloud account and cannot answer: Mark van den Berg', $e->getMessage());
		}

		$this->assertSame([], $this->world->saves);
	}//end testAMemberWhoCannotAnswerBlocksTheOpen()

	/**
	 * Every malformed request is a 400 with its reason, before any lookup.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function badRequests(): array {
		return [
			'a meeting' => [['meetingId' => 'meeting-1'], 'A written round has no meeting: leave meetingId out.'],
			'no body' => [['governanceBodyId' => ''], 'decisionId and governanceBodyId are required for a written round.'],
			'no decision' => [['decisionId' => ''], 'decisionId and governanceBodyId are required for a written round.'],
			'no deadline' => [['votingDeadline' => ''], 'votingDeadline is required for a written round.'],
			'a past deadline' => [['votingDeadline' => '2026-03-09T08:59:00Z'], 'The deadline of a written round must be in the future.'],
			'an unknown rule' => [['writtenRule' => 'most'], 'writtenRule must be one of: unanimous, threshold'],
		];
	}//end badRequests()

	/**
	 * A malformed request is refused with its reason and stores nothing.
	 *
	 * @param array<string, mixed> $extra   The bad part.
	 * @param string               $message The reason.
	 *
	 * @dataProvider badRequests
	 *
	 * @return void
	 */
	public function testABadRequestIsRefused(array $extra, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		try {
			$this->opener()->open(params: self::request(extra: $extra), userId: 'jan');
		} finally {
			$this->assertSame([], $this->groupChecks);
			$this->assertSame([], $this->world->saves);
		}
	}//end testABadRequestIsRefused()

	/**
	 * A decision of a meeting, or one past deliberation, is not put in writing.
	 *
	 * @return void
	 */
	public function testTheDecisionMustBeOpenAndWithoutAMeeting(): void {
		$this->world->put('decision', ['id' => 'd-meeting', 'lifecycle' => 'proposed', 'meeting' => 'meeting-1']);
		$this->world->put('decision', ['id' => 'd-decided', 'lifecycle' => 'decided']);

		foreach (['d-meeting' => 'belongs to a meeting', 'd-decided' => 'Only a proposed or deliberating decision', 'd-missing' => 'does not exist'] as $decision => $reason) {
			try {
				$this->opener()->open(params: self::request(extra: ['decisionId' => $decision]), userId: 'jan');
				$this->fail($decision . ' was put in writing.');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($reason, $e->getMessage());
			}
		}

		$this->assertSame([], $this->world->saves);
	}//end testTheDecisionMustBeOpenAndWithoutAMeeting()

	/**
	 * The decision's active vote stage for the same body is resolved by the round;
	 * a stage of another body or one not active is not.
	 *
	 * @return void
	 */
	public function testTheActiveVoteStageOfTheBodyIsLinked(): void {
		$this->world->put('decision-stage', ['id' => 's-other', 'decision' => 'd-auditor', 'status' => 'active', 'method' => 'vote', 'assignedBody' => 'other']);
		$this->world->put('decision-stage', ['id' => 's-pending', 'decision' => 'd-auditor', 'status' => 'pending', 'method' => 'vote', 'assignedBody' => 'board']);
		$this->world->put('decision-stage', ['id' => 's-board', 'decision' => 'd-auditor', 'status' => 'active', 'method' => 'vote', 'assignedBody' => 'board']);

		$round = $this->opener()->open(params: self::request(extra: ['writtenRule' => 'threshold', 'voteThreshold' => 'qualified-majority-two-thirds']), userId: 'jan');

		$this->assertSame('s-board', $round['decisionStage']);
		$this->assertSame('threshold', $round['writtenRule']);
		$this->assertSame('qualified-majority-two-thirds', $round['voteThreshold']);
	}//end testTheActiveVoteStageOfTheBodyIsLinked()
}//end class
