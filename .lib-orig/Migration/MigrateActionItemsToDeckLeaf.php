<?php
/**
 * Decidesk Migrate Action Items To Deck Leaf Repair Step
 *
 * One-shot, idempotent, resume-safe migration of the legacy p4-collaboration
 * in-app `task` and `delegation` object stores onto the ADR-002 CalDAV VTODO
 * ActionItem source of truth, surfaced through the ADR-019 Deck integration
 * leaf (migrate-action-items-to-deck-leaf, design D1/D2/D3).
 *
 * For each legacy `task` object:
 *
 *   1. Ensure a canonical `ActionItem` (VTODO) record exists carrying the same
 *      title / assignee / dueDate / status (created from the Task when the Task
 *      predates VTODO storage — design D3). The VTODO is the source of truth;
 *      the deck leaf renders one card per VTODO over the registry binding.
 *   2. Stamp the legacy `task` with a migration marker pointing at its VTODO.
 *   3. Archive the legacy `task` via OR's archival workflow (soft delete; the
 *      object remains queryable for audit — never hard-purged).
 *
 * For each legacy `delegation` object (design D2):
 *
 *   1. Replay the delegation/reclaim semantics onto the bound VTODO ActionItem:
 *      the effective assignee (substitute, or the delegator when the delegation
 *      was reclaimed/revoked) is written to the ActionItem's `assignee`. Saving
 *      the ActionItem records the assignee change in OpenRegister's immutable
 *      audit trail — preserving the governance-relevant "reclaim" fact without a
 *      bespoke `delegation` object (REQ-AI-DECK-002).
 *   2. Archive the legacy `delegation` object via OR's archival workflow.
 *
 * Resume-safe: objects already stamped `_migratedToDeckLeaf` are skipped;
 * archived objects drop out of the active findAll() set, so a re-run produces
 * no duplicate VTODOs/cards and no double-archival (REQ-AI-DECK-003).
 *
 * Graceful no-op: instances without legacy `task` / `delegation` objects exit
 * cleanly — no error is raised. The Deck app does not need to be installed for
 * the migration to run; the deck board is a UI projection over the VTODOs.
 *
 * @category Migration
 * @package  OCA\Decidesk\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Migration;

use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step migrating legacy `task` / `delegation` objects onto the VTODO
 * ActionItem source of truth (rendered via the Deck integration leaf), then
 * archiving the legacy objects (no hard delete — audit trail retained).
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
 */
class MigrateActionItemsToDeckLeaf implements IRepairStep
{

    /**
     * The decidesk register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * The legacy in-app task schema slug being retired.
     *
     * @var string
     */
    private const LEGACY_TASK_SCHEMA = 'task';

    /**
     * The legacy in-app delegation schema slug being retired.
     *
     * @var string
     */
    private const LEGACY_DELEGATION_SCHEMA = 'delegation';

    /**
     * The canonical VTODO-backed ActionItem schema (ADR-002 source of truth).
     *
     * @var string
     */
    private const ACTION_ITEM_SCHEMA = 'ActionItem';

    /**
     * Migration marker stamped on each legacy object before archival.
     * Presence of this key causes a re-run to skip the object.
     *
     * @var string
     */
    private const MIGRATION_MARKER = '_migratedToDeckLeaf';

    /**
     * Property on the migrated ActionItem that records the originating legacy
     * task UUID, used to avoid creating a duplicate VTODO on re-run.
     *
     * @var string
     */
    private const SOURCE_TASK_PROPERTY = '_migratedFromTaskUuid';

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Detects OpenRegister availability.
     * @param ContainerInterface $container       DI container (lazy-loads OR services).
     * @param LoggerInterface    $logger          Logger.
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    public function getName(): string
    {
        return 'Migrate legacy Decidesk Task/Delegation objects to the Deck leaf (VTODO ActionItem source of truth)';

    }//end getName()

    /**
     * Run the migration.
     *
     * @param IOutput $output Progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available — skipping action-item migration.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $output->warning('Could not resolve OpenRegister ObjectService — skipping action-item migration.');
            $this->logger->warning(
                'Decidesk: action-item migration could not resolve ObjectService',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $tasksMigrated = $this->migrateTasks(objectService: $objectService, output: $output);
        $delegations   = $this->migrateDelegations(objectService: $objectService, output: $output);

        $output->info(
            'Decidesk action-item migration complete: '.$tasksMigrated.' task(s) and '
            .$delegations.' delegation(s) migrated.'
        );

    }//end run()

    /**
     * Migrate every legacy `task` object onto a VTODO ActionItem and archive it.
     *
     * @param object  $objectService The OR ObjectService.
     * @param IOutput $output        Progress reporting.
     *
     * @return int The number of task objects migrated.
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    private function migrateTasks(object $objectService, IOutput $output): int
    {
        $legacyTasks = $this->loadLegacy(
            objectService: $objectService,
            schema: self::LEGACY_TASK_SCHEMA,
            output: $output,
        );

        $migrated = 0;
        foreach ($legacyTasks as $entity) {
            $task = $this->toArray(entity: $entity);
            if ($task === null) {
                continue;
            }

            // Resume-safe: skip anything already migrated (task-3.4).
            if (($task[self::MIGRATION_MARKER] ?? false) === true) {
                continue;
            }

            $uuid = (string) ($task['id'] ?? $task['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            try {
                $this->ensureActionItem(objectService: $objectService, task: $task, taskUuid: $uuid);
                $this->archiveLegacy(
                    objectService: $objectService,
                    schema: self::LEGACY_TASK_SCHEMA,
                    uuid: $uuid,
                    object: $task,
                );
                $migrated++;
                $this->logger->info(
                    'Decidesk: migrated legacy task to VTODO ActionItem + deck leaf',
                    ['uuid' => $uuid]
                );
            } catch (Throwable $e) {
                $output->warning('Failed to migrate task '.$uuid.': '.$e->getMessage());
                $this->logger->warning(
                    'Decidesk: task migration failed for one object',
                    ['uuid' => $uuid, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        return $migrated;

    }//end migrateTasks()

    /**
     * Replay every legacy `delegation` onto its VTODO ActionItem assignee, then
     * archive the delegation object (design D2 / REQ-AI-DECK-002).
     *
     * @param object  $objectService The OR ObjectService.
     * @param IOutput $output        Progress reporting.
     *
     * @return int The number of delegation objects migrated.
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
     */
    private function migrateDelegations(object $objectService, IOutput $output): int
    {
        $legacyDelegations = $this->loadLegacy(
            objectService: $objectService,
            schema: self::LEGACY_DELEGATION_SCHEMA,
            output: $output,
        );

        $migrated = 0;
        foreach ($legacyDelegations as $entity) {
            $delegation = $this->toArray(entity: $entity);
            if ($delegation === null) {
                continue;
            }

            if (($delegation[self::MIGRATION_MARKER] ?? false) === true) {
                continue;
            }

            $uuid = (string) ($delegation['id'] ?? $delegation['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            try {
                $this->replayDelegationOntoActionItem(
                    objectService: $objectService,
                    delegation: $delegation,
                );
                $this->archiveLegacy(
                    objectService: $objectService,
                    schema: self::LEGACY_DELEGATION_SCHEMA,
                    uuid: $uuid,
                    object: $delegation,
                );
                $migrated++;
                $this->logger->info(
                    'Decidesk: replayed legacy delegation onto VTODO ActionItem assignee + audit',
                    ['uuid' => $uuid]
                );
            } catch (Throwable $e) {
                $output->warning('Failed to migrate delegation '.$uuid.': '.$e->getMessage());
                $this->logger->warning(
                    'Decidesk: delegation migration failed for one object',
                    ['uuid' => $uuid, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        return $migrated;

    }//end migrateDelegations()

    /**
     * Ensure a canonical VTODO ActionItem exists for a legacy task.
     *
     * Idempotent: if an ActionItem already carries this task's UUID in
     * `_migratedFromTaskUuid`, no second VTODO is created (resume-safe, task-3.4).
     *
     * @param object              $objectService The OR ObjectService.
     * @param array<string,mixed> $task          The legacy task object.
     * @param string              $taskUuid      The legacy task UUID.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    private function ensureActionItem(object $objectService, array $task, string $taskUuid): void
    {
        if ($this->actionItemExistsForTask(objectService: $objectService, taskUuid: $taskUuid) === true) {
            return;
        }

        $actionItem = [
            'title'                    => (string) ($task['title'] ?? 'Untitled action item'),
            'taskStatus'               => $this->mapTaskStatus(status: (string) ($task['taskStatus'] ?? 'pending')),
            self::SOURCE_TASK_PROPERTY => $taskUuid,
        ];

        $assignee = (string) ($task['assignee'] ?? '');
        if ($assignee !== '') {
            $actionItem['assignee'] = $assignee;
        }

        $dueDate = (string) ($task['dueDate'] ?? '');
        if ($dueDate !== '') {
            $actionItem['dueDate'] = $dueDate;
        }

        $meeting = (string) ($task['meeting'] ?? '');
        if ($meeting !== '') {
            $actionItem['relations'] = ['Meeting' => [$meeting]];
        }

        $objectService->saveObject(
            register: self::REGISTER,
            schema: self::ACTION_ITEM_SCHEMA,
            object: $actionItem,
        );

    }//end ensureActionItem()

    /**
     * Replay a delegation's effective assignee onto its target VTODO ActionItem.
     *
     * The substitute holds the item while the delegation is active; once it is
     * reclaimed/revoked/expired the delegator regains it. Saving the ActionItem
     * records the assignee change in OpenRegister's immutable audit trail,
     * preserving the reclaim fact (REQ-AI-DECK-002 / design D2). Returns silently
     * when the delegation does not point at a resolvable ActionItem.
     *
     * @param object              $objectService The OR ObjectService.
     * @param array<string,mixed> $delegation    The legacy delegation object.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     */
    private function replayDelegationOntoActionItem(object $objectService, array $delegation): void
    {
        $targetUuid = (string) ($delegation['task'] ?? $delegation['actionItem'] ?? '');
        if ($targetUuid === '') {
            return;
        }

        $entity = null;
        try {
            $entity = $objectService->find(
                id: $targetUuid,
                register: self::REGISTER,
                schema: self::ACTION_ITEM_SCHEMA,
            );
        } catch (Throwable) {
            return;
        }

        if ($entity === null) {
            return;
        }

        $actionItem = $this->toArray(entity: $entity);
        if ($actionItem === null) {
            return;
        }

        // Effective assignee: substitute while active, delegator once reclaimed.
        $status     = (string) ($delegation['status'] ?? $delegation['delegationStatus'] ?? 'active');
        $delegator  = (string) ($delegation['delegator'] ?? '');
        $substitute = (string) ($delegation['substitute'] ?? $delegation['delegate'] ?? '');

        $reclaimedStates   = ['revoked', 'expired', 'reclaimed'];
        $effectiveAssignee = $substitute;
        if (in_array($status, $reclaimedStates, true) === true) {
            $effectiveAssignee = $delegator;
        }

        if ($effectiveAssignee === '') {
            return;
        }

        $actionItem['assignee'] = $effectiveAssignee;

        $objectService->saveObject(
            register: self::REGISTER,
            schema: self::ACTION_ITEM_SCHEMA,
            object: $actionItem,
            uuid: $targetUuid,
        );

    }//end replayDelegationOntoActionItem()

    /**
     * Detect whether an ActionItem already records this task's UUID.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $taskUuid      The legacy task UUID.
     *
     * @return bool True when a VTODO ActionItem already exists for the task.
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    private function actionItemExistsForTask(object $objectService, string $taskUuid): bool
    {
        try {
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema(self::ACTION_ITEM_SCHEMA);
            $items = $objectService->findAll(limit: 5000);
        } catch (Throwable) {
            return false;
        }

        foreach ($items as $entity) {
            $item = $this->toArray(entity: $entity);
            if ($item === null) {
                continue;
            }

            if (($item[self::SOURCE_TASK_PROPERTY] ?? '') === $taskUuid) {
                return true;
            }
        }

        return false;

    }//end actionItemExistsForTask()

    /**
     * Map a legacy task lifecycle status onto the ActionItem taskStatus vocab.
     *
     * @param string $status The legacy task status.
     *
     * @return string The ActionItem status ('open' | 'in-progress' | 'completed').
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    private function mapTaskStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'in-progress' => 'in-progress',
            default => 'open',
        };

    }//end mapTaskStatus()

    /**
     * Load all active legacy objects for a schema, or an empty list if the
     * schema was never instantiated.
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $schema        The legacy schema slug.
     * @param IOutput $output        Progress reporting.
     *
     * @return array<int,mixed> The legacy entities (possibly empty).
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    private function loadLegacy(object $objectService, string $schema, IOutput $output): array
    {
        try {
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema($schema);
            return $objectService->findAll(limit: 1000);
        } catch (Throwable $e) {
            $output->info('No legacy '.$schema.' objects found — nothing to migrate for that schema.');
            $this->logger->info(
                'Decidesk: action-item migration found no legacy schema/objects',
                ['schema' => $schema, 'error' => $e->getMessage()]
            );
            return [];
        }

    }//end loadLegacy()

    /**
     * Archive a legacy object via OR's archival workflow.
     *
     * First stamps `_migratedToDeckLeaf` so the step is resume-safe, then
     * soft-deletes it. `deleteObject` is retention-aware — the object is
     * archived, not hard-purged (REQ-AI-DECK-003).
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $schema        The legacy schema slug.
     * @param string              $uuid          The legacy object UUID.
     * @param array<string,mixed> $object        The legacy object.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
     */
    private function archiveLegacy(object $objectService, string $schema, string $uuid, array $object): void
    {
        // Stamp the migration marker first so the step is resume-safe.
        $object[self::MIGRATION_MARKER] = true;
        $objectService->saveObject(
            register: self::REGISTER,
            schema: $schema,
            object: $object,
            uuid: $uuid,
        );

        // Archive via OR archival workflow (soft delete; not a hard purge).
        $objectService->deleteObject(
            uuid: $uuid,
            register: self::REGISTER,
            schema: $schema,
        );

    }//end archiveLegacy()

    /**
     * Normalise an OR find/findAll result into a plain array.
     *
     * @param mixed $entity An ObjectEntity, array, or null.
     *
     * @return array<string,mixed>|null The object array, or null when unusable.
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    private function toArray(mixed $entity): ?array
    {
        if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
            $serialized = $entity->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return null;
        }

        if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
            $object = $entity->getObject();
            if (is_array($object) === true) {
                return $object;
            }

            return null;
        }

        if (is_array($entity) === true) {
            return $entity;
        }

        return null;

    }//end toArray()
}//end class
