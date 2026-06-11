<?php
/**
 * Decidesk Board Member Service
 *
 * Phase 2 service for managing BoardMember rows: invite (create), remove
 * (soft-delete by clearing termEndDate to "now"), and change-role.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CRUD-style service for BoardMember entities.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
 */
class BoardMemberService
{

    /**
     * Allowed role enum values for a board member.
     *
     * @var string[]
     */
    public const ROLES = [
        'chairman',
        'vice-chairman',
        'member',
        'executive-member',
        'non-executive-member',
        'independent-member',
        'employee-representative',
    ];

    /**
     * Allowed independence-status values.
     *
     * @var string[]
     */
    public const INDEPENDENCE_STATUS = ['independent', 'non-independent'];

    /**
     * Constructor for BoardMemberService.
     *
     * @param ContainerInterface $container The DI container (used to resolve ObjectService)
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List the members of a board.
     *
     * @param string $boardId UUID of the board
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
     *
     * @return array{success: bool, members: array<int, array<string, mixed>>, count: int}
     */
    public function listForBoard(string $boardId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-member',
                    'filters'  => ['boardKoppeling' => $boardId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMemberService::listForBoard failed',
                ['boardId' => $boardId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'members' => [],
                'count'   => 0,
            ];
        }//end try

        $members = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            if (isset($row['boardKoppeling']) === true && (string) $row['boardKoppeling'] !== $boardId) {
                continue;
            }

            $members[] = $row;
        }

        return [
            'success' => true,
            'members' => $members,
            'count'   => count($members),
        ];

    }//end listForBoard()

    /**
     * Invite (create) a new board member.
     *
     * @param string               $boardId UUID of the parent board
     * @param array<string, mixed> $data    Member payload (persoonKoppeling, rol, etc.)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
     *
     * @return array{success: bool, member: array|null, message: string}
     */
    public function invite(string $boardId, array $data): array
    {
        if (isset($data['rol']) === false || in_array($data['rol'], self::ROLES, true) === false) {
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Role is required and must be one of: '.implode(', ', self::ROLES),
            ];
        }

        if (isset($data['independenceStatus']) === true
            && in_array($data['independenceStatus'], self::INDEPENDENCE_STATUS, true) === false
        ) {
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Unknown independenceStatus: '.$data['independenceStatus'],
            ];
        }

        $row = array_merge(
            $data,
            [
                'boardKoppeling'  => $boardId,
                'appointmentDate' => ($data['appointmentDate'] ?? gmdate('Y-m-d')),
            ]
        );

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: 'board-member'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMemberService::invite failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Failed to invite board member.',
            ];
        }

        $this->logger->info('Decidesk: board member invited', ['boardId' => $boardId, 'role' => $data['rol']]);

        $memberPayload = $row;
        if (is_object($saved) === true) {
            $memberPayload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'member'  => $memberPayload,
            'message' => 'Board member invited.',
        ];

    }//end invite()

    /**
     * Remove a board member (sets termEndDate to today; the row is not deleted
     * so historical resolutions still reference a valid member).
     *
     * @param string $memberId UUID of the board-member row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
     *
     * @return array{success: bool, member: array|null, message: string}
     */
    public function remove(string $memberId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $memberId, register: 'decidesk', schema: 'board-member');
            if ($entity === null) {
                return [
                    'success' => false,
                    'member'  => null,
                    'message' => 'Board member not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $merged = array_merge(
                $current,
                ['termEndDate' => gmdate('Y-m-d')]
            );

            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'board-member',
                uuid: $memberId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMemberService::remove failed',
                ['memberId' => $memberId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Failed to remove board member.',
            ];
        }//end try

        $memberPayload = $merged;
        if (is_object($saved) === true) {
            $memberPayload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'member'  => $memberPayload,
            'message' => 'Board member term ended.',
        ];

    }//end remove()

    /**
     * Change a board member's role.
     *
     * @param string $memberId UUID of the board-member row
     * @param string $role     New role (one of self::ROLES)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-member-service
     *
     * @return array{success: bool, member: array|null, message: string}
     */
    public function changeRole(string $memberId, string $role): array
    {
        if (in_array($role, self::ROLES, true) === false) {
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Unknown role: '.$role,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $memberId, register: 'decidesk', schema: 'board-member');
            if ($entity === null) {
                return [
                    'success' => false,
                    'member'  => null,
                    'message' => 'Board member not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $merged = array_merge($current, ['rol' => $role]);

            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'board-member',
                uuid: $memberId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMemberService::changeRole failed',
                ['memberId' => $memberId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'member'  => null,
                'message' => 'Failed to update role.',
            ];
        }//end try

        $memberPayload = $merged;
        if (is_object($saved) === true) {
            $memberPayload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'member'  => $memberPayload,
            'message' => 'Role updated.',
        ];

    }//end changeRole()
}//end class
