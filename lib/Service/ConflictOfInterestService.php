<?php
/**
 * Decidesk Conflict Of Interest Service
 *
 * Manages mandatory per-agenda-item conflict-of-interest declarations and the
 * access/vote restrictions that follow from a declared material conflict.
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-authoritative conflict-of-interest declaration management.
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
     * Constructor.
     *
     * @param ContainerInterface   $container The DI container.
     * @param LoggerInterface      $logger    The logger.
     * @param BoardAuditLogService $auditLog  The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
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
     * Normalise a saved object (or array) into an associative array.
     *
     * @param mixed               $saved    ObjectEntity or array.
     * @param array<string,mixed> $fallback Original payload when serialization is unavailable.
     *
     * @return array<string,mixed>
     */
    private function serializeResult(mixed $saved, array $fallback): array
    {
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return $saved->jsonSerialize();
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        return $fallback;

    }//end serializeResult()

    /**
     * Return the active conflict declaration for a (member, agenda-item) pair.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return array<string,mixed>|null The declaration, or null if none exists.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function getActiveConflict(string $boardMemberId, string $agendaItemId): ?array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $result = $objectService->findAll(
            ['filters' => ['relations.boardMember' => $boardMemberId, 'relations.agendaItem' => $agendaItemId]]
        );

        foreach (($result['results'] ?? $result) as $item) {
            return $this->serializeResult(saved: $item, fallback: []);
        }

        return null;

    }//end getActiveConflict()

    /**
     * Determine whether a declaration is required (none exists yet).
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return bool True when no declaration exists and one is required.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function requireDeclaration(string $boardMemberId, string $agendaItemId): bool
    {
        return $this->getActiveConflict(boardMemberId: $boardMemberId, agendaItemId: $agendaItemId) === null;

    }//end requireDeclaration()

    /**
     * File a conflict declaration.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     * @param string $type          Declaration-type enum value.
     * @param string $severity      Severity enum value.
     * @param string $description   Free-text description (required for non-none).
     * @param string $actorUuid     Acting user UUID (for audit).
     *
     * @return array<string,mixed> The persisted declaration.
     *
     * @throws \RuntimeException On invalid input.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function declare(
        string $boardMemberId,
        string $agendaItemId,
        string $type,
        string $severity,
        string $description,
        string $actorUuid
    ): array {
        $allowedTypes = ['financial-interest', 'personal-relationship', 'competing-business', 'prior-involvement', 'none'];
        if (in_array($type, $allowedTypes, true) === false) {
            throw new \RuntimeException('Invalid declaration type');
        }

        if ($type !== 'none' && trim($description) === '') {
            throw new \RuntimeException('A description is required for a declared conflict');
        }

        $declaration = [
            'declarationType'      => $type,
            'description'          => $description,
            'severity'             => $severity,
            'actionTaken'          => 'no-action-needed',
            'declarationTimestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'relations'            => [
                ['schema' => 'board-member', 'id' => $boardMemberId],
                ['schema' => 'agenda-item', 'id' => $agendaItemId],
            ],
        ];

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $declaration);
        $this->auditLog->append(actorUuid: $actorUuid, action: 'conflict-declaration', objectUids: [$boardMemberId, $agendaItemId]);

        if ($severity === 'material') {
            $this->notifyChair(boardMemberId: $boardMemberId, agendaItemId: $agendaItemId);
        }

        return $this->serializeResult(saved: $saved, fallback: $declaration);

    }//end declare()

    /**
     * Record the action taken for a declaration.
     *
     * @param string $declarationId Declaration UUID.
     * @param string $actionTaken   Action-taken enum value.
     * @param string $actorUuid     Acting user UUID (for audit).
     *
     * @return array<string,mixed> The updated declaration.
     *
     * @throws \RuntimeException When the declaration is missing or action invalid.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function recordAction(string $declarationId, string $actionTaken, string $actorUuid): array
    {
        $allowed = ['recused-from-discussion', 'recused-from-vote', 'disclosed-and-participated', 'no-action-needed'];
        if (in_array($actionTaken, $allowed, true) === false) {
            throw new \RuntimeException('Invalid action-taken value');
        }

        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $declarationId, register: self::REGISTER, schema: self::SCHEMA);
        if ($entity === null) {
            throw new \RuntimeException('Declaration not found');
        }

        $data = $entity->jsonSerialize();
        $data['actionTaken'] = $actionTaken;

        $saved = $objectService->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $data, uuid: $declarationId);
        $this->auditLog->append(actorUuid: $actorUuid, action: 'conflict-declaration', objectUids: [$declarationId]);

        return $this->serializeResult(saved: $saved, fallback: $data);

    }//end recordAction()

    /**
     * Determine whether a member is barred from voting on an agenda item.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return bool True when a recused-from-vote/discussion conflict is active.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
     */
    public function isBarredFromVoting(string $boardMemberId, string $agendaItemId): bool
    {
        $conflict = $this->getActiveConflict(boardMemberId: $boardMemberId, agendaItemId: $agendaItemId);
        if ($conflict === null) {
            return false;
        }

        return in_array(($conflict['actionTaken'] ?? ''), ['recused-from-vote', 'recused-from-discussion'], true);

    }//end isBarredFromVoting()

    /**
     * Send a notification to the chair/secretary about a material conflict.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $agendaItemId  AgendaItem UUID.
     *
     * @return void
     */
    private function notifyChair(string $boardMemberId, string $agendaItemId): void
    {
        try {
            $manager      = $this->container->get(\OCP\Notification\IManager::class);
            $notification = $manager->createNotification();
            $notification->setApp('decidesk')
                ->setObject('conflict', $agendaItemId)
                ->setSubject('material_conflict', ['boardMember' => $boardMemberId, 'agendaItem' => $agendaItemId])
                ->setDateTime(new \DateTime());
            $manager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to notify chair of material conflict', ['exception' => $e->getMessage()]);
        }

    }//end notifyChair()
}//end class
