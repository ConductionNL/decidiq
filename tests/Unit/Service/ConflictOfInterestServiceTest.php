<?php

/**
 * Unit tests for ConflictOfInterestService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConflictOfInterestService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */
class ConflictOfInterestServiceTest extends TestCase {

	/**
	 * Wrap a plain array as an ObjectEntity double whose jsonSerialize()/
	 * getObject() return it — the same pattern already used across this
	 * app's other ObjectService-backed test doubles (ObjectEntity's own
	 * accessors are magic __call, so only its REAL declared methods are
	 * stubbed here, never mocked-as-magic).
	 *
	 * @param array<string, mixed> $data The object payload
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
	 * Build a service backed by a captured ObjectService and a stub audit log
	 * that records material-declaration calls.
	 *
	 * @param array<int, array<string, mixed>> &$rows The findAll/find fixture (conflict-of-interest rows)
	 * @param array<int, array<string, mixed>> &$audited Captured audit calls
	 * @param array<int, array<string, string>> $participants Fixture `participant` rows: ['uuid' => .., 'nextcloudUserId' => ..]
	 * @param array<string, array<int, string>> $chairsByMeeting Map of meetingId => Nextcloud UIDs holding chair/secretary role
	 * @param array<string, array{person: string, membership: string}|null> $crosswalk Non-identity Participant->Person/Membership map;
	 *                                                                                  defaults to an identity map (membership =
	 *                                                                                  participantId . '-membership')
	 * @param array<int, array<string, mixed>> $agendaItems Fixture `agenda-item` rows: ['id' => .., 'meeting' => ..]
	 *
	 * @return ConflictOfInterestService
	 */
	private function makeService(
		array &$rows,
		array &$audited,
		array $participants = [],
		array $chairsByMeeting = [],
		array $crosswalk = [],
		array $agendaItems = [],
	): ConflictOfInterestService {
		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$rows, $participants): array {
				$filters = ($config['filters'] ?? []);
				if (array_key_exists('nextcloudUserId', $filters) === true) {
					$out = [];
					foreach ($participants as $participant) {
						if (($participant['nextcloudUserId'] ?? null) !== $filters['nextcloudUserId']) {
							continue;
						}

						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($participant);
						$out[] = $entity;
					}

					return $out;
				}

				return $rows;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$rows): ObjectEntity {
				$row = array_merge(['id' => $uuid ?? ('decl-' . count($rows))], $object);
				$rows[] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use (&$rows, $agendaItems): ?ObjectEntity {
				foreach ($rows as $row) {
					if (is_array($row) === true && ($row['id'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				foreach ($agendaItems as $item) {
					if (($item['id'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($item);
						$entity->method('getObject')->willReturn($item);
						return $entity;
					}
				}

				return null;
			}
		);

		$auditLog = $this->createMock(AuditLogService::class);
		$auditLog->method('append')->willReturnCallback(
			static function (string $actor, string $action, array $objectUids, array $payload = []) use (&$audited): array {
				$audited[] = [
					'actor' => $actor,
					'action' => $action,
					'uids' => $objectUids,
					'payload' => $payload,
				];
				return [
					'success' => true,
					'entry' => [],
					'message' => 'ok',
				];
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		$participantResolver->method('hasRole')->willReturnCallback(
			static function (string $meetingId, string $nextcloudUid, array $roles) use ($chairsByMeeting): bool {
				return in_array($nextcloudUid, ($chairsByMeeting[$meetingId] ?? []), true);
			}
		);

		// Identity crosswalk double (membership = participantId . '-membership') so a
		// test that does not care about crosswalk resolution still gets a stable,
		// non-null membership id back; the real resolver's own matching behaviour is
		// covered by ParticipantToPersonMembershipResolverTest, not re-derived here.
		$participantCrosswalk = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$participantCrosswalk->method('resolve')->willReturnCallback(
			static function (string $participantId) use ($crosswalk): ?array {
				if ($participantId === '') {
					return null;
				}

				if (array_key_exists($participantId, $crosswalk) === true) {
					return $crosswalk[$participantId];
				}

				return ['person' => $participantId . '-person', 'membership' => $participantId . '-membership'];
			}
		);

		$authorizationGuard = new ConflictOfInterestAuthorizationGuard(
			logger: $logger,
			objectService: $objectService,
			participantResolver: $participantResolver,
			participantCrosswalk: $participantCrosswalk,
		);

		return new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $authorizationGuard,
		);
	}//end makeService()

	/**
	 * Material declarations are saved AND mirrored to the audit log.
	 *
	 * @return void
	 */
	public function testDeclareMaterialMirrorsToAuditLog(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->declare(
			membershipId: 'member-1',
			agendaItemId: 'agenda-1',
			type: 'financial-interest',
			description: 'Holds shares in proposed counterparty.',
			severity: 'material'
		);

		$this->assertTrue($result['success']);
		$this->assertCount(1, $audited);
		$this->assertSame('conflict-declaration', $audited[0]['action']);
		$this->assertSame('member-1', $audited[0]['actor']);

	}//end testDeclareMaterialMirrorsToAuditLog()

	/**
	 * Non-material declarations are saved without touching the audit log.
	 *
	 * @return void
	 */
	public function testDeclareNonMaterialSkipsAuditLog(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->declare(
			membershipId: 'member-2',
			agendaItemId: 'agenda-2',
			type: 'personal-relationship',
			description: 'Distant family member tangentially involved.',
			severity: 'non-material'
		);

		$this->assertTrue($result['success']);
		$this->assertSame([], $audited);

	}//end testDeclareNonMaterialSkipsAuditLog()

	/**
	 * Unknown declaration type is rejected before persistence.
	 *
	 * @return void
	 */
	public function testDeclareRejectsUnknownType(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->declare('m', 'a', 'astrology', 'because');

		$this->assertFalse($result['success']);
		$this->assertSame([], $rows);

	}//end testDeclareRejectsUnknownType()

	/**
	 * getActiveConflicts returns the most restrictive declaration.
	 *
	 * @return void
	 */
	public function testGetActiveConflictsReturnsMostRestrictive(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'm1', 'agendaItem' => 'a1', 'actionTaken' => 'disclosed-and-participated'],
			['id' => 'd2', 'boardMember' => 'm1', 'agendaItem' => 'a1', 'actionTaken' => 'recused-from-vote'],
			['id' => 'd3', 'boardMember' => 'm1', 'agendaItem' => 'a1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$active = $service->getActiveConflicts('m1', 'a1');

		$this->assertNotNull($active);
		$this->assertSame('recused-from-vote', $active['actionTaken']);

	}//end testGetActiveConflictsReturnsMostRestrictive()

	/**
	 * recordAction updates an existing declaration.
	 *
	 * @return void
	 */
	public function testRecordActionUpdatesExistingDeclaration(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'm1', 'agendaItem' => 'a1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->recordAction('d1', 'recused-from-vote');

		$this->assertTrue($result['success']);
		$this->assertSame('recused-from-vote', $result['declaration']['actionTaken']);

	}//end testRecordActionUpdatesExistingDeclaration()

	/**
	 * recordAction rejects unknown action-taken values.
	 *
	 * @return void
	 */
	public function testRecordActionRejectsUnknownAction(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->recordAction('d1', 'time-out');

		$this->assertFalse($result['success']);

	}//end testRecordActionRejectsUnknownAction()

	/**
	 * Unknown severity is rejected before persistence.
	 *
	 * @return void
	 */
	public function testDeclareRejectsUnknownSeverity(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->declare('m', 'a', 'financial-interest', 'because', 'extremely-material');

		$this->assertFalse($result['success']);
		$this->assertSame('Unknown severity: extremely-material', $result['message']);
		$this->assertSame([], $rows);

	}//end testDeclareRejectsUnknownSeverity()

	/**
	 * A thrown exception from saveObject() is caught: declare() fails
	 * closed (success false) and never reaches the audit-log mirror.
	 *
	 * @return void
	 */
	public function testDeclareFailsClosedWhenSaveObjectThrows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		$auditLog = $this->createMock(AuditLogService::class);
		$auditLog->expects($this->never())->method('append');

		$service = new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $this->createMock(ConflictOfInterestAuthorizationGuard::class),
		);

		$result = $service->declare(
			membershipId: 'member-9',
			agendaItemId: 'agenda-9',
			type: 'financial-interest',
			description: 'Boom.',
			severity: 'material'
		);

		$this->assertFalse($result['success']);
		$this->assertNull($result['declaration']);
		$this->assertSame('Failed to record declaration.', $result['message']);

	}//end testDeclareFailsClosedWhenSaveObjectThrows()

	/**
	 * When the persisted row has no 'id' key, the audit mirror falls back
	 * to 'uuid' rather than losing the object reference entirely.
	 *
	 * @return void
	 */
	public function testDeclareFallsBackToUuidWhenPersistedIdIsMissing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturn(
			$this->entity(['uuid' => 'u-99', 'boardMember' => 'member-3', 'agendaItem' => 'agenda-3'])
		);

		$audited = [];
		$auditLog = $this->createMock(AuditLogService::class);
		$auditLog->method('append')->willReturnCallback(
			static function (string $actor, string $action, array $objectUids, array $payload = []) use (&$audited): array {
				$audited[] = $objectUids;
				return [
					'success' => true,
					'entry' => [],
					'message' => 'ok',
				];
			}
		);

		$service = new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $this->createMock(ConflictOfInterestAuthorizationGuard::class),
		);

		$service->declare(
			membershipId: 'member-3',
			agendaItemId: 'agenda-3',
			type: 'financial-interest',
			description: 'No id key on the persisted row.',
			severity: 'material'
		);

		$this->assertSame('u-99', $audited[0][0]);

	}//end testDeclareFallsBackToUuidWhenPersistedIdIsMissing()

	/**
	 * recordAction() returns a not-found result when find() cannot locate
	 * the declaration, without attempting to save anything.
	 *
	 * @return void
	 */
	public function testRecordActionReturnsNotFoundWhenDeclarationMissing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn(null);
		$objectService->expects($this->never())->method('saveObject');

		$auditLog = $this->createMock(AuditLogService::class);

		$service = new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $this->createMock(ConflictOfInterestAuthorizationGuard::class),
		);

		$result = $service->recordAction('missing-id', 'recused-from-vote');

		$this->assertFalse($result['success']);
		$this->assertNull($result['declaration']);
		$this->assertSame('Declaration not found.', $result['message']);

	}//end testRecordActionReturnsNotFoundWhenDeclarationMissing()

	/**
	 * A thrown exception from find() is caught: recordAction() fails
	 * closed rather than propagating.
	 *
	 * @return void
	 */
	public function testRecordActionFailsClosedWhenFindThrows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		$auditLog = $this->createMock(AuditLogService::class);

		$service = new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $this->createMock(ConflictOfInterestAuthorizationGuard::class),
		);

		$result = $service->recordAction('d1', 'recused-from-vote');

		$this->assertFalse($result['success']);
		$this->assertNull($result['declaration']);
		$this->assertSame('Failed to record action.', $result['message']);

	}//end testRecordActionFailsClosedWhenFindThrows()

	/**
	 * getActiveConflicts() returns null when no stored declaration matches
	 * the requested member + agenda item pair.
	 *
	 * @return void
	 */
	public function testGetActiveConflictsReturnsNullWhenNoDeclarationsMatch(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'other-member', 'agendaItem' => 'other-agenda', 'actionTaken' => 'recused-from-vote'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$this->assertNull($service->getActiveConflicts('m1', 'a1'));

	}//end testGetActiveConflictsReturnsNullWhenNoDeclarationsMatch()

	/**
	 * getActiveConflicts() filters strictly on BOTH boardMember and
	 * agendaItem: decoy rows that match only one of the two must never
	 * win over the genuinely matching row, even when they carry a more
	 * restrictive action.
	 *
	 * @return void
	 */
	public function testGetActiveConflictsFiltersByBoardMemberAndAgendaItem(): void {
		$rows = [
			['id' => 'decoy-member', 'boardMember' => 'other-member', 'agendaItem' => 'a1', 'actionTaken' => 'recused-from-vote'],
			['id' => 'decoy-item', 'boardMember' => 'm1', 'agendaItem' => 'other-agenda', 'actionTaken' => 'recused-from-vote'],
			['id' => 'real', 'boardMember' => 'm1', 'agendaItem' => 'a1', 'actionTaken' => 'disclosed-and-participated'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$active = $service->getActiveConflicts('m1', 'a1');

		$this->assertNotNull($active);
		$this->assertSame('real', $active['id']);
		$this->assertSame('disclosed-and-participated', $active['actionTaken']);

	}//end testGetActiveConflictsFiltersByBoardMemberAndAgendaItem()

	/**
	 * Rows that are neither an array nor an object exposing jsonSerialize()
	 * are skipped rather than crashing the lookup — a genuinely matching
	 * row further down the result set must still be found.
	 *
	 * @return void
	 */
	public function testFindDeclarationsSkipsRowsThatAreNeitherArrayNorSerializable(): void {
		$rows = [
			'not-a-row',
			new \stdClass(),
			['id' => 'd9', 'boardMember' => 'mX', 'agendaItem' => 'aX', 'actionTaken' => 'recused-from-discussion'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$active = $service->getActiveConflicts('mX', 'aX');

		$this->assertNotNull($active);
		$this->assertSame('d9', $active['id']);

	}//end testFindDeclarationsSkipsRowsThatAreNeitherArrayNorSerializable()

	/**
	 * Rows returned by findAll() as objects exposing jsonSerialize() (as a
	 * real ObjectEntity would) are converted to arrays before matching —
	 * not silently discarded by the is_array() guard.
	 *
	 * @return void
	 */
	public function testFindDeclarationsConvertsJsonSerializableRowObjects(): void {
		$rows = [
			$this->entity(['id' => 'd10', 'boardMember' => 'mY', 'agendaItem' => 'aY', 'actionTaken' => 'recused-from-vote']),
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$active = $service->getActiveConflicts('mY', 'aY');

		$this->assertNotNull($active);
		$this->assertSame('d10', $active['id']);

	}//end testFindDeclarationsConvertsJsonSerializableRowObjects()

	/**
	 * A thrown exception from findAll() is caught inside findDeclarations():
	 * getActiveConflicts() degrades to null instead of propagating.
	 *
	 * @return void
	 */
	public function testGetActiveConflictsReturnsNullWhenFindAllThrows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		$auditLog = $this->createMock(AuditLogService::class);

		$service = new ConflictOfInterestService(
			$logger,
			$auditLog,
			objectService: $objectService,
			authorizationGuard: $this->createMock(ConflictOfInterestAuthorizationGuard::class),
		);

		$this->assertNull($service->getActiveConflicts('m1', 'a1'));

	}//end testGetActiveConflictsReturnsNullWhenFindAllThrows()

	/**
	 * declare() allows the caller to record a declaration about themselves —
	 * resolved via the Nextcloud UID -> Participant -> Person/Membership
	 * crosswalk, comparing the resolved Membership against `membershipId`.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return void
	 */
	public function testDeclareAllowsSelfDeclaration(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			participants: [['uuid' => 'p-alice', 'nextcloudUserId' => 'alice']],
			crosswalk: ['p-alice' => ['person' => 'person-alice', 'membership' => 'membership-alice']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->declare('membership-alice', 'agenda-1', 'financial-interest', 'shares', 'material', callerUid: 'alice');

		$this->assertTrue($result['success']);

	}//end testDeclareAllowsSelfDeclaration()

	/**
	 * declare() allows a chair to record a declaration on behalf of another
	 * member of the same GovernanceBody.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return void
	 */
	public function testDeclareAllowsChairOnBehalfOfAnotherMember(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			chairsByMeeting: ['meet-1' => ['chair-carol']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->declare('membership-alice', 'agenda-1', 'financial-interest', 'shares', 'material', callerUid: 'chair-carol');

		$this->assertTrue($result['success']);

	}//end testDeclareAllowsChairOnBehalfOfAnotherMember()

	/**
	 * declare() rejects an authenticated caller who is neither the declaring
	 * member nor chair/secretary of the relevant GovernanceBody (IDOR guard),
	 * and writes nothing.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return void
	 */
	public function testDeclareRejectsUnrelatedCaller(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			participants: [
				['uuid' => 'p-alice', 'nextcloudUserId' => 'alice'],
				['uuid' => 'p-dave', 'nextcloudUserId' => 'dave'],
			],
			crosswalk: ['p-alice' => ['person' => 'person-alice', 'membership' => 'membership-alice']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->declare('membership-alice', 'agenda-1', 'financial-interest', 'shares', 'material', callerUid: 'dave');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertSame([], $rows, 'No declaration may be written for an unauthorized caller');

	}//end testDeclareRejectsUnrelatedCaller()

	/**
	 * declare() allows a null callerUid (admin bypass convention).
	 *
	 * @return void
	 */
	public function testDeclareAllowsAdminBypassViaNullCallerUid(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->declare('membership-alice', 'agenda-1', 'financial-interest', 'shares', 'material', callerUid: null);

		$this->assertTrue($result['success']);

	}//end testDeclareAllowsAdminBypassViaNullCallerUid()

	/**
	 * isAuthorizedForMember() allows the caller to read their own declarations.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-102-only-the-member-or-an-authorized-official-may-read-a-members-conflict-declarations
	 *
	 * @return void
	 */
	public function testIsAuthorizedForMemberAllowsSelf(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			participants: [['uuid' => 'p-alice', 'nextcloudUserId' => 'alice']],
			crosswalk: ['p-alice' => ['person' => 'person-alice', 'membership' => 'membership-alice']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$this->assertTrue($service->isAuthorizedForMember('membership-alice', 'agenda-1', 'alice'));

	}//end testIsAuthorizedForMemberAllowsSelf()

	/**
	 * isAuthorizedForMember() allows a secretary to read another member's
	 * declarations.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-102-only-the-member-or-an-authorized-official-may-read-a-members-conflict-declarations
	 *
	 * @return void
	 */
	public function testIsAuthorizedForMemberAllowsChairOrSecretary(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			chairsByMeeting: ['meet-1' => ['secretary-erin']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$this->assertTrue($service->isAuthorizedForMember('membership-alice', 'agenda-1', 'secretary-erin'));

	}//end testIsAuthorizedForMemberAllowsChairOrSecretary()

	/**
	 * isAuthorizedForMember() rejects an authenticated caller who is neither
	 * the member nor chair/secretary of the relevant GovernanceBody (the
	 * confirmed IDOR: any authenticated user could read any member's
	 * declarations before this guard existed).
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-102-only-the-member-or-an-authorized-official-may-read-a-members-conflict-declarations
	 *
	 * @return void
	 */
	public function testIsAuthorizedForMemberRejectsUnrelatedCaller(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			participants: [
				['uuid' => 'p-alice', 'nextcloudUserId' => 'alice'],
				['uuid' => 'p-dave', 'nextcloudUserId' => 'dave'],
			],
			crosswalk: ['p-alice' => ['person' => 'person-alice', 'membership' => 'membership-alice']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$this->assertFalse($service->isAuthorizedForMember('membership-alice', 'agenda-1', 'dave'));

	}//end testIsAuthorizedForMemberRejectsUnrelatedCaller()

	/**
	 * isAuthorizedForMember() fails closed when the agenda item's meeting
	 * cannot be resolved and the caller is not the member themselves.
	 *
	 * @return void
	 */
	public function testIsAuthorizedForMemberFailsClosedWhenMeetingUnresolvable(): void {
		$rows = [];
		$audited = [];
		$service = $this->makeService($rows, $audited, agendaItems: []);

		$this->assertFalse($service->isAuthorizedForMember('membership-alice', 'missing-agenda-item', 'dave'));

	}//end testIsAuthorizedForMemberFailsClosedWhenMeetingUnresolvable()

	/**
	 * recordAction() allows a chair/secretary of the relevant GovernanceBody
	 * to record the action taken.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return void
	 */
	public function testRecordActionAllowsChairOrSecretary(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'membership-alice', 'agendaItem' => 'agenda-1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			chairsByMeeting: ['meet-1' => ['chair-carol']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->recordAction('d1', 'recused-from-vote', callerUid: 'chair-carol');

		$this->assertTrue($result['success']);
		$this->assertSame('recused-from-vote', $result['declaration']['actionTaken']);

	}//end testRecordActionAllowsChairOrSecretary()

	/**
	 * recordAction() rejects the declaring member themselves when they hold
	 * no chair/secretary role — recording the action taken is a
	 * presiding-officer act, not something the declarant authorizes for
	 * their own declaration.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return void
	 */
	public function testRecordActionRejectsDeclaringMemberWithoutChairRole(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'membership-alice', 'agendaItem' => 'agenda-1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			participants: [['uuid' => 'p-alice', 'nextcloudUserId' => 'alice']],
			crosswalk: ['p-alice' => ['person' => 'person-alice', 'membership' => 'membership-alice']],
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->recordAction('d1', 'recused-from-vote', callerUid: 'alice');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertSame('no-action-needed', $rows[0]['actionTaken'], 'The row must not change for an unauthorized caller');

	}//end testRecordActionRejectsDeclaringMemberWithoutChairRole()

	/**
	 * recordAction() rejects an unrelated authenticated caller (IDOR guard),
	 * leaving the row unchanged.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return void
	 */
	public function testRecordActionRejectsUnrelatedCaller(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'membership-alice', 'agendaItem' => 'agenda-1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			agendaItems: [['id' => 'agenda-1', 'meeting' => 'meet-1']],
		);

		$result = $service->recordAction('d1', 'recused-from-vote', callerUid: 'dave');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertSame('no-action-needed', $rows[0]['actionTaken']);

	}//end testRecordActionRejectsUnrelatedCaller()

	/**
	 * recordAction() allows a null callerUid (admin bypass convention).
	 *
	 * @return void
	 */
	public function testRecordActionAllowsAdminBypassViaNullCallerUid(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'membership-alice', 'agendaItem' => 'agenda-1', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService($rows, $audited);

		$result = $service->recordAction('d1', 'recused-from-vote', callerUid: null);

		$this->assertTrue($result['success']);

	}//end testRecordActionAllowsAdminBypassViaNullCallerUid()

	/**
	 * recordAction() fails closed when the declaration carries no agenda item
	 * (legacy row, or a declare() call that omitted it) — even a genuine
	 * chair of SOME meeting cannot be verified against an unresolvable
	 * GovernanceBody, so the request must be refused rather than let through.
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return void
	 */
	public function testRecordActionFailsClosedWhenAgendaItemMissingOnRow(): void {
		$rows = [
			['id' => 'd1', 'boardMember' => 'membership-alice', 'actionTaken' => 'no-action-needed'],
		];
		$audited = [];
		$service = $this->makeService(
			$rows,
			$audited,
			chairsByMeeting: ['meet-1' => ['chair-carol']],
		);

		$result = $service->recordAction('d1', 'recused-from-vote', callerUid: 'chair-carol');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);

	}//end testRecordActionFailsClosedWhenAgendaItemMissingOnRow()

}//end class
