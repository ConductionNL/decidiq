<?php
/**
 * Decidesk Board Material Authorization Service
 *
 * Enforces the access-level compartments on board materials at view time and
 * logs every access decision to the immutable audit trail (least-privilege).
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

/**
 * Maps board roles to the material access-level compartments they may view.
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
     * Role to access-level compartment matrix.
     *
     * @var array<string,array<string>>
     */
    private const ACCESS_MATRIX = [
        'chairman'                => ['board-only', 'executive-only', 'audit-committee'],
        'vice-chairman'           => ['board-only', 'executive-only', 'audit-committee'],
        'member'                  => ['board-only'],
        'executive-member'        => ['board-only', 'executive-only'],
        'non-executive-member'    => ['board-only'],
        'independent-member'      => ['board-only', 'audit-committee'],
        'employee-representative' => ['board-only'],
        'external-auditor'        => ['external-auditor', 'audit-committee'],
        'regulator'               => ['regulator'],
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container The DI container.
     * @param BoardAuditLogService $auditLog  The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly BoardAuditLogService $auditLog,
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
     * Return the access-level compartments a given role may view.
     *
     * @param string $role Board/external role.
     *
     * @return array<string>
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function compartmentsForRole(string $role): array
    {
        return (self::ACCESS_MATRIX[$role] ?? []);

    }//end compartmentsForRole()

    /**
     * Decide whether a role may view a material with a given access-level.
     *
     * @param string $role        Board/external role.
     * @param string $accessLevel Material access-level enum value.
     *
     * @return bool
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function roleCanViewLevel(string $role, string $accessLevel): bool
    {
        return in_array($accessLevel, $this->compartmentsForRole(role: $role), true);

    }//end roleCanViewLevel()

    /**
     * Decide whether a board member may view a specific material, with audit logging.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $role          The board member's role.
     * @param string $materialId    BoardMaterial UUID.
     * @param string $actorUuid     Acting user UUID (for audit).
     *
     * @return bool
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function canViewMaterial(string $boardMemberId, string $role, string $materialId, string $actorUuid): bool
    {
        $objectService = $this->objectService();
        $material      = $objectService->find(id: $materialId, register: self::REGISTER, schema: 'board-material');
        if ($material === null) {
            return false;
        }

        $accessLevel = (string) ($material->jsonSerialize()['accessLevel'] ?? '');
        $granted     = $this->roleCanViewLevel(role: $role, accessLevel: $accessLevel);

        $this->logMaterialAccess(actorUuid: $actorUuid, materialId: $materialId, granted: $granted);

        return $granted;

    }//end canViewMaterial()

    /**
     * Filter a list of materials down to those the role may view.
     *
     * @param array<int,array<string,mixed>> $materials Materials as arrays.
     * @param string                         $role      Board/external role.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function filterMaterialsByRole(array $materials, string $role): array
    {
        $compartments = $this->compartmentsForRole(role: $role);

        return array_values(
            array_filter(
                $materials,
                static function (array $material) use ($compartments): bool {
                    return in_array(($material['accessLevel'] ?? ''), $compartments, true);
                }
            )
        );

    }//end filterMaterialsByRole()

    /**
     * Record a material-access decision in the audit trail.
     *
     * @param string $actorUuid  Acting user UUID.
     * @param string $materialId BoardMaterial UUID.
     * @param bool   $granted    Whether access was granted.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function logMaterialAccess(string $actorUuid, string $materialId, bool $granted): void
    {
        $outcome = 'denied';
        if ($granted === true) {
            $outcome = 'granted';
        }

        $this->auditLog->append(actorUuid: $actorUuid, action: 'material-access', objectUids: [$materialId, $outcome]);

    }//end logMaterialAccess()
}//end class
