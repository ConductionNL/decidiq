<?php
/**
 * Decidesk Conflict of Interest Service
 *
 * Enforces mandatory per-agenda-item conflict-of-interest declarations and tracks
 * the action taken (recuse from discussion, recuse from vote, disclose-and-participate).
 * Access to an agenda item's materials is blocked until a declaration exists for the
 * (board-member, agenda-item) pair.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Conflict-of-interest declaration enforcement and action tracking.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */
class ConflictOfInterestService
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
    private const SCHEMA = 'conflict-of-interest';

    /**
     * Constructor for ConflictOfInterestService.
     *
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     * @param AuditLogService    $auditLogService The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
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
     * Determine whether a declaration already exists for a (member, agenda-item) pair.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return bool True when a declaration exists.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function requireDeclaration(string $boardMemberId, string $agendaItemId): bool
    {
        return $this->getActiveConflict(boardMemberId: $boardMemberId, agendaItemId: $agendaItemId) !== null;

    }//end requireDeclaration()

    /**
     * Return the conflict record for a (member, agenda-item) pair, or null.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return array|null Serialized conflict record or null.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function getActiveConflict(string $boardMemberId, string $agendaItemId): ?array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $entities = $objectService->findAll(
            [
                'filters' => [
                    'board-member-koppeling' => $boardMemberId,
                    'agenda-item-koppeling'  => $agendaItemId,
                ],
            ]
        );

        foreach ($entities as $entity) {
            return $entity->jsonSerialize();
        }

        return null;

    }//end getActiveConflict()

    /**
     * Create a conflict-of-interest declaration and notify the chairman if material.
     *
     * @param string      $boardMemberId BoardMember UUID.
     * @param string      $agendaItemId  AgendaItem UUID.
     * @param string      $type          Declaration-type enum value.
     * @param string|null $description   Description (required for material/non-material).
     * @param string|null $severity      Severity enum value (material|non-material) or null for 'none'.
     *
     * @return array The serialized created declaration.
     *
     * @throws \InvalidArgumentException When a non-'none' declaration lacks an adequate description.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function declare(string $boardMemberId, string $agendaItemId, string $type, ?string $description, ?string $severity): array
    {
        if ($type !== 'none' && (mb_strlen((string) $description) < 10)) {
            throw new InvalidArgumentException('A description of at least 10 characters is required for a declared conflict.');
        }

        $actionTaken = null;
        if ($type === 'none') {
            $actionTaken = 'no-action-needed';
        }

        $record = [
            'board-member-koppeling' => $boardMemberId,
            'agenda-item-koppeling'  => $agendaItemId,
            'declaration-type'       => $type,
            'description'            => $description,
            'severity'               => $severity,
            'action-taken'           => $actionTaken,
            'declaration-timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $record);
        $data  = $saved->jsonSerialize();

        $declarationId = (string) ($data['id'] ?? '');
        $this->auditLogService->append(
            actor: $boardMemberId,
            action: 'conflict-declaration',
            objectUids: [$agendaItemId, $declarationId]
        );

        if ($severity === 'material') {
            $this->notifyChairman(
                boardMemberId: $boardMemberId,
                agendaItemId: $agendaItemId,
                declarationId: $declarationId
            );
        }

        return $data;

    }//end declare()

    /**
     * Record the action taken for an existing declaration.
     *
     * @param string $declarationId Declaration UUID.
     * @param string $actionTaken   Action-taken enum value.
     *
     * @return array The serialized updated declaration.
     *
     * @throws \RuntimeException When the declaration does not exist.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function recordAction(string $declarationId, string $actionTaken): array
    {
        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $declarationId, register: self::REGISTER, schema: self::SCHEMA);
        if ($entity === null) {
            throw new RuntimeException('Conflict declaration '.$declarationId.' not found.');
        }

        $record = $entity->jsonSerialize();

        $record['action-taken'] = $actionTaken;
        unset($record['@self']);

        $saved = $objectService->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $record);

        $this->auditLogService->append(
            actor: (string) ($record['board-member-koppeling'] ?? 'system'),
            action: 'conflict-declaration',
            objectUids: [$declarationId]
        );

        return $saved->jsonSerialize();

    }//end recordAction()

    /**
     * Notify the chairman and secretary of a material conflict.
     *
     * @param string $boardMemberId BoardMember UUID who declared.
     * @param string $agendaItemId  AgendaItem UUID.
     * @param string $declarationId Declaration UUID.
     *
     * @return void
     */
    private function notifyChairman(string $boardMemberId, string $agendaItemId, string $declarationId): void
    {
        // The chairman/secretary are resolved per board; a material conflict is logged so the
        // notification engine (x-openregister-notifications) and admin views can surface it.
        $this->logger->info(
            'Decidesk: material conflict declared',
            [
                'boardMember' => $boardMemberId,
                'agendaItem'  => $agendaItemId,
                'declaration' => $declarationId,
            ]
        );

    }//end notifyChairman()
}//end class
