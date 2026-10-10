<?php

/**
 * Voting follows the seat (bodies-substitute-mandate-swap task 3): who may
 * cast, the quorum and a voting group preset each ask the substitutions of
 * the meeting, through the real VoteCastingService (via SeatHolderGuard),
 * MeetingRuleSource and VotingRoundOpener.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MeetingRuleSource;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\RecusalGuard;
use OCA\Decidiq\Service\SeatHolderGuard;
use OCA\Decidiq\Service\SubstitutionResolver;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Mr Bos (seat 3) left the audit committee; Mrs De Wit holds his seat.
 *
 * @covers \OCA\Decidiq\Service\SubstitutionResolver
 * @covers \OCA\Decidiq\Service\SeatHolderGuard
 * @covers \OCA\Decidiq\Service\MeetingRuleSource
 * @covers \OCA\Decidiq\Service\VoteCastingService
 * @covers \OCA\Decidiq\Service\VotingRoundOpener
 * @uses   \OCA\Decidiq\Service\BodyQuorum
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
 * @uses   \OCA\Decidiq\Service\VoteCastGuard
 * @uses   \OCA\Decidiq\Service\VotingRoundPreflight
 * @uses   \OCA\Decidiq\Service\AmendmentOrderService
 * @uses   \OCA\Decidiq\Service\RankedBallotRules
 * @uses   \OCA\Decidiq\Service\SavedObjectNormaliser
 * @uses   \OCA\Decidiq\Service\VotingOpenedNotifier
 * @uses   \OCA\Decidiq\Service\VotingRoundRules
 * @uses   \OCA\Decidiq\Service\VoteBallotFactory
 * @uses   \OCA\Decidiq\Service\VoterTokenSecret
 */
class SubstitutionResolverTest extends TestCase {

	private const MEETING = 'm-audit';

	/**
	 * The meeting's participants.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $participants = [];

	/**
	 * Stored substitutions, for every meeting.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $substitutions = [];

	/**
	 * Stored attendance of the meeting.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $attendance = [];

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->participants = [
			['id' => 'p-visser', 'role' => 'chair', 'governanceBody' => 'body-audit'],
			['id' => 'p-bos', 'role' => 'member', 'governanceBody' => 'body-audit'],
			['id' => 'p-kaya', 'role' => 'member', 'governanceBody' => 'body-audit'],
			['id' => 'p-dewit', 'role' => 'observer', 'governanceBody' => 'body-audit'],
		];
		$this->substitutions = [
			['id' => 'sub-1', 'meeting' => self::MEETING, 'outgoingParticipant' => 'p-bos', 'incomingParticipant' => 'p-dewit', 'startedAt' => '2026-03-04T20:15:00+01:00'],
			['id' => 'sub-0', 'meeting' => 'm-earlier', 'outgoingParticipant' => 'p-kaya', 'incomingParticipant' => 'p-dewit', 'startedAt' => '2026-02-04T20:15:00+01:00'],
		];
		$this->attendance = [];
	}//end setUp()

	/**
	 * End the substitution of this meeting.
	 *
	 * @return void
	 */
	private function endTheSwap(): void {
		$this->substitutions[0]['endedAt'] = '2026-03-04T21:40:00+01:00';
	}//end endTheSwap()

	/**
	 * Wrap an array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn(($data['id'] ?? null));
		return $entity;
	}//end entity()

	/**
	 * The OpenRegister double: substitutions and attendance by schema, the meeting by id.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(
				fn (array $row): ObjectEntity => $this->entity($row),
				match ($config['filters']['schema'] ?? '') {
					'mandate-substitution' => $this->substitutions,
					'meeting-attendance' => $this->attendance,
					default => [],
				}
			)
		);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => match ((string)$id) {
				self::MEETING => $this->entity(['id' => self::MEETING, 'governanceBody' => 'body-audit', 'quorumRequired' => 3, '@self' => ['relations' => ['governanceBody' => 'body-audit']]]),
				'r-1' => $this->entity(['id' => 'r-1', 'openedAt' => '2026-03-04T20:30:00+01:00', 'votingMethod' => 'for-against-abstain']),
				default => $this->entity(['id' => (string)$id, 'decisionType' => 'motion', 'lifecycle' => 'deliberating']),
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * The participant resolver double.
	 *
	 * @return ParticipantResolver
	 */
	private function participantResolver(): ParticipantResolver {
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturnCallback(fn (): array => $this->participants);
		$resolver->method('resolveGovernanceBodyId')->willReturn(null);
		return $resolver;
	}//end participantResolver()

	/**
	 * The amendment-order double: every round belongs to the meeting.
	 *
	 * @return AmendmentOrderService
	 */
	private function order(): AmendmentOrderService {
		$order = $this->createMock(AmendmentOrderService::class);
		$order->method('resolveMeetingIdForRound')->willReturn(self::MEETING);
		return $order;
	}//end order()

	/**
	 * Whether the seat guard lets a participant cast in the meeting's round.
	 *
	 * @param string $participantId The caster
	 *
	 * @return string|null Null when accepted, else the refusal
	 */
	private function refusalFor(string $participantId): ?string {
		$guard = new SeatHolderGuard(amendmentOrder: $this->order(), participantResolver: $this->participantResolver(), objectService: $this->objectService(), logger: new NullLogger());
		try {
			$guard->assertHoldsSeat(round: ['id' => 'r-1'], participantId: $participantId);
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusalFor()

	/**
	 * Scenario "The substitute votes, the member who left cannot", and after
	 * "Mr Bos returns" the other way round. A substitution of another meeting
	 * changes nothing here.
	 *
	 * @return void
	 */
	public function testTheSubstituteCastsAndTheMemberWhoLeftCannot(): void {
		self::assertNull($this->refusalFor('p-dewit'));
		self::assertStringContainsString('plaatsvervanger', (string)$this->refusalFor('p-bos'));
		self::assertNull($this->refusalFor('p-kaya'), 'A substitution of an earlier meeting does not follow Mrs Kaya here');

		$this->endTheSwap();
		self::assertNull($this->refusalFor('p-bos'));
		self::assertNotNull($this->refusalFor('p-dewit'), 'Once the swap ended the observer does not vote');
	}//end testTheSubstituteCastsAndTheMemberWhoLeftCannot()

	/**
	 * The casting path asks the seat guard: Mr Bos's ballot through the real
	 * VoteCastingService is refused before anything is written.
	 *
	 * @return void
	 */
	public function testTheCastingPathRefusesTheMemberWhoLeft(): void {
		$objectService = $this->objectService();
		$objectService->expects($this->never())->method('saveObject');
		$caster = new VoteCastingService(
			logger: new NullLogger(),
			participantResolver: $this->participantResolver(),
			amendmentOrder: $this->order(),
			relationFilter: new ObjectRelationFilter(),
			objectService: $objectService,
			container: $this->createMock(ContainerInterface::class),
			recusal: $this->createMock(RecusalGuard::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('plaatsvervanger');
		$caster->castVote(votingRoundId: 'r-1', participantId: 'p-bos', value: 'for', isProxy: false, delegatorId: null);
	}//end testTheCastingPathRefusesTheMemberWhoLeft()

	/**
	 * Scenario "The quorum does not drop when a seat is filled": quorum 3,
	 * the chair and Mrs Kaya present, Mr Bos marked absent after he left, and
	 * Mrs De Wit, who took his seat, not on the attendance list.
	 *
	 * @return void
	 */
	public function testTheQuorumCountsAFilledSeatOnce(): void {
		$this->attendance = [
			['meeting' => self::MEETING, 'participant' => 'p-visser', 'status' => 'present'],
			['meeting' => self::MEETING, 'participant' => 'p-kaya', 'status' => 'present'],
			['meeting' => self::MEETING, 'participant' => 'p-bos', 'status' => 'absent'],
		];
		$source = new MeetingRuleSource(objectService: $this->objectService(), participantResolver: $this->participantResolver());
		self::assertTrue($source->quorumMet(meetingId: self::MEETING));

		$seats = (new SubstitutionResolver(objectService: $this->objectService()))->seatsForQuorum(
			meetingId: self::MEETING,
			participants: [['id' => 'p-visser', 'attendanceStatus' => 'present'], ['id' => 'p-bos', 'attendanceStatus' => 'absent'], ['id' => 'p-dewit', 'attendanceStatus' => 'present']]
		);
		self::assertSame([['id' => 'p-visser', 'attendanceStatus' => 'present'], ['id' => 'p-bos', 'attendanceStatus' => 'present']], $seats, 'The seat counts once');

		$this->endTheSwap();
		$source = new MeetingRuleSource(objectService: $this->objectService(), participantResolver: $this->participantResolver());
		self::assertFalse($source->quorumMet(meetingId: self::MEETING), 'Mr Bos absent and no swap: 2 of 3');
	}//end testTheQuorumCountsAFilledSeatOnce()

	/**
	 * Scenario "A voting group follows the swap".
	 *
	 * @return void
	 */
	public function testAVotingGroupFollowsTheSwap(): void {
		$rule = new MeetingRuleSource(objectService: $this->objectService(), participantResolver: $this->participantResolver());
		self::assertSame(['p-dewit', 'p-kaya'], $rule->seatHoldersFor(meetingId: self::MEETING, participantIds: ['p-bos', 'p-kaya', 'p-dewit']));

		self::assertSame(['p-dewit', 'p-kaya'], $this->votersOfARoundWith(presetIds: ['p-bos', 'p-kaya']), 'The round lists the substitute, not Mr Bos');

		$this->endTheSwap();
		self::assertSame(['p-bos', 'p-kaya'], $this->votersOfARoundWith(presetIds: ['p-bos', 'p-kaya']));
	}//end testAVotingGroupFollowsTheSwap()

	/**
	 * Open a round in the meeting with a voting group preset, through the real
	 * VotingRoundOpener, and answer the participants the saved round lists.
	 *
	 * @param list<string> $presetIds The preset's participants
	 *
	 * @return list<string>
	 */
	private function votersOfARoundWith(array $presetIds): array {
		$saved = [];
		$objectService = $this->objectService();
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity($object);
			}
		);
		$logger = new NullLogger();
		$motionService = $this->createMock(MotionService::class);
		$participants = $this->participantResolver();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));

		$opener = new VotingRoundOpener(
			motionService: $motionService,
			participantResolver: $participants,
			preflight: new VotingRoundPreflight(
				logger: $logger,
				motionService: $motionService,
				participantResolver: $participants,
				templateService: $this->createMock(ProcessTemplateService::class),
				objectService: $objectService,
			),
			notifier: new VotingOpenedNotifier(logger: $logger, participantResolver: $participants, container: $container),
			objectService: $objectService,
		);
		$opener->openVotingRound(
			motionId: 'motion-1',
			meetingId: self::MEETING,
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			presetParticipantIds: $presetIds,
			roundRules: new VotingRoundRules()
		);

		$rounds = array_values(array_filter($saved, static fn (array $o): bool => isset($o['votingMethod']) === true));
		self::assertNotSame([], $rounds, 'A round was saved');
		$relations = array_filter(($rounds[0]['relations'] ?? []), static fn (array $rel): bool => ($rel['schema'] ?? '') === 'participant');
		return array_values(array_column($relations, 'id'));
	}//end votersOfARoundWith()
}//end class
