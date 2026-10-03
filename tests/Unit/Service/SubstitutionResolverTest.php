<?php

/**
 * Voting follows the seat (bodies-substitute-mandate-swap task 3): who may
 * cast, the quorum and a voting group preset each ask the substitutions of
 * the meeting, through the real VoteCastGuard, MeetingRuleSource and
 * VotingRoundPreflight.
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
use OCA\Decidiq\Service\SubstitutionResolver;
use OCA\Decidiq\Service\VoteCastGuard;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\Decidiq\Service\VotingRoundPreflight;
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
 * @covers \OCA\Decidiq\Service\VoteCastGuard
 * @covers \OCA\Decidiq\Service\MeetingRuleSource
 * @covers \OCA\Decidiq\Service\VotingRoundPreflight
 * @uses   \OCA\Decidiq\Service\BodyQuorum
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
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
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => $id === self::MEETING ? $this->entity(['id' => self::MEETING, 'governanceBody' => 'body-audit', 'quorumRequired' => 3]) : null
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
	 * The real cast guard, its round resolving to the meeting.
	 *
	 * @return VoteCastGuard
	 */
	private function guard(): VoteCastGuard {
		$order = $this->createMock(AmendmentOrderService::class);
		$order->method('resolveMeetingIdForRound')->willReturn(self::MEETING);

		return new VoteCastGuard(
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			relationFilter: $this->createMock(ObjectRelationFilter::class),
			tokens: $this->createMock(VoterTokenSecret::class),
			participantResolver: $this->participantResolver(),
			amendmentOrder: $order,
			objectService: $this->objectService(),
		);
	}//end guard()

	/**
	 * Whether the guard lets a participant cast in the meeting's round.
	 *
	 * @param string $participantId The caster
	 *
	 * @return string|null Null when accepted, else the refusal
	 */
	private function refusalFor(string $participantId): ?string {
		try {
			$this->guard()->assertMeetingMembership(round: ['id' => 'r-1'], participantId: $participantId);
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
		$preflight = new VotingRoundPreflight(
			logger: new NullLogger(),
			motionService: $this->createMock(MotionService::class),
			participantResolver: $this->participantResolver(),
			templateService: $this->createMock(ProcessTemplateService::class),
			objectService: $this->objectService(),
		);

		$split = $preflight->splitPresetParticipants(meetingId: self::MEETING, presetIds: ['p-bos', 'p-kaya']);
		self::assertSame(['p-dewit', 'p-kaya'], array_values($split['eligible']));
		self::assertSame([], $split['excluded']);

		$this->endTheSwap();
		$split = $preflight->splitPresetParticipants(meetingId: self::MEETING, presetIds: ['p-bos', 'p-kaya']);
		self::assertSame(['p-bos', 'p-kaya'], array_values($split['eligible']));
	}//end testAVotingGroupFollowsTheSwap()
}//end class
