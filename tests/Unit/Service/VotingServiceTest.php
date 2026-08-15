<?php

/**
 * Unit tests for VotingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AmendmentOrderService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ObjectRelationFilter;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\Decidesk\Service\VoteCastingService;
use OCA\Decidesk\Service\VotingOpenedNotifier;
use OCA\Decidesk\Service\VotingRoundCloser;
use OCA\Decidesk\Service\VotingRoundOpener;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\Decidesk\Service\VotingRoundProjection;
use OCA\Decidesk\Service\VotingRoundResults;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */
class VotingServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var VotingService
	 */
	private VotingService $service;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OriPublicationService.
	 *
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService&MockObject $oriService;

	/**
	 * Mock MotionService.
	 *
	 * @var MotionService&MockObject
	 */
	private MotionService&MockObject $motionService;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkipped(
			'See https://codeberg.org/Conduction/decidesk/issues/90 — '
			. 'real OpenRegister ObjectService loads instead of the stub when tests run '
			. 'in an environment with OpenRegister installed, causing signature/return-type mismatches. '
			. 'Unskip once #90 is resolved.'
		);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->oriService = $this->createMock(OriPublicationService::class);
		$this->motionService = $this->createMock(MotionService::class);

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->container
			->method('get')
			->willReturn($this->objectService);

		$participantResolver = $this->createMock(\OCA\Decidesk\Service\ParticipantResolver::class);
		$participantResolver->method('resolveMeetingParticipants')->willReturn([]);

		$templateService = $this->createMock(\OCA\Decidesk\Service\ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturn(null);

		// VotingService is a thin facade: every operation is delegated to a
		// single-purpose collaborator, so the graph is built explicitly here
		// where production relies on Nextcloud's constructor auto-wiring.
		$amendmentOrder = new AmendmentOrderService(
			motionService: $this->motionService,
			objectService: $this->objectService,
		);
		$relationFilter = new ObjectRelationFilter();

		$this->service = new VotingService(
			opener: new VotingRoundOpener(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
				preflight: new VotingRoundPreflight(
					logger: $this->logger,
					motionService: $this->motionService,
					participantResolver: $participantResolver,
					templateService: $templateService,
			objectService: $this->objectService,
		),
				notifier: new VotingOpenedNotifier(
					logger: $this->logger,
					participantResolver: $participantResolver
				),
			objectService: $this->objectService,
		),
			caster: new VoteCastingService(
				logger: $this->logger,
				participantResolver: $participantResolver,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
			objectService: $this->objectService,
		),
			closer: new VotingRoundCloser(
				logger: $this->logger,
				oriService: $this->oriService,
				motionService: $this->motionService,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
			objectService: $this->objectService,
		),
			results: new VotingRoundResults(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
			objectService: $this->objectService,
		),
			projection: new VotingRoundProjection(container: $this->container,
			objectService: $this->objectService,
		),
			participants: new ParticipantUuidLookup(container: $this->container,
			objectService: $this->objectService,
		),
		);

	}//end setUp()

	/**
	 * Test that checkQuorum returns true when active participants meet the quorum.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCheckQuorumMet(): void {
		$meeting = [
			'quorumRequired' => 3,
			'relations' => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
		];

		$participants = [
			'results' => [
				['displayName' => 'A', 'leftAt' => null],
				['displayName' => 'B', 'leftAt' => null],
				['displayName' => 'C', 'leftAt' => null],
			],
		];

		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($meeting);

		$this->objectService->expects($this->once())
			->method('findObjects')
			->willReturn($participants);

		self::assertTrue($this->service->checkQuorum('meeting-uuid'));

	}//end testCheckQuorumMet()

	/**
	 * Test that checkQuorum returns false when not enough active participants.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCheckQuorumNotMet(): void {
		$meeting = [
			'quorumRequired' => 5,
			'relations' => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
		];

		$participants = [
			'results' => [
				['displayName' => 'A', 'leftAt' => null],
				['displayName' => 'B', 'leftAt' => '2025-04-14T20:00:00+02:00'],
				['displayName' => 'C', 'leftAt' => null],
			],
		];

		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($meeting);

		$this->objectService->expects($this->once())
			->method('findObjects')
			->willReturn($participants);

		self::assertFalse($this->service->checkQuorum('meeting-uuid'));

	}//end testCheckQuorumNotMet()

	/**
	 * Test that openVotingRound throws when quorum is not met.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testOpenVotingRoundBlocksOnQuorumFailure(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Quorum niet bereikt');

		$meeting = [
			'quorumRequired' => 10,
			'relations' => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
		];

		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($meeting);

		// Only 1 active participant — quorum not met.
		$this->objectService->method('findObjects')
			->willReturn(['results' => [['displayName' => 'A', 'leftAt' => null]]]);

		$this->service->openVotingRound('motion-uuid', 'meeting-uuid', 'for-against-abstain', false, null);

	}//end testOpenVotingRoundBlocksOnQuorumFailure()

	/**
	 * Test that castVote overwrites an existing vote (duplicate update).
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCastVoteOverwritesDuplicate(): void {
		$round = [
			'openedAt' => '2025-04-14T20:05:00+02:00',
			'closedAt' => null,
		];

		$existingVote = [
			'id' => 'existing-vote-uuid',
			'value' => 'against',
			'isProxy' => false,
		];

		$savedVote = ['value' => 'for', 'isProxy' => false, 'castAt' => '2025-04-14T20:08:00+02:00'];

		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($round);

		// isProxy=false → only one findObjects call for existing vote lookup.
		$this->objectService->expects($this->once())
			->method('findObjects')
			->willReturn(['results' => [$existingVote]]);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(fn ($obj) => ($obj['value'] ?? '') === 'for'),
				$this->anything(),
			)
			->willReturn($savedVote);

		$result = $this->service->castVote('round-uuid', 'participant-uuid', 'for', false, null);
		self::assertSame('for', $result['value']);

	}//end testCastVoteOverwritesDuplicate()

	/**
	 * Test that castVote enforces one-proxy-per-round rule.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCastVoteEnforcesOneProxyPerRound(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');

		// Round must contain a proxy grant so authorization passes before the duplicate check runs.
		$round = [
			'openedAt' => '2025-04-14T20:05:00+02:00',
			'closedAt' => null,
			'notes' => [
				[
					'title' => 'Proxy',
					'body' => json_encode([
						'fromParticipantId' => 'delegator-uuid',
						'toParticipantId' => 'delegate-uuid',
						'votingRoundId' => 'round-uuid',
						'grantedAt' => '2025-04-14T19:00:00+02:00',
					]),
				],
			],
		];

		$existingProxyVote = [
			'id' => 'proxy-vote-uuid',
			'value' => 'for',
			'isProxy' => true,
			'relations' => [
				['schema' => 'voting-round', 'id' => 'round-uuid'],
				['schema' => 'participant',  'id' => 'delegator-uuid', 'type' => 'delegator'],
			],
		];

		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($round);

		// findObjects call is for the duplicate proxy check — returns an existing proxy.
		$this->objectService->expects($this->once())
			->method('findObjects')
			->willReturn(['results' => [$existingProxyVote]]);

		$this->service->castVote('round-uuid', 'delegate-uuid', 'for', true, 'delegator-uuid');

	}//end testCastVoteEnforcesOneProxyPerRound()

	/**
	 * Test that tallyResults returns 'adopted' when votes for exceed votes against.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsAdopted(): void {
		$votes = [
			'results' => [
				['value' => 'for',     'weight' => 1],
				['value' => 'for',     'weight' => 1],
				['value' => 'against', 'weight' => 1],
			],
		];

		$round = ['openedAt' => '2025-04-14T20:05:00+02:00'];

		$this->objectService->method('findObjects')->willReturn($votes);
		$this->objectService->method('getObject')->willReturn($round);
		$this->objectService->method('saveObject')->willReturn($round);

		$result = $this->service->tallyResults('round-uuid');

		self::assertSame('adopted', $result['result']);
		self::assertSame(2, $result['votesFor']);
		self::assertSame(1, $result['votesAgainst']);

	}//end testTallyResultsAdopted()

	/**
	 * Test that tallyResults returns 'rejected' when votes against exceed votes for.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsRejected(): void {
		$votes = [
			'results' => [
				['value' => 'for',     'weight' => 1],
				['value' => 'against', 'weight' => 1],
				['value' => 'against', 'weight' => 1],
			],
		];

		$round = ['openedAt' => '2025-04-14T20:05:00+02:00'];

		$this->objectService->method('findObjects')->willReturn($votes);
		$this->objectService->method('getObject')->willReturn($round);
		$this->objectService->method('saveObject')->willReturn($round);

		$result = $this->service->tallyResults('round-uuid');

		self::assertSame('rejected', $result['result']);

	}//end testTallyResultsRejected()

	/**
	 * Test that tallyResults returns 'tied' when votes for equal votes against
	 * AND the round's tie-break rule is one that yields a tie.
	 *
	 * ⚠️ `tieBreakRule` is load-bearing here, not decoration. This test used to
	 * pass a round of `['openedAt' => …]` only and still assert 'tied' — but
	 * with no stored rule VotingResultCalculator falls back to the spec default
	 * `rejected`, so 'tied' is the one answer that round CANNOT produce
	 * (openspec/specs/voting-system/spec.md, "Handle a tie vote": *with
	 * `rejected` (default) the result MUST be "rejected" … with `chair-decides`
	 * or `revote` the result MUST be "tied"*).
	 *
	 * It was never caught because this whole class is markTestSkipped() in
	 * setUp (issue #90) — a skip is not a pass. The same wrong expectation was
	 * copied into tests/e2e/workflows/voting-quorum-workflow.spec.ts, where it
	 * DOES run, and it failed every full-scope run while the production
	 * calculator was correct throughout.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsTied(): void {
		$votes = [
			'results' => [
				['value' => 'for',     'weight' => 1],
				['value' => 'against', 'weight' => 1],
			],
		];

		$round = [
			'openedAt' => '2025-04-14T20:05:00+02:00',
			'tieBreakRule' => 'revote',
		];

		$this->objectService->method('findObjects')->willReturn($votes);
		$this->objectService->method('getObject')->willReturn($round);
		$this->objectService->method('saveObject')->willReturn($round);

		$result = $this->service->tallyResults('round-uuid');

		self::assertSame('tied', $result['result']);

	}//end testTallyResultsTied()

	/**
	 * Test that closeVotingRound closes the round and triggers motion lifecycle update.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCloseVotingRoundTransitionsLifecycle(): void {
		$round = [
			'openedAt' => '2025-04-14T20:05:00+02:00',
			'closedAt' => null,
			'relations' => [['schema' => 'motion', 'id' => 'motion-uuid']],
		];

		$motion = ['lifecycle' => 'voting', 'title' => 'Test Motion'];

		// Return round for tally + close + getObject, motion for lifecycle update.
		$this->objectService->method('getObject')
			->willReturnCallback(function () use ($round, $motion) {
				static $calls = 0;
				$calls++;
				// Third getObject call (after tally and close) fetches the motion.
				if ($calls === 3) {
					return $motion;
				}
				return $round;
			});

		$this->objectService->method('findObjects')
			->willReturn(['results' => [['value' => 'against', 'weight' => 1]]]);

		$this->objectService->method('saveObject')->willReturn($round);

		$this->oriService->method('publish');

		// Should complete without throwing.
		$this->service->closeVotingRound('round-uuid');
		$this->addToAssertionCount(1);

	}//end testCloseVotingRoundTransitionsLifecycle()

	// The proxy (volmacht) delegation rules moved to ProxyDelegationService
	// together with grantProxy()/revokeProxy(); they are covered — and, unlike
	// this whole class, actually EXECUTED — by ProxyDelegationServiceTest.
}//end class
