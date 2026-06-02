<?php
/**
 * Decidesk Board Material Authorization Service
 *
 * Enforces the least-privilege access-level model on board materials at the API layer.
 * A board member may view a material only when their role is permitted by the material's
 * access-level enum. Every access decision (granted or denied) is logged to the audit trail.
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
 * Access-level enforcement for board materials.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
 */
class BoardMaterialAuthorizationService
{
    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Schema slug.
     *
     * @var string
     */
    private const SCHEMA = 'board-material';

    /**
     * Mapping of access-level enum to the board roles permitted to view a material.
     * board-only materials are visible to every board role; the more restrictive
     * levels list only the roles that may see them.
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
            'secretary',
        ],
        'executive-only'   => ['chairman', 'vice-chairman', 'executive-member', 'secretary'],
        'audit-committee'  => ['chairman', 'member', 'independent-member', 'secretary'],
        'external-auditor' => ['external-auditor'],
        'regulator'        => ['regulator'],
    ];

    /**
     * Constructor for BoardMaterialAuthorizationService.
     *
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     * @param AuditLogService    $auditLogService The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Determine whether a given role may view a material at a given access-level.
     *
     * @param string $accessLevel Material access-level enum value.
     * @param string $role        BoardMember role enum value.
     *
     * @return bool True when the role is permitted.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function roleMayView(string $accessLevel, string $role): bool
    {
        $allowed = (self::ACCESS_MATRIX[$accessLevel] ?? []);
        return in_array($role, $allowed, true);

    }//end roleMayView()

    /**
     * Resolve the role of a board member.
     *
     * @param string $boardMemberId BoardMember UUID.
     *
     * @return string|null The role enum value, or null when the member is unknown.
     */
    private function memberRole(string $boardMemberId): ?string
    {
        $entity = $this->objectService()->find(id: $boardMemberId, register: self::REGISTER, schema: 'board-member');
        if ($entity === null) {
            return null;
        }

        $data = $entity->jsonSerialize();
        $role = ($data['rol'] ?? null);
        if ($role === null) {
            return null;
        }

        return (string) $role;

    }//end memberRole()

    /**
     * Check whether a board member may view a specific material, and log the decision.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $materialId    BoardMaterial UUID.
     *
     * @return bool True when access is granted.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function canViewMaterial(string $boardMemberId, string $materialId): bool
    {
        $materialEntity = $this->objectService()->find(id: $materialId, register: self::REGISTER, schema: self::SCHEMA);
        if ($materialEntity === null) {
            $this->logMaterialAccess(boardMemberId: $boardMemberId, materialId: $materialId, granted: false);
            return false;
        }

        $material    = $materialEntity->jsonSerialize();
        $accessLevel = (string) ($material['access-level'] ?? 'board-only');
        $role        = $this->memberRole(boardMemberId: $boardMemberId);

        $granted = ($role !== null && $this->roleMayView(accessLevel: $accessLevel, role: $role) === true);
        $this->logMaterialAccess(boardMemberId: $boardMemberId, materialId: $materialId, granted: $granted);

        return $granted;

    }//end canViewMaterial()

    /**
     * Return all materials of a meeting that are accessible to a given role.
     *
     * @param string $meetingId BoardMeeting UUID.
     * @param string $role      BoardMember role enum value.
     *
     * @return array<int, array> Serialized accessible materials.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function filterMaterialsByRole(string $meetingId, string $role): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $entities = $objectService->findAll(['filters' => ['meeting-koppeling' => $meetingId]]);

        $result = [];
        foreach ($entities as $entity) {
            $material    = $entity->jsonSerialize();
            $accessLevel = (string) ($material['access-level'] ?? 'board-only');
            if ($this->roleMayView(accessLevel: $accessLevel, role: $role) === true) {
                $result[] = $material;
            }
        }//end foreach

        return $result;

    }//end filterMaterialsByRole()

    /**
     * Log a material-access attempt to the audit trail.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $materialId    BoardMaterial UUID.
     * @param bool   $granted       Whether access was granted.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function logMaterialAccess(string $boardMemberId, string $materialId, bool $granted): void
    {
        $outcome = 'denied';
        if ($granted === true) {
            $outcome = 'granted';
        }

        $this->auditLogService->append(
            actor: $boardMemberId,
            action: 'material-access',
            objectUids: [$materialId, $outcome]
        );

    }//end logMaterialAccess()
}//end class
