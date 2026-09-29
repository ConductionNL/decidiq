<?php

/**
 * Unit tests for RecusalGuard and its wiring into the vote-casting path.
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
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidiq\Service\ConflictOfInterestService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\Decidiq\Service\RecusalGuard;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Anna owns land in the plan area of motion M-12 and is recused from its vote.
 *
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */
class RecusalGuardTest extends TestCase {

	/**
	 * Objects by id: the round, the motion, an amendment and the agenda item.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Stored conflict-of-interest rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $declarations = [];

	/**
	 * Captured ballots.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'round-1' => [
				'id' => 'round-1',
				'openedAt' => '2026-09-29T19:00:00Z',
				'closedAt' => null,
				'isSecret' => false,
				'relations' => [['schema' => 'motion', 'id' => 'M-12']],
			],
			'round-2' => [
				'id' => 'round-2',
				'openedAt' => '2026-09-29T19:00:00Z',
				'closedAt' => null,
				'isSecret' => false,
				'relations' => [['schema' => 'amendment', 'id' => 'A-1']],
			],
			'M-12' => ['id' => 'M-12', 'meeting' => 'meeting-1', 'agendaItem' => 'item-7'],
			'A-1' => ['id' => 'A-1', 'amends' => 'M-12'],
			'item-7' => ['id' => 'item-7', 'meeting' => 'meeting-1'],
		];
		$this->declarations = [];
		$this->saved = [];
	}//end setUp()

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
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The OpenRegister double over the fixture.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($this->objects[$id]) === true ? $this->entity($this->objects[$id]) : null
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['filters']['schema'] ?? '') !== 'conflict-of-interest') {
					return [];
				}

				return array_map(fn (array $row): ObjectEntity => $this->entity($row), $this->declarations);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * The real ConflictOfInterestService over the double.
	 *
	 * @param ObjectServiceInterface                $objectService The double
	 * @param ParticipantToPersonMembershipResolver $crosswalk     The crosswalk double
	 *
	 * @return ConflictOfInterestService
	 */
	private function conflicts(ObjectServiceInterface $objectService, ParticipantToPersonMembershipResolver $crosswalk): ConflictOfInterestService {
		$logger = new NullLogger();
		return new ConflictOfInterestService(
			logger: $logger,
			auditLogService: $this->createMock(AuditLogService::class),
			objectService: $objectService,
			authorizationGuard: new ConflictOfInterestAuthorizationGuard(
				logger: $logger,
				objectService: $objectService,
				participantResolver: $this->createMock(ParticipantResolver::class),
				participantCrosswalk: $crosswalk,
			),
		);
	}//end conflicts()

	/**
	 * Participant P-anna is Membership M-anna, P-bert is M-bert.
	 *
	 * @return ParticipantToPersonMembershipResolver
	 */
	private function crosswalk(): ParticipantToPersonMembershipResolver {
		$crosswalk = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$crosswalk->method('resolve')->willReturnCallback(
			static fn (string $participantId): array => [
				'person' => str_replace('P-', 'person-', $participantId),
				'membership' => str_replace('P-', 'M-', $participantId),
			]
		);
		return $crosswalk;
	}//end crosswalk()

	/**
	 * The guard as production builds it.
	 *
	 * @return RecusalGuard
	 */
	private function guard(): RecusalGuard {
		$objectService = $this->objectService();
		$crosswalk = $this->crosswalk();
		return new RecusalGuard(
			conflicts: $this->conflicts(objectService: $objectService, crosswalk: $crosswalk),
			participantCrosswalk: $crosswalk,
			objectService: $objectService,
			amendmentOrder: new AmendmentOrderService(
				motionService: $this->createMock(MotionService::class),
				objectService: $objectService,
			),
		);
	}//end guard()

	/**
	 * Store a declaration.
	 *
	 * @param string $member  The boardMember id
	 * @param string $subject The agendaItem (subject) id
	 * @param string $action  The action taken
	 *
	 * @return void
	 */
	private function declare(string $member, string $subject, string $action): void {
		$this->declarations[] = [
			'id' => 'decl-' . count($this->declarations),
			'boardMember' => $member,
			'agendaItem' => $subject,
			'declarationType' => 'financial-interest',
			'description' => 'Owns land in the plan area',
			'severity' => 'material',
			'actionTaken' => $action,
		];
	}//end declare()

	/**
	 * A recusal on the motion refuses Anna's ballot and names her declaration.
	 *
	 * @return void
	 */
	public function testAMemberRecusedOnTheMotionCannotVote(): void {
		$this->declare(member: 'M-anna', subject: 'M-12', action: 'recused-from-vote');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Owns land in the plan area');
		$this->guard()->assertNotRecused(round: $this->objects['round-1'], participantId: 'P-anna');
	}//end testAMemberRecusedOnTheMotionCannotVote()

	/**
	 * A recusal on the agenda item the motion is tabled under counts too.
	 *
	 * @return void
	 */
	public function testARecusalOnTheAgendaItemCoversItsMotion(): void {
		$this->declare(member: 'M-anna', subject: 'item-7', action: 'recused-from-discussion');

		$this->expectException(RuntimeException::class);
		$this->guard()->assertNotRecused(round: $this->objects['round-1'], participantId: 'P-anna');
	}//end testARecusalOnTheAgendaItemCoversItsMotion()

	/**
	 * A recusal on a motion covers the rounds on its amendments.
	 *
	 * @return void
	 */
	public function testARecusalOnTheMotionCoversItsAmendments(): void {
		$this->declare(member: 'M-anna', subject: 'M-12', action: 'recused-from-vote');

		$this->expectException(RuntimeException::class);
		$this->guard()->assertNotRecused(round: $this->objects['round-2'], participantId: 'P-anna');
	}//end testARecusalOnTheMotionCoversItsAmendments()

	/**
	 * A declaration made before boardMember moved to Membership names the Participant.
	 *
	 * @return void
	 */
	public function testADeclarationKeyedOnTheParticipantStillCounts(): void {
		$this->declare(member: 'P-anna', subject: 'M-12', action: 'recused-from-vote');

		$this->expectException(RuntimeException::class);
		$this->guard()->assertNotRecused(round: $this->objects['round-1'], participantId: 'P-anna');
	}//end testADeclarationKeyedOnTheParticipantStillCounts()

	/**
	 * Disclosed-and-participated, another member's recusal or another motion do not refuse.
	 *
	 * @return void
	 */
	public function testOnlyARecusalOfThisMemberOnThisMatterRefuses(): void {
		$this->declare(member: 'M-anna', subject: 'M-12', action: 'disclosed-and-participated');
		$this->declare(member: 'M-bert', subject: 'M-12', action: 'recused-from-vote');
		$this->declare(member: 'M-anna', subject: 'M-99', action: 'recused-from-vote');

		$this->guard()->assertNotRecused(round: $this->objects['round-1'], participantId: 'P-anna');
		$this->addToAssertionCount(1);
	}//end testOnlyARecusalOfThisMemberOnThisMatterRefuses()

	/**
	 * The casting path consults the guard: no ballot is written for a recused member.
	 *
	 * @return void
	 */
	public function testCastingRefusesARecusedMemberAndWritesNoBallot(): void {
		$this->declare(member: 'M-anna', subject: 'M-12', action: 'recused-from-vote');
		$objectService = $this->objectService();
		$crosswalk = $this->crosswalk();
		$amendmentOrder = new AmendmentOrderService(
			motionService: $this->createMock(MotionService::class),
			objectService: $objectService,
		);
		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturn([['id' => 'P-anna'], ['id' => 'P-bert']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$caster = new VoteCastingService(
			logger: new NullLogger(),
			participantResolver: $participants,
			amendmentOrder: $amendmentOrder,
			relationFilter: new ObjectRelationFilter(),
			objectService: $objectService,
			container: $container,
			recusal: new RecusalGuard(
				conflicts: $this->conflicts(objectService: $objectService, crosswalk: $crosswalk),
				participantCrosswalk: $crosswalk,
				objectService: $objectService,
				amendmentOrder: $amendmentOrder,
			),
		);

		try {
			$caster->castVote(votingRoundId: 'round-1', participantId: 'P-anna', value: 'for', isProxy: false, delegatorId: null);
			$this->fail('A recused member cast a ballot.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('recused from the vote', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testCastingRefusesARecusedMemberAndWritesNoBallot()
}//end class
