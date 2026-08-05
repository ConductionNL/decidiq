<?php
/**
 * Decidesk Migrate EmailLinks To Registry Repair Step
 *
 * One-shot, idempotent, resume-safe migration of legacy in-app EmailLink
 * objects onto the ADR-019 registry email-object link
 * (migrate-email-links-to-email-leaf, design D4). For each legacy EmailLink:
 *
 *   1. bind the email to its target dossier / agenda-item via the target
 *      object's `relations` map (the registry link), and
 *   2. archive the legacy EmailLink object through OR's archival workflow
 *      (soft delete — retention-aware, never a hard purge), so the audit
 *      record survives.
 *
 * Resume-safe: a legacy object already stamped `_migratedToRegistry` is
 * skipped, and archived objects drop out of the active findAll() set, so a
 * re-run produces no duplicate links and no double-archival.
 *
 * Graceful no-op: instances that never instantiated the `email-link` schema
 * (it was never shipped in the active register set) have nothing to migrate;
 * findAll() returns empty and the step exits cleanly.
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
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Migration;

use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step migrating legacy EmailLink objects onto registry email-object
 * links, then archiving them (no hard delete).
 *
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
 */
class MigrateEmailLinksToRegistry implements IRepairStep
{
    /**
     * The decidesk register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * The legacy email-link schema slug being retired.
     *
     * @var string
     */
    private const LEGACY_SCHEMA = 'email-link';

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Detects OpenRegister availability.
     * @param ContainerInterface $container       DI container (lazy-loads OR ObjectService).
     * @param LoggerInterface    $logger          Logger.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
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
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
     */
    public function getName(): string
    {
        return 'Migrate legacy Decidesk EmailLink objects to registry email-object links';
    }//end getName()

    /**
     * Run the migration.
     *
     * @param IOutput $output Progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.2
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.3
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available — skipping EmailLink migration.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $output->warning('Could not resolve OpenRegister ObjectService — skipping EmailLink migration.');
            $this->logger->warning('Decidesk: EmailLink migration could not resolve ObjectService', ['error' => $e->getMessage()]);
            return;
        }

        try {
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema(self::LEGACY_SCHEMA);
            $legacyLinks = $objectService->findAll(['limit' => 1000]);
        } catch (Throwable $e) {
            // The legacy email-link schema was never instantiated on this
            // instance — nothing to migrate. This is the expected path for
            // installs that adopted the leaf from the start.
            $output->info('No legacy email-link objects found — nothing to migrate.');
            $this->logger->info('Decidesk: EmailLink migration found no legacy schema/objects', ['error' => $e->getMessage()]);
            return;
        }

        $migrated = 0;
        $skipped  = 0;

        foreach ($legacyLinks as $entity) {
            $result = $this->migrateOne(objectService: $objectService, entity: $entity, output: $output);
            if ($result === true) {
                $migrated++;
                continue;
            }

            $skipped++;
        }

        $output->info(
            'Decidesk EmailLink migration complete: '.$migrated.' migrated, '.$skipped.' skipped.'
        );
    }//end run()

    /**
     * Migrate a single legacy EmailLink entity.
     *
     * @param object  $objectService The OR ObjectService.
     * @param mixed   $entity        The legacy EmailLink entity from findAll().
     * @param IOutput $output        Progress reporting.
     *
     * @return bool True when the object was migrated; false when skipped.
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.3
     */
    private function migrateOne(object $objectService, mixed $entity, IOutput $output): bool
    {
        $link = $this->toArray(entity: $entity);
        if ($link === null) {
            return false;
        }

        // Resume-safe: skip anything already migrated.
        if (($link['_migratedToRegistry'] ?? false) === true) {
            return false;
        }

        $uuid   = (string) ($link['id'] ?? $link['uuid'] ?? '');
        $target = (string) ($link['linkedTo'] ?? '');

        if ($uuid === '' || $target === '') {
            // Malformed legacy record — cannot migrate; leave untouched for audit.
            return false;
        }

        try {
            $this->relinkToRegistry(objectService: $objectService, target: $target, link: $link);
            $this->archiveLegacy(objectService: $objectService, uuid: $uuid, link: $link);
            $this->logger->info(
                'Decidesk: migrated EmailLink to registry link',
                ['uuid' => $uuid, 'target' => $target, 'emailUid' => (string) ($link['emailUid'] ?? '')]
            );
            return true;
        } catch (Throwable $e) {
            $output->warning('Failed to migrate EmailLink '.$uuid.': '.$e->getMessage());
            $this->logger->warning(
                'Decidesk: EmailLink migration failed for one object',
                ['uuid' => $uuid, 'error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end migrateOne()

    /**
     * Bind the email to its target object via the registry link (the target
     * object's `relations` map), idempotently.
     *
     * The target reference is `register:schema:uuid`. The email reference is
     * stored under the `Email` relation key the integration registry reads;
     * duplicates are de-duplicated so a re-run adds nothing.
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $target        Target reference 'register:schema:uuid'.
     * @param array<string,mixed> $link          The legacy EmailLink object.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
     */
    private function relinkToRegistry(object $objectService, string $target, array $link): void
    {
        $parts = explode(':', $target);
        if (count($parts) !== 3) {
            return;
        }

        [$register, $schema, $targetUuid] = $parts;

        $targetEntity = $objectService->find(id: $targetUuid, register: $register, schema: $schema);
        if ($targetEntity === null) {
            return;
        }

        $targetObject = $this->toArray(entity: $targetEntity);
        if ($targetObject === null) {
            return;
        }

        $emailRef  = (string) ($link['emailUid'] ?? $link['id'] ?? '');
        $relations = ($targetObject['relations'] ?? []);
        $existing  = ($relations['Email'] ?? []);

        if (in_array($emailRef, $existing, true) === false && $emailRef !== '') {
            $existing[]         = $emailRef;
            $relations['Email'] = array_values(array_unique($existing));
            $targetObject['relations'] = $relations;

            $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $targetObject
            );
        }
    }//end relinkToRegistry()

    /**
     * Archive the legacy EmailLink object via OR's archival workflow.
     *
     * First stamps `_migratedToRegistry` so a re-run skips this object even
     * if archival fails, then soft-deletes it. `deleteObject` is
     * retention-aware (the archival-annotated schema keeps the object
     * readable; it is never hard-purged).
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $uuid          The legacy object UUID.
     * @param array<string,mixed> $link          The legacy EmailLink object.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.2
     */
    private function archiveLegacy(object $objectService, string $uuid, array $link): void
    {
        // Stamp the migration marker first so the step is resume-safe.
        $link['_migratedToRegistry'] = true;
        $objectService->saveObject(
            register: self::REGISTER,
            schema: self::LEGACY_SCHEMA,
            object: $link
        );

        // Archive via OR archival workflow (soft delete; not a hard purge).
        $objectService->deleteObject(
            uuid: $uuid,
            register: self::REGISTER,
            schema: self::LEGACY_SCHEMA
        );
    }//end archiveLegacy()

    /**
     * Normalise an OR find/findAll result into a plain array.
     *
     * @param mixed $entity An ObjectEntity, array, or null.
     *
     * @return array<string,mixed>|null The object array, or null when unusable.
     *
     * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
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
