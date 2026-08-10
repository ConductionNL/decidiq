<?php
/**
 * Decidesk Migrate Comments To Talk Leaf Repair Step
 *
 * One-shot, idempotent, resume-safe migration of legacy in-app Comment
 * objects onto the ADR-019 Talk integration leaf
 * (migrate-comments-to-talk-leaf, design D2). For each legacy Comment:
 *
 *   1. Detect the bound Talk conversation for the target artifact via NC Talk's
 *      room-manager (if Talk is installed). If no conversation exists for the
 *      object, one is created as an "object conversation" bound to the OR object.
 *   2. Replay the comment into the Talk conversation as a chat message.
 *      Author + original timestamp are prepended in the message body (design D3
 *      fallback: Talk conversations are flat; threading degrades to quotes).
 *   3. Set each Comment to an archived state via OR's archival workflow
 *      (no hard delete — audit trail retained).
 *
 * Resume-safe: comments already stamped `_migratedToTalkLeaf` are skipped;
 * archived comments drop out of the active findAll() set, so a re-run
 * produces no duplicate messages and no double-archival.
 *
 * Graceful no-op: instances without Comment objects or without the Talk app
 * installed exit cleanly — no error is raised.
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
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.2
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.3
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Migration;

use DateTimeImmutable;
use OCA\Decidesk\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step migrating legacy Comment objects onto the Talk integration leaf,
 * then archiving them (no hard delete — audit trail retained).
 *
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
 */
class MigrateCommentsToTalkLeaf implements IRepairStep
{

    /**
     * The decidesk register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * The legacy comment schema slug being retired.
     *
     * @var string
     */
    private const LEGACY_SCHEMA = 'comment';

    /**
     * Object type sent to Talk when binding conversations to OR objects.
     * This is the type string Talk's "object conversation" feature uses for
     * OpenRegister-backed objects (ADR-019 talk leaf convention).
     *
     * @var string
     */
    private const TALK_OBJECT_TYPE = 'openregister_object';

    /**
     * Migration marker stamped on each Comment before archival.
     * Presence of this key causes a re-run to skip the object.
     *
     * @var string
     */
    private const MIGRATION_MARKER = '_migratedToTalkLeaf';

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Detects OpenRegister availability.
     * @param ContainerInterface $container       DI container (lazy-loads OR + Talk services).
     * @param LoggerInterface    $logger          Logger.
     * @param IAppManager        $appManager      Checks whether the Talk app is installed.
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
     */
    public function getName(): string
    {
        return 'Migrate legacy Decidesk Comment objects to the Talk integration leaf';

    }//end getName()

    /**
     * Run the migration.
     *
     * @param IOutput $output Progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.2
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.3
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available — skipping Comment migration.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $output->warning('Could not resolve OpenRegister ObjectService — skipping Comment migration.');
            $this->logger->warning(
                'Decidesk: Comment migration could not resolve ObjectService',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $talkManager     = $this->resolveTalkManager();
        $talkChatManager = $this->resolveTalkChatManager();

        if ($talkManager === null || $talkChatManager === null) {
            $output->info(
                'Talk app not available — Comment objects will be archived without Talk replay. '
                .'Install Talk and re-run if message import is required.'
            );
        }

        try {
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema(self::LEGACY_SCHEMA);
            // ObjectService::findAll() takes a single $config array — the
            // named-argument form (limit:) threw "Unknown named parameter" and
            // was swallowed by the catch below, so the migration always reported
            // "nothing to migrate". Register/schema context is set above.
            $legacyComments = $objectService->findAll(['limit' => 1000]);
        } catch (Throwable $e) {
            $output->info('No legacy Comment objects found — nothing to migrate.');
            $this->logger->info(
                'Decidesk: Comment migration found no legacy comment schema/objects',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $migrated = 0;
        $skipped  = 0;

        foreach ($legacyComments as $entity) {
            $result = $this->migrateOne(
                objectService: $objectService,
                talkManager: $talkManager,
                talkChatManager: $talkChatManager,
                entity: $entity,
                output: $output,
            );
            if ($result === true) {
                $migrated++;
                continue;
            }

            $skipped++;
        }//end foreach

        $output->info(
            'Decidesk Comment migration complete: '.$migrated.' migrated, '.$skipped.' skipped.'
        );

    }//end run()

    /**
     * Migrate a single legacy Comment entity.
     *
     * @param object      $objectService   The OR ObjectService.
     * @param object|null $talkManager     NC Talk Manager (null when Talk absent).
     * @param object|null $talkChatManager NC Talk ChatManager (null when Talk absent).
     * @param mixed       $entity          The legacy Comment entity from findAll().
     * @param IOutput     $output          Progress reporting.
     *
     * @return bool True when the object was migrated; false when skipped.
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
     */
    private function migrateOne(
        object $objectService,
        ?object $talkManager,
        ?object $talkChatManager,
        mixed $entity,
        IOutput $output,
    ): bool {
        $comment = $this->toArray(entity: $entity);
        if ($comment === null) {
            return false;
        }

        // Resume-safe: skip anything already migrated (task-2.4).
        if (($comment[self::MIGRATION_MARKER] ?? false) === true) {
            return false;
        }

        $uuid   = (string) ($comment['id'] ?? $comment['uuid'] ?? '');
        $target = (string) ($comment['target'] ?? '');

        if ($uuid === '') {
            return false;
        }

        try {
            if ($talkManager !== null && $talkChatManager !== null && $target !== '') {
                $this->replayIntoTalk(
                    talkManager: $talkManager,
                    talkChatManager: $talkChatManager,
                    comment: $comment,
                    target: $target,
                );
            }

            $this->archiveLegacy(
                objectService: $objectService,
                uuid: $uuid,
                comment: $comment,
            );

            $this->logger->info(
                'Decidesk: migrated Comment to Talk leaf',
                ['uuid' => $uuid, 'target' => $target]
            );
            return true;
        } catch (Throwable $e) {
            $output->warning('Failed to migrate Comment '.$uuid.': '.$e->getMessage());
            $this->logger->warning(
                'Decidesk: Comment migration failed for one object',
                ['uuid' => $uuid, 'error' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end migrateOne()

    /**
     * Replay a comment into the Talk conversation bound to the target artifact.
     *
     * Ensures the conversation exists (creating an object conversation if needed),
     * then posts the comment text with author + timestamp prefix so the audit
     * record is preserved in the Talk chat log (design D3).
     *
     * @param object              $talkManager     NC Talk Manager.
     * @param object              $talkChatManager NC Talk ChatManager.
     * @param array<string,mixed> $comment         The legacy Comment object.
     * @param string              $target          Target ref 'register:schema:uuid'.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.2
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.3
     */
    private function replayIntoTalk(
        object $talkManager,
        object $talkChatManager,
        array $comment,
        string $target,
    ): void {
        $parts = explode(':', $target);
        if (count($parts) !== 3) {
            return;
        }

        [, , $artifactUuid] = $parts;

        // Get or create the Talk object conversation bound to this OR object.
        $room = $this->getOrCreateTalkRoom(
            talkManager: $talkManager,
            objectId: $artifactUuid,
        );

        if ($room === null) {
            return;
        }

        // Build the message body with author + timestamp prefix (design D3 fallback).
        $author    = (string) ($comment['author'] ?? 'unknown');
        $timestamp = (string) ($comment['createdAt'] ?? '');
        $text      = (string) ($comment['text'] ?? '');

        $messageBody = '[Migrated from decidesk — author: '.$author;
        if ($timestamp !== '') {
            $messageBody .= ' | original timestamp: '.$timestamp;
        }

        $messageBody .= ']'."\n\n".$text;

        // Truncate to Talk's 32000 char limit.
        if (mb_strlen($messageBody) > 32000) {
            $messageBody = mb_substr($messageBody, 0, 31997).'...';
        }

        // Post as a system message in the admin actor context.
        $creationDateStr = 'now';
        if ($timestamp !== '') {
            $creationDateStr = $timestamp;
        }

        $talkChatManager->addSystemMessage(
            chat: $room,
            actorType: 'guests',
            actorId: 'decidesk-migration',
            message: $messageBody,
            creationDateTime: new DateTimeImmutable($creationDateStr),
        );

    }//end replayIntoTalk()

    /**
     * Get the Talk object conversation for this OR object, creating it if absent.
     *
     * Uses Talk's `getRoomForObject` (if available) and falls back to
     * `createConversationFromObject` on first access. Wrapped in a broad
     * try/catch so a Talk API change does not abort the whole migration.
     *
     * @param object $talkManager NC Talk Manager.
     * @param string $objectId    The OR object UUID used as Talk's objectId.
     *
     * @return object|null The Talk Room, or null when unavailable.
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
     */
    private function getOrCreateTalkRoom(object $talkManager, string $objectId): ?object
    {
        try {
            // Try to find an existing object conversation for this artifact.
            if (method_exists($talkManager, 'getRoomForObject') === true) {
                $room = $talkManager->getRoomForObject(
                    objectType: self::TALK_OBJECT_TYPE,
                    objectId: $objectId,
                );
                if ($room !== null) {
                    return $room;
                }
            }

            // No existing room — create one bound to the OR object.
            if (method_exists($talkManager, 'createConversationFromObject') === true) {
                // Room::TYPE_PUBLIC = 3 in Talk's constants; use literal to avoid
                // hard dependency on Talk internals at parse time.
                return $talkManager->createConversationFromObject(
                    type: 3,
                    name: 'decidesk:discussion:'.$objectId,
                    objectType: self::TALK_OBJECT_TYPE,
                    objectId: $objectId,
                );
            }

            return null;
        } catch (Throwable) {
            return null;
        }//end try

    }//end getOrCreateTalkRoom()

    /**
     * Archive the legacy Comment object via OR's archival workflow.
     *
     * First stamps `_migratedToTalkLeaf` so the step is resume-safe, then
     * soft-deletes it. `deleteObject` is retention-aware — the object is
     * archived, not hard-purged.
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $uuid          The legacy object UUID.
     * @param array<string,mixed> $comment       The legacy Comment object.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.3
     */
    private function archiveLegacy(object $objectService, string $uuid, array $comment): void
    {
        // Stamp the migration marker first so the step is resume-safe.
        $comment[self::MIGRATION_MARKER] = true;
        $objectService->saveObject(
            register: self::REGISTER,
            schema: self::LEGACY_SCHEMA,
            object: $comment,
        );

        // Archive via OR archival workflow (soft delete; not a hard purge).
        $objectService->deleteObject(
            uuid: $uuid,
            register: self::REGISTER,
            schema: self::LEGACY_SCHEMA,
        );

    }//end archiveLegacy()

    /**
     * Lazy-load the Talk Manager, returning null when Talk is not installed.
     *
     * @return object|null
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.4
     */
    private function resolveTalkManager(): ?object
    {
        if ($this->appManager->isEnabledForUser('spreed') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\Talk\Manager');
        } catch (Throwable) {
            return null;
        }

    }//end resolveTalkManager()

    /**
     * Lazy-load the Talk ChatManager, returning null when Talk is not installed.
     *
     * @return object|null
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-1.4
     */
    private function resolveTalkChatManager(): ?object
    {
        if ($this->appManager->isEnabledForUser('spreed') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\Talk\Chat\ChatManager');
        } catch (Throwable) {
            return null;
        }

    }//end resolveTalkChatManager()

    /**
     * Normalise an OR find/findAll result into a plain array.
     *
     * @param mixed $entity An ObjectEntity, array, or null.
     *
     * @return array<string,mixed>|null The object array, or null when unusable.
     *
     * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
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
