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

}//end class
