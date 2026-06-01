<?php
/**
 * Decidesk Migrate Tasks To Action Items Repair Step
 *
 * Idempotent, resume-safe migration that retires the legacy in-app `task`
 * and `delegation` object stores (p4-collaboration) by projecting each
 * legacy Task onto the canonical `action-item` object and replaying each
 * Delegation's assignee semantics onto that action item, then archiving the
 * legacy objects via OpenRegister's soft-delete (archival) workflow — never
 * hard-deleting them, so they remain queryable for audit.
 *
 * See openspec/changes/migrate-action-items-to-deck-leaf/design.md (D3).
 *
 * @category Repair
 * @package  OCA\Decidesk\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that migrates legacy Task / Delegation objects onto the
 * canonical action-item object and archives the legacy records.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
 */
class MigrateTasksToActionItems implements IRepairStep
{

    /**
     * Marker field stamped on an action item that was created from a legacy
     * Task, so re-runs can detect existing projections and avoid duplicates.
     *
     * @var string
     */
    private const MIGRATED_FROM_KEY = 'migratedFromTaskUuid';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR ObjectService)
     * @param LoggerInterface    $logger    PSR-3 logger
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the human-readable name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
     */
    public function getName(): string
    {
        return 'Migrate legacy Decidesk tasks/delegations onto canonical action items';

    }//end getName()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * Returns null when OpenRegister is not available, allowing the repair
     * step to no-op gracefully on instances without OR.
     *
     * @return object|null
     */
    private function objectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk MigrateTasksToActionItems: ObjectService unavailable, skipping',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end objectService()

    /**
     * Normalise an ObjectService result into a plain associative array.
     *
     * @param mixed $item Raw object/entity/array from ObjectService
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'getObject') === true) {
            return $item->getObject();
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract a UUID from a normalised object array.
     *
     * @param array<string, mixed> $item The normalised object array
     *
     * @return string The UUID, or empty string when absent
     */
    private function uuidOf(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end uuidOf()

    /**
     * Build the index of already-migrated legacy Task UUIDs from existing
     * action items, so a re-run produces no duplicate action items.
     *
     * @param object $objectService The OpenRegister ObjectService
     *
     * @return array<string, bool> Map of legacy-task-uuid => true
     */
    private function alreadyMigratedIndex(object $objectService): array
    {
        $index = [];
        $items = $objectService->findAll(
            [
                'filters' => [
                    'register' => 'decidesk',
                    'schema'   => 'action-item',
                ],
            ]
        );

        foreach ($items as $raw) {
            $item   = $this->toArray(item: $raw);
            $marker = (string) ($item[self::MIGRATED_FROM_KEY] ?? '');
            if ($marker !== '') {
                $index[$marker] = true;
            }
        }

        return $index;

    }//end alreadyMigratedIndex()

    /**
     * Run the migration.
     *
     * @param IOutput $output Migration output channel
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3
     */
    public function run(IOutput $output): void
    {
        $objectService = $this->objectService();
        if ($objectService === null) {
            $output->info('OpenRegister unavailable — skipping task/delegation migration.');
            return;
        }

        try {
            $migratedIndex = $this->alreadyMigratedIndex(objectService: $objectService);
            $createdItems  = $this->migrateTasks(objectService: $objectService, migratedIndex: $migratedIndex);
            $delegations   = $this->migrateDelegations(objectService: $objectService, taskToItem: $createdItems);

            $output->info(
                sprintf(
                    'Decidesk migration: %d task(s) projected to action items, %d delegation(s) replayed.',
                    count($createdItems),
                    $delegations
                )
            );
        } catch (Throwable $e) {
            // A repair step must not fatally abort an upgrade; log and continue.
            $this->logger->error(
                'Decidesk MigrateTasksToActionItems failed',
                ['exception' => $e->getMessage()]
            );
            $output->warning('Decidesk task/delegation migration encountered an error: '.$e->getMessage());
        }//end try

    }//end run()

    /**
     * Project each legacy Task onto an action item and archive the Task.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string, bool> $migratedIndex Map of already-migrated task UUIDs
     *
     * @return array<string, string> Map of legacy-task-uuid => action-item uuid
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    private function migrateTasks(object $objectService, array $migratedIndex): array
    {
        $created = [];

        $tasks = $objectService->findAll(
            [
                'filters' => [
                    'register' => 'decidesk',
                    'schema'   => 'task',
                ],
            ]
        );

        foreach ($tasks as $raw) {
            $task     = $this->toArray(item: $raw);
            $taskUuid = $this->uuidOf(item: $task);
            if ($taskUuid === '') {
                continue;
            }

            // Resume-safe: skip tasks already projected to an action item.
            if (isset($migratedIndex[$taskUuid]) === true) {
                continue;
            }

            $actionItem = [
                'title'                 => (string) ($task['title'] ?? 'Untitled action item'),
                'taskStatus'            => $this->mapTaskStatus(status: (string) ($task['taskStatus'] ?? 'open')),
                self::MIGRATED_FROM_KEY => $taskUuid,
            ];

            if (empty($task['description']) === false) {
                $actionItem['description'] = (string) $task['description'];
            }

            if (empty($task['assignee']) === false) {
                $actionItem['assignee'] = (string) $task['assignee'];
            }

            if (empty($task['delegator']) === false) {
                $actionItem['delegator'] = (string) $task['delegator'];
            }

            if (empty($task['dueDate']) === false) {
                $actionItem['dueDate'] = (string) $task['dueDate'];
            }

            $saved   = $this->toArray(
                item: $objectService->saveObject(
                    object: $actionItem,
                    register: 'decidesk',
                    schema: 'action-item',
                )
            );
            $newUuid = $this->uuidOf(item: $saved);

            $created[$taskUuid] = $newUuid;

            // Archive the legacy Task via OR soft-delete (hardDelete:false) — never purge.
            $this->archive(objectService: $objectService, schema: 'task', uuid: $taskUuid);
        }//end foreach

        return $created;

    }//end migrateTasks()

    /**
     * Replay each legacy Delegation onto the corresponding action item's
     * assignee/delegator and archive the Delegation.
     *
     * @param object                $objectService The OpenRegister ObjectService
     * @param array<string, string> $taskToItem    Map of legacy-task-uuid => action-item uuid
     *
     * @return int Number of delegations replayed
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     */
    private function migrateDelegations(object $objectService, array $taskToItem): int
    {
        $count = 0;

        $delegations = $objectService->findAll(
            [
                'filters' => [
                    'register' => 'decidesk',
                    'schema'   => 'delegation',
                ],
            ]
        );

        foreach ($delegations as $raw) {
            $delegation     = $this->toArray(item: $raw);
            $delegationUuid = $this->uuidOf(item: $delegation);
            if ($delegationUuid === '') {
                continue;
            }

            $legacyTaskUuid = (string) ($delegation['taskUid'] ?? '');
            $itemUuid       = (string) ($taskToItem[$legacyTaskUuid] ?? '');
            $this->replayDelegationOntoItem(
                objectService: $objectService,
                delegation: $delegation,
                itemUuid: $itemUuid,
            );

            // Archive the legacy Delegation regardless — its semantics now live on
            // the action item + the OR audit trail.
            $this->archive(objectService: $objectService, schema: 'delegation', uuid: $delegationUuid);
            $count++;
        }//end foreach

        return $count;

    }//end migrateDelegations()

    /**
     * Replay one active delegation's assignee/delegator semantics onto its
     * corresponding action item. No-op when the item is unknown or the
     * delegation is not an active substitution.
     *
     * @param object               $objectService The OpenRegister ObjectService
     * @param array<string, mixed> $delegation    The legacy delegation array
     * @param string               $itemUuid      The action-item UUID (empty when unknown)
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     */
    private function replayDelegationOntoItem(object $objectService, array $delegation, string $itemUuid): void
    {
        $isActiveSubstitution = ((string) ($delegation['status'] ?? '') === 'active'
            && empty($delegation['substitute']) === false);

        if ($itemUuid === '' || $isActiveSubstitution === false) {
            return;
        }

        $itemEntity = $objectService->find(id: $itemUuid, register: 'decidesk', schema: 'action-item');
        if ($itemEntity === null) {
            return;
        }

        $item = $this->toArray(item: $itemEntity);

        // Preserve the original owner as delegator so it can be reclaimed.
        if (empty($item['delegator']) === true && empty($item['assignee']) === false) {
            $item['delegator'] = (string) $item['assignee'];
        }

        $item['assignee'] = (string) $delegation['substitute'];

        if (empty($delegation['substituteUntil']) === false) {
            $item['substituteUntil'] = (string) $delegation['substituteUntil'];
        }

        $objectService->saveObject(
            object: $item,
            register: 'decidesk',
            schema: 'action-item',
            uuid: $itemUuid,
        );

    }//end replayDelegationOntoItem()

    /**
     * Map a legacy Task taskStatus enum to the action-item taskStatus enum.
     *
     * @param string $status Legacy Task status
     *
     * @return string Action-item status
     */
    private function mapTaskStatus(string $status): string
    {
        return match ($status) {
            'pending', 'reclaimed' => 'open',
            'in-progress'          => 'in-progress',
            'completed'            => 'completed',
            default                => 'open',
        };

    }//end mapTaskStatus()

    /**
     * Archive a legacy object via OpenRegister's soft-delete workflow.
     *
     * Resume-safe: a delete on an already-archived object is logged and
     * ignored rather than aborting the migration.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $schema        The legacy schema slug
     * @param string $uuid          The legacy object UUID
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
     */
    private function archive(object $objectService, string $schema, string $uuid): void
    {
        try {
            $objectService->deleteObject(uuid: $uuid, register: 'decidesk', schema: $schema);
        } catch (Throwable $e) {
            $this->logger->info(
                'Decidesk MigrateTasksToActionItems: archive skipped (already archived or unavailable)',
                ['schema' => $schema, 'uuid' => $uuid, 'reason' => $e->getMessage()]
            );
        }

    }//end archive()
}//end class
