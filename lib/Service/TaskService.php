<?php
/**
 * Decidesk Task Service
 *
 * Stateless service handling governance Task lifecycle transitions and
 * reclaim semantics. Tasks are distinct from CalDAV ActionItem follow-up
 * tasks (see ADR-002) — they capture delegation with optional substitute
 * and an explicit reclaimable lifecycle.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing governance Task lifecycle, delegation, and reclaim.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2
 */
class TaskService
{

    /**
     * Allowed lifecycle transitions for Task objects.
     *
     * Tasks can be reclaimed from any non-terminal state by setting status
     * to 'reclaimed' and resetting assignee back to the delegator.
     *
     * @var array<string, array<string>>
     */
    private const TASK_TRANSITIONS = [
        'pending'     => ['in-progress', 'completed', 'reclaimed'],
        'in-progress' => ['completed', 'reclaimed'],
        'completed'   => ['reclaimed'],
        'reclaimed'   => ['pending', 'in-progress'],
    ];

    /**
     * Construct the TaskService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Persist a Task object via OpenRegister.
     *
     * @param array<string, mixed> $task Task properties
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the object cannot be saved
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.1
     */
    public function saveTask(array $task): array
    {
        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $task,
            register: 'decidesk',
            schema: 'task',
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end saveTask()

    /**
     * Find a Task by UUID.
     *
     * @param string $taskId UUID of the Task object
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.1
     */
    public function findTask(string $taskId): ?array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('task');

        $entity = $objectService->find($taskId);
        if ($entity === null) {
            return null;
        }

        return $entity->getObject();

    }//end findTask()

    /**
     * Update the status of a Task with state-machine validation.
     *
     * @param string $taskId    UUID of the Task object
     * @param string $newStatus Target taskStatus
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     * @throws \RuntimeException         When the task cannot be found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.5
     */
    public function updateTaskStatus(string $taskId, string $newStatus): array
    {
        $task = $this->findTask(taskId: $taskId);
        if ($task === null) {
            throw new \RuntimeException("Task $taskId not found");
        }

        $current = $task['taskStatus'] ?? 'pending';
        $allowed = self::TASK_TRANSITIONS[$current] ?? [];

        if (in_array($newStatus, $allowed, true) === false) {
            throw new \InvalidArgumentException(
                "Transition from '$current' to '$newStatus' is not allowed for task"
            );
        }

        $task['taskStatus'] = $newStatus;
        if ($newStatus === 'completed') {
            $task['completedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $task,
            register: 'decidesk',
            schema: 'task',
            uuid: $taskId,
        );

        $this->logger->info(
            'Decidesk: Task status updated',
            ['taskId' => $taskId, 'from' => $current, 'to' => $newStatus]
        );

        return $task;

    }//end updateTaskStatus()

    /**
     * Reclaim a task: reset assignee to delegator and mark status='reclaimed'.
     *
     * @param string $taskId UUID of the Task object
     * @param string $actor  UUID of the actor (must be the original delegator)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When actor is not the delegator
     * @throws \RuntimeException         When the task cannot be found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.5
     */
    public function reclaimTask(string $taskId, string $actor): array
    {
        $task = $this->findTask(taskId: $taskId);
        if ($task === null) {
            throw new \RuntimeException("Task $taskId not found");
        }

        $delegator = $task['delegator'] ?? null;
        if ($delegator === null || $delegator !== $actor) {
            throw new \InvalidArgumentException(
                'Only the original delegator may reclaim this task'
            );
        }

        $task['assignee']   = $delegator;
        $task['taskStatus'] = 'reclaimed';

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $task,
            register: 'decidesk',
            schema: 'task',
            uuid: $taskId,
        );

        $this->logger->info(
            'Decidesk: Task reclaimed',
            ['taskId' => $taskId, 'actor' => $actor]
        );

        return $task;

    }//end reclaimTask()
}//end class
