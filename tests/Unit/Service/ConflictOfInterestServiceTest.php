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
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
	 * @param array<int, array<string, mixed>> &$rows The findAll fixture
	 * @param array<int, array<string, mixed>> &$audited Captured audit calls
	 *
	 * @return ConflictOfInterestService
	 */
	private function makeService(array &$rows, array &$audited): ConflictOfInterestService {
		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$rows): array {
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
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use (&$rows): ?ObjectEntity {
				foreach ($rows as $row) {
					if (($row['id'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}
				return null;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

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

		return new ConflictOfInterestService( $logger, $auditLog,
			objectService: $objectService,
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

		$service = new ConflictOfInterestService($logger, $auditLog, objectService: $objectService);

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

		$service = new ConflictOfInterestService($logger, $auditLog, objectService: $objectService);

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

		$service = new ConflictOfInterestService($logger, $auditLog, objectService: $objectService);

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

		$service = new ConflictOfInterestService($logger, $auditLog, objectService: $objectService);

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

		$service = new ConflictOfInterestService($logger, $auditLog, objectService: $objectService);

		$this->assertNull($service->getActiveConflicts('m1', 'a1'));

	}//end testGetActiveConflictsReturnsNullWhenFindAllThrows()

}//end class
