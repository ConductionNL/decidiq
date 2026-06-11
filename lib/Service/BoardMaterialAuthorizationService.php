<?php
/**
 * Decidesk Board Material Authorization Service
 *
 * Enforces the BoardMaterial schema's access-level enum against a requesting
 * BoardMember's role. Every access attempt (granted or denied) is mirrored to
 * the board audit log via AuditLogService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Role-based access control for board materials.
 *
 * The access-level enum maps to an allow-list of board-member roles per the
 * MCCG and statuten requirements. Members of multiple committees (e.g. a chair
 * who sits on the audit committee) are granted the union of allow-lists.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */
class BoardMaterialAuthorizationService
{

    /**
     * Allow-list per access-level enum value. Roles that match any entry in
     * the list are granted read access to the material.
     *
     * @var array<string, string[]>
     */
    private const ACCESS_MATRIX = [
        'board-only'       => [
            'chairman',
            'vice-chairman',
            'member',
            'executive-member',
            'non-executive-member',
            'independent-member',
            'employee-representative',
        ],
        'executive-only'   => [
            'executive-member',
            'chairman',
        ],
        'audit-committee'  => [
            'audit-committee-member',
            'chairman',
        ],
        'external-auditor' => [
            'external-auditor',
        ],
        'regulator'        => [
            'regulator',
        ],
    ];

    /**
     * Constructor for BoardMaterialAuthorizationService.
     *
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     * @param AuditLogService    $auditLogService Audit log dependency for access events
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Return true when the given board member's roles include at least one
     * role allowed for the material's access-level.
     *
     * @param string $boardMemberId UUID of the board member
     * @param string $materialId    UUID of the board material
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     *
     * @return bool
     */
    public function canViewMaterial(string $boardMemberId, string $materialId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $member   = $objectService->find(id: $boardMemberId, register: 'decidesk', schema: 'board-member');
            $material = $objectService->find(id: $materialId, register: 'decidesk', schema: 'board-material');

            if ($member === null || $material === null) {
                return false;
            }

            $memberData   = $this->toArray(row: $member);
            $materialData = $this->toArray(row: $material);

            $accessLevel = (string) ($materialData['accessLevel'] ?? 'board-only');
            $roles       = $this->memberRoles(memberData: $memberData);

            $allowed = (self::ACCESS_MATRIX[$accessLevel] ?? []);
            foreach ($roles as $role) {
                if (in_array($role, $allowed, true) === true) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: board material authorization check failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

        return false;

    }//end canViewMaterial()

    /**
     * Return all materials a member with the given role can read on the given
     * board. The result is an array of material objects (each a plain array).
     *
     * @param string $boardId UUID of the board
     * @param string $role    Role to filter against
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     *
     * @return array<int, array<string, mixed>>
     */
    public function filterMaterialsByRole(string $boardId, string $role): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $rows = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-material',
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to list board materials',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            // Materials are linked to a meeting which is linked to a board; if
            // the caller provided a boardId we honour the constraint only when
            // the material exposes a direct boardKoppeling field.
            if (isset($row['boardKoppeling']) === true && (string) $row['boardKoppeling'] !== $boardId) {
                continue;
            }

            $accessLevel = (string) ($row['accessLevel'] ?? 'board-only');
            $allowed     = (self::ACCESS_MATRIX[$accessLevel] ?? []);
            if (in_array($role, $allowed, true) === true) {
                $out[] = $row;
            }
        }//end foreach

        return $out;

    }//end filterMaterialsByRole()

    /**
     * Append a `material-access` entry to the audit log. The granted flag is
     * stored in the payload so denial attempts remain auditable.
     *
     * @param string $boardMemberId UUID of the board member who attempted access
     * @param string $materialId    UUID of the material that was requested
     * @param bool   $granted       True when access was allowed
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     *
     * @return array{success: bool, entry: array|null, message: string}
     */
    public function logMaterialAccess(string $boardMemberId, string $materialId, bool $granted): array
    {
        return $this->auditLogService->append(
            actor: $boardMemberId,
            action: 'material-access',
            objectUids: [$materialId],
            payload: ['granted' => $granted]
        );

    }//end logMaterialAccess()

    /**
     * Convert an ObjectService row (object or array) to a plain array.
     *
     * @param mixed $row Row to convert
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        if (is_array($row) === true) {
            return $row;
        }

        return [];

    }//end toArray()

    /**
     * Roles a member holds (primary role + optional committee memberships).
     *
     * @param array<string, mixed> $memberData The board member object
     *
     * @return string[]
     */
    private function memberRoles(array $memberData): array
    {
        $roles = [];
        if (isset($memberData['rol']) === true && is_string($memberData['rol']) === true) {
            $roles[] = $memberData['rol'];
        }

        if (isset($memberData['committees']) === true && is_array($memberData['committees']) === true) {
            foreach ($memberData['committees'] as $committee) {
                if (is_string($committee) === true) {
                    $roles[] = $committee;
                }
            }
        }

        return array_values(array_unique($roles));

    }//end memberRoles()
}//end class
