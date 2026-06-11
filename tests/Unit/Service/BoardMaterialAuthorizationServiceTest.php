<?php

/**
 * Unit tests for BoardMaterialAuthorizationService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardMaterialAuthorizationService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */
class BoardMaterialAuthorizationServiceTest extends TestCase
{


    /**
     * Helper: construct the service with stubbed ObjectService + AuditLogService.
     *
     * @param array<string, array<string, mixed>> $members   Map of memberId => member row
     * @param array<string, array<string, mixed>> $materials Map of materialId => material row
     * @param array<int, array<string, mixed>>    &$audited  Captured audit-log calls
     *
     * @return BoardMaterialAuthorizationService
     */
    private function makeService(array $members, array $materials, array &$audited): BoardMaterialAuthorizationService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($members, $materials): ?ObjectEntity {
                $row = null;
                if ($schema === 'board-member' && isset($members[(string) $id]) === true) {
                    $row = $members[(string) $id];
                } else if ($schema === 'board-material' && isset($materials[(string) $id]) === true) {
                    $row = $materials[(string) $id];
                }

                if ($row === null) {
                    return null;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use ($materials): array {
                return array_values($materials);
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $auditLog = $this->createMock(AuditLogService::class);
        $auditLog->method('append')->willReturnCallback(
            static function (string $actor, string $action, array $uids, array $payload=[]) use (&$audited): array {
                $audited[] = compact('actor', 'action', 'uids', 'payload');
                return ['success' => true, 'entry' => [], 'message' => 'ok'];
            }
        );

        return new BoardMaterialAuthorizationService($container, $logger, $auditLog);

    }//end makeService()


    /**
     * board-only materials are visible to chairmen.
     *
     * @return void
     */
    public function testChairmanCanViewBoardOnlyMaterial(): void
    {
        $audited = [];
        $service = $this->makeService(
            members: ['m1' => ['id' => 'm1', 'rol' => 'chairman']],
            materials: ['mat1' => ['id' => 'mat1', 'accessLevel' => 'board-only']],
            audited: $audited
        );

        $this->assertTrue($service->canViewMaterial('m1', 'mat1'));

    }//end testChairmanCanViewBoardOnlyMaterial()


    /**
     * Non-executive members are denied executive-only materials.
     *
     * @return void
     */
    public function testNonExecutiveCannotViewExecutiveOnly(): void
    {
        $audited = [];
        $service = $this->makeService(
            members: ['m2' => ['id' => 'm2', 'rol' => 'non-executive-member']],
            materials: ['mat2' => ['id' => 'mat2', 'accessLevel' => 'executive-only']],
            audited: $audited
        );

        $this->assertFalse($service->canViewMaterial('m2', 'mat2'));

    }//end testNonExecutiveCannotViewExecutiveOnly()


    /**
     * Audit-committee membership grants access to audit-committee materials.
     *
     * @return void
     */
    public function testAuditCommitteeMembershipGrantsAccess(): void
    {
        $audited = [];
        $service = $this->makeService(
            members: ['m3' => ['id' => 'm3', 'rol' => 'member', 'committees' => ['audit-committee-member']]],
            materials: ['mat3' => ['id' => 'mat3', 'accessLevel' => 'audit-committee']],
            audited: $audited
        );

        $this->assertTrue($service->canViewMaterial('m3', 'mat3'));

    }//end testAuditCommitteeMembershipGrantsAccess()


    /**
     * Missing member or material is denied gracefully.
     *
     * @return void
     */
    public function testMissingObjectsAreDenied(): void
    {
        $audited = [];
        $service = $this->makeService([], [], $audited);

        $this->assertFalse($service->canViewMaterial('missing', 'missing'));

    }//end testMissingObjectsAreDenied()


    /**
     * filterMaterialsByRole returns only the rows whose access-level matches
     * the requested role and (when provided) board.
     *
     * @return void
     */
    public function testFilterMaterialsByRole(): void
    {
        $audited = [];
        $service = $this->makeService(
            members: [],
            materials: [
                'm1' => ['id' => 'm1', 'boardKoppeling' => 'board-x', 'accessLevel' => 'board-only'],
                'm2' => ['id' => 'm2', 'boardKoppeling' => 'board-x', 'accessLevel' => 'audit-committee'],
                'm3' => ['id' => 'm3', 'boardKoppeling' => 'board-y', 'accessLevel' => 'board-only'],
            ],
            audited: $audited
        );

        $rows = $service->filterMaterialsByRole('board-x', 'chairman');

        $this->assertCount(2, $rows);
        $ids = array_column($rows, 'id');
        $this->assertContains('m1', $ids);
        $this->assertContains('m2', $ids);

    }//end testFilterMaterialsByRole()


    /**
     * logMaterialAccess mirrors to the audit log including the granted flag.
     *
     * @return void
     */
    public function testLogMaterialAccessRecordsGrantStatus(): void
    {
        $audited = [];
        $service = $this->makeService([], [], $audited);

        $service->logMaterialAccess('m1', 'mat1', granted: true);
        $service->logMaterialAccess('m1', 'mat2', granted: false);

        $this->assertCount(2, $audited);
        $this->assertSame('material-access', $audited[0]['action']);
        $this->assertTrue($audited[0]['payload']['granted']);
        $this->assertFalse($audited[1]['payload']['granted']);

    }//end testLogMaterialAccessRecordsGrantStatus()


}//end class
