<?php

/**
 * Unit tests for BoardMemberService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardMemberService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BoardMemberService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
 */
class BoardMemberServiceTest extends TestCase
{


    /**
     * Build service backed by an in-memory members map.
     *
     * @param array<string, array<string, mixed>> &$members Map of memberId => member row
     *
     * @return BoardMemberService
     */
    private function makeService(array &$members): BoardMemberService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$members): array {
                return array_values($members);
            }
        );
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$members): ?ObjectEntity {
                if (isset($members[(string) $id]) === false) {
                    return null;
                }

                $row    = $members[(string) $id];
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$members): ObjectEntity {
                $id = $uuid ?? ('mem-'.(count($members) + 1));
                $row = array_merge(['id' => $id], $object);
                $members[$id] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new BoardMemberService($container, $logger);

    }//end makeService()


    /**
     * Invite requires a recognised role.
     *
     * @return void
     */
    public function testInviteRequiresValidRole(): void
    {
        $members = [];
        $service = $this->makeService($members);

        $result = $service->invite('b1', ['rol' => 'spectator']);
        $this->assertFalse($result['success']);

        $resultNoRole = $service->invite('b1', []);
        $this->assertFalse($resultNoRole['success']);

    }//end testInviteRequiresValidRole()


    /**
     * Invite persists a row with the board koppeling injected.
     *
     * @return void
     */
    public function testInvitePersistsBoardLink(): void
    {
        $members = [];
        $service = $this->makeService($members);

        $result = $service->invite('b1', ['rol' => 'chairman', 'persoonKoppeling' => 'person-1']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $members);
        $first = array_values($members)[0];
        $this->assertSame('b1', $first['boardKoppeling']);
        $this->assertSame('chairman', $first['rol']);

    }//end testInvitePersistsBoardLink()


    /**
     * remove() sets termEndDate to today (YYYY-MM-DD).
     *
     * @return void
     */
    public function testRemoveSetsTermEndDate(): void
    {
        $members = [
            'm1' => ['id' => 'm1', 'rol' => 'member'],
        ];
        $service = $this->makeService($members);

        $result = $service->remove('m1');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('termEndDate', $members['m1']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $members['m1']['termEndDate']);

    }//end testRemoveSetsTermEndDate()


    /**
     * changeRole rejects unknown roles and accepts known ones.
     *
     * @return void
     */
    public function testChangeRoleValidatesRole(): void
    {
        $members = [
            'm1' => ['id' => 'm1', 'rol' => 'member'],
        ];
        $service = $this->makeService($members);

        $bad = $service->changeRole('m1', 'spectator');
        $this->assertFalse($bad['success']);

        $ok = $service->changeRole('m1', 'vice-chairman');
        $this->assertTrue($ok['success']);
        $this->assertSame('vice-chairman', $members['m1']['rol']);

    }//end testChangeRoleValidatesRole()


    /**
     * listForBoard filters rows by board koppeling.
     *
     * @return void
     */
    public function testListForBoardFiltersByBoard(): void
    {
        $members = [
            'm1' => ['id' => 'm1', 'boardKoppeling' => 'b1'],
            'm2' => ['id' => 'm2', 'boardKoppeling' => 'b2'],
            'm3' => ['id' => 'm3', 'boardKoppeling' => 'b1'],
        ];
        $service = $this->makeService($members);

        $result = $service->listForBoard('b1');

        $this->assertSame(2, $result['count']);

    }//end testListForBoardFiltersByBoard()


}//end class
