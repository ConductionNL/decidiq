<?php

/**
 * Unit tests for ConflictOfInterestAuthorizationGuard: who may record or read a
 * conflict-of-interest declaration, and who may record the action taken.
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
 * @spec openspec/specs/conflict-of-interest-authorization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed authorization matrix of the conflict-of-interest guard.
 *
 * @spec openspec/specs/conflict-of-interest-authorization/spec.md
 */
#[CoversClass(ConflictOfInterestAuthorizationGuard::class)]
#[UsesClass(ParticipantUuidLookup::class)]
class ConflictOfInterestAuthorizationGuardTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The role resolver double.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * The participant crosswalk double.
	 *
	 * @var ParticipantToPersonMembershipResolver&MockObject
	 */
	private ParticipantToPersonMembershipResolver&MockObject $crosswalk;

	/**
	 * Objects served by find(), keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = ['agenda-item' => [], 'decision' => []];

	/**
	 * Participant uuid per Nextcloud uid.
	 *
	 * @var array<string, string>
	 */
	private array $participants = ['anna' => 'P-anna', 'bob' => 'P-bob'];

	/**
	 * The guard under test.
	 *
	 * @var ConflictOfInterestAuthorizationGuard
	 */
	private ConflictOfInterestAuthorizationGuard $guard;

	/**
	 * Build the guard over the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(FindCallShape::legacy(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntity {
				if ($id === 'explodes') {
					throw new \RuntimeException('database gone');
				}

				$row = ($this->objects[(string)$schema][(string)$id] ?? null);
				if ($register !== 'decidiq' || $row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		));
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$uid = ($config['filters']['nextcloudUserId'] ?? '');
				if (isset($this->participants[$uid]) === false) {
					return [];
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['uuid' => $this->participants[$uid]]);
				return [$entity];
			}
		);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->crosswalk           = $this->createMock(ParticipantToPersonMembershipResolver::class);

		$this->guard = new ConflictOfInterestAuthorizationGuard(
			logger: $this->logger,
			objectService: $this->objectService,
			participantResolver: $this->participantResolver,
			participantCrosswalk: $this->crosswalk,
		);
	}//end setUp()

	/**
	 * The declaring member is authorized without a role check.
	 *
	 * @return void
	 */
	public function testDeclaringMemberIsAuthorized(): void {
		$this->crosswalk->expects($this->once())->method('resolve')->with('P-anna')->willReturn(['person' => 'person-anna', 'membership' => 'M-anna']);
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertTrue($this->guard->isAuthorizedForMember(membershipId: 'M-anna', agendaItemId: 'ai-1', callerUid: 'anna'));
	}//end testDeclaringMemberIsAuthorized()

	/**
	 * A chair of the agenda item's meeting is authorized for another member.
	 *
	 * @return void
	 */
	public function testChairOfMeetingIsAuthorizedForAnotherMember(): void {
		$this->objects['agenda-item']['ai-1'] = ['id' => 'ai-1', 'meeting' => 'meeting-1'];
		$this->crosswalk->method('resolve')->willReturn(null);
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with('meeting-1', 'bob', ['chair', 'secretary'])
			->willReturn(true);

		$this->assertTrue($this->guard->isAuthorizedForMember(membershipId: 'M-anna', agendaItemId: 'ai-1', callerUid: 'bob'));
	}//end testChairOfMeetingIsAuthorizedForAnotherMember()

	/**
	 * Another member without a role on the meeting is refused.
	 *
	 * @return void
	 */
	public function testOtherMemberWithoutRoleIsRefused(): void {
		$this->objects['agenda-item']['ai-1'] = ['id' => 'ai-1', 'meeting' => 'meeting-1'];
		$this->crosswalk->method('resolve')->willReturn(['person' => 'person-bob', 'membership' => 'M-bob']);
		$this->participantResolver->expects($this->once())->method('hasRole')->willReturn(false);

		$this->assertFalse($this->guard->isAuthorizedForMember(membershipId: 'M-anna', agendaItemId: 'ai-1', callerUid: 'bob'));
	}//end testOtherMemberWithoutRoleIsRefused()

	/**
	 * A caller without participant record and no agenda item is refused without any role lookup.
	 *
	 * @return void
	 */
	public function testUnknownCallerWithoutAgendaItemIsRefused(): void {
		$this->crosswalk->expects($this->never())->method('resolve');
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertFalse($this->guard->isAuthorizedForMember(membershipId: 'M-anna', agendaItemId: '', callerUid: 'stranger'));
	}//end testUnknownCallerWithoutAgendaItemIsRefused()

	/**
	 * Recording an action needs an agenda item on the declaration.
	 *
	 * @return void
	 */
	public function testRecordActionWithoutAgendaItemIsRefused(): void {
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['boardMember' => 'M-anna'], callerUid: 'bob'));
	}//end testRecordActionWithoutAgendaItemIsRefused()

	/**
	 * Recording an action on an unknown subject is refused.
	 *
	 * @return void
	 */
	public function testRecordActionOnUnknownSubjectIsRefused(): void {
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'nothing'], callerUid: 'bob'));
	}//end testRecordActionOnUnknownSubjectIsRefused()

	/**
	 * A motion subject falls back to the decision schema and its expanded meeting.
	 *
	 * @return void
	 */
	public function testRecordActionOnMotionUsesDecisionMeeting(): void {
		$this->objects['decision']['motion-1'] = ['id' => 'motion-1', 'meeting' => ['id' => 'meeting-7']];
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with('meeting-7', 'bob', ['chair', 'secretary'])
			->willReturn(true);

		$this->assertTrue($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'motion-1'], callerUid: 'bob'));
	}//end testRecordActionOnMotionUsesDecisionMeeting()

	/**
	 * A failing lookup is logged and fails closed.
	 *
	 * @return void
	 */
	public function testLookupFailureIsLoggedAndRefused(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Decidiq: ConflictOfInterestAuthorizationGuard could not resolve the agenda item\'s meeting',
				['agendaItemId' => 'explodes', 'exception' => 'database gone']
			);
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'explodes'], callerUid: 'bob'));
	}//end testLookupFailureIsLoggedAndRefused()

	/**
	 * The meeting is read from @self relations when the flat reference has no id.
	 *
	 * @return void
	 */
	public function testMeetingFromSelfRelationsList(): void {
		$this->objects['agenda-item']['ai-2'] = [
			'meeting' => ['id' => ''],
			'@self' => ['relations' => [['schema' => 'motion', 'id' => 'x'], ['schema' => 'meeting', 'id' => 'meeting-2']]],
		];
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with('meeting-2', 'bob', ['chair', 'secretary'])
			->willReturn(true);

		$this->assertTrue($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'ai-2'], callerUid: 'bob'));
	}//end testMeetingFromSelfRelationsList()

	/**
	 * The meeting is read from a field-keyed relations map.
	 *
	 * @return void
	 */
	public function testMeetingFromRelationsMap(): void {
		$this->objects['agenda-item']['ai-3'] = ['relations' => ['owner' => 'someone', 'meeting' => 'meeting-3']];
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with('meeting-3', 'bob', ['chair', 'secretary'])
			->willReturn(false);

		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'ai-3'], callerUid: 'bob'));
	}//end testMeetingFromRelationsMap()

	/**
	 * Unusable or meeting-less relations fail closed.
	 *
	 * @return void
	 */
	public function testUnlinkedAgendaItemsAreRefused(): void {
		$this->objects['agenda-item']['ai-4'] = ['relations' => 'not-a-list'];
		$this->objects['agenda-item']['ai-5'] = ['relations' => [['schema' => 'motion', 'id' => 'x'], 'meeting-in-list']];
		$this->participantResolver->expects($this->never())->method('hasRole');

		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'ai-4'], callerUid: 'bob'));
		$this->assertFalse($this->guard->isAuthorizedToRecordAction(declaration: ['agendaItem' => 'ai-5'], callerUid: 'bob'));
	}//end testUnlinkedAgendaItemsAreRefused()

	/**
	 * A user's own Membership, via participant and crosswalk.
	 *
	 * @return void
	 */
	public function testMembershipForUser(): void {
		$this->crosswalk->expects($this->exactly(3))
			->method('resolve')
			->willReturnCallback(
				static fn (string $participantId): ?array => match ($participantId) {
					'P-anna' => ['person' => 'person-anna', 'membership' => 'M-anna'],
					'P-bob' => ['person' => 'person-bob', 'membership' => ''],
					default => null,
				}
			);
		$this->participants['carl'] = 'P-carl';

		$this->assertSame('M-anna', $this->guard->membershipForUser(uid: 'anna'));
		$this->assertNull($this->guard->membershipForUser(uid: 'bob'), 'empty membership');
		$this->assertNull($this->guard->membershipForUser(uid: 'carl'), 'unresolvable participant');
		$this->assertNull($this->guard->membershipForUser(uid: 'stranger'), 'no participant');
	}//end testMembershipForUser()
}//end class
