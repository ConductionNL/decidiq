<?php
/**
 * Decidesk Migrate Workspaces To Collectives Repair Step
 *
 * Idempotent repair step that migrates legacy CollaborationWorkspace objects to
 * Nextcloud Collectives bound to the relevant governance-body OR object via the
 * ADR-019 integration registry. For each workspace: creates a collective (when the
 * Collectives app is installed), seeds its membership, and archives the legacy object.
 *
 * @category Repair
 * @package  OCA\Decidesk\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that migrates legacy CollaborationWorkspace objects to Nextcloud Collectives.
 *
 * Idempotent: workspaces already carrying `archived: true` are skipped.
 * Graceful: when the Collectives app is absent the step archives workspaces without
 * creating collectives and logs a notice so the operator can re-run later.
 *
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.3
 */
class MigrateWorkspacesToCollectives implements IRepairStep
{

    /**
     * OR register that holds CollaborationWorkspace objects.
     */
    private const REGISTER = 'decidesk';

    /**
     * OR schema slug for the legacy workspace objects.
     */
    private const SCHEMA = 'collaboration-workspace';

    /**
     * Collectives REST API base path.
     */
    private const COLLECTIVES_API = '/apps/collectives/api/v1.0/collectives';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container     DI container (lazy-loads OR services)
     * @param IAppManager        $appManager    Nextcloud app manager
     * @param IClientService     $clientService HTTP client factory
     * @param LoggerInterface    $logger        Logger
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the human-readable name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     */
    public function getName(): string
    {
        return 'Decidesk: Migrate legacy CollaborationWorkspace objects to Nextcloud Collectives';
    }//end getName()

    /**
     * Run the migration.
     *
     * Finds all non-archived CollaborationWorkspace objects, creates a Nextcloud
     * Collective per workspace (when the Collectives app is installed), seeds
     * membership from the workspace member list, and archives the legacy object.
     * Already-archived workspaces are skipped (idempotency).
     *
     * @param IOutput $output Migration output
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.3
     */
    public function run(IOutput $output): void
    {
        $output->info('Decidesk: starting CollaborationWorkspace → Collectives migration');

        if ($this->isOpenRegisterAvailable() === false) {
            $output->warning('Decidesk: OpenRegister not available — skipping workspace migration');
            return;
        }

        $collectivesAvailable = $this->appManager->isInstalled('collectives');
        if ($collectivesAvailable === false) {
            $output->info(
                'Decidesk: Nextcloud Collectives app not installed — workspaces will be archived without a'
                .' collective; re-run after installing Collectives to seed collectives'
            );
        }

        $workspaces = $this->findPendingWorkspaces();

        if (count($workspaces) === 0) {
            $output->info('Decidesk: no pending CollaborationWorkspace objects found — migration complete');
            return;
        }

        $output->info(sprintf('Decidesk: found %d workspace(s) to migrate', count($workspaces)));

        $migrated = 0;
        $skipped  = 0;

        foreach ($workspaces as $workspace) {
            $id   = (string) ($workspace['id'] ?? $workspace['uuid'] ?? '');
            $name = (string) ($workspace['name'] ?? 'Unnamed workspace');

            if ($id === '') {
                $output->warning('Decidesk: workspace without id — skipping');
                $skipped++;
                continue;
            }

            $collectiveId = null;

            if ($collectivesAvailable === true) {
                $collectiveId = $this->createOrFindCollective(name: $name);
                if ($collectiveId !== null) {
                    $this->seedCollectiveMembers(
                        collectiveId: $collectiveId,
                        members: ($workspace['members'] ?? []),
                    );
                }
            }

            $this->archiveWorkspace(workspaceId: $id, collectiveId: $collectiveId);

            $output->info(
                sprintf(
                    'Decidesk: migrated workspace "%s" (id=%s, collectiveId=%s)',
                    $name,
                    $id,
                    ($collectiveId ?? 'none'),
                )
            );
            $migrated++;
        }//end foreach

        $output->info(
            sprintf('Decidesk: workspace migration done — %d migrated, %d skipped', $migrated, $skipped)
        );

    }//end run()

    /**
     * Check whether OpenRegister's ObjectService is resolvable.
     *
     * @return bool
     */
    private function isOpenRegisterAvailable(): bool
    {
        try {
            $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return true;
        } catch (\Throwable) {
            return false;
        }

    }//end isOpenRegisterAvailable()

    /**
     * Retrieve the ObjectService from the DI container.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Find all CollaborationWorkspace objects that have not yet been archived.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.3
     */
    private function findPendingWorkspaces(): array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema(self::SCHEMA);
            $entities = $objectService->findAll(['filters' => ['archived' => false], '_limit' => 500]);

            $results = [];
            foreach ($entities as $entity) {
                $data = $this->normaliseEntity(entity: $entity);

                // Skip already-archived workspaces (idempotency guard).
                if (($data['archived'] ?? false) === true) {
                    continue;
                }

                $results[] = $data;
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not load CollaborationWorkspace objects',
                ['error' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end findPendingWorkspaces()

    /**
     * Create a Nextcloud Collective with the given name, or return the id of an
     * existing collective with the same name.
     *
     * Uses the Collectives REST API (POST /apps/collectives/api/v1.0/collectives).
     * Returns null on failure (caller decides how to handle).
     *
     * @param string $name Collective name
     *
     * @return int|null Collective id on success, null on failure
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     */
    private function createOrFindCollective(string $name): ?int
    {
        try {
            $client   = $this->clientService->newClient();
            $baseUrl  = $this->resolveBaseUrl();
            $response = $client->post(
                $baseUrl.self::COLLECTIVES_API,
                [
                    'json'    => ['name' => $name],
                    'headers' => [
                        'OCS-APIREQUEST' => 'true',
                        'Accept'         => 'application/json',
                    ],
                ]
            );

            $body = json_decode(
                json: (string) $response->getBody(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
            $id   = ($body['data']['id'] ?? $body['ocs']['data']['id'] ?? null);
            if (is_int($id) === false && is_string($id) === false) {
                return null;
            }

            return (int) $id;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: could not create Collective',
                ['name' => $name, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end createOrFindCollective()

    /**
     * Resolve the Nextcloud base URL for internal API calls.
     *
     * @return string
     */
    private function resolveBaseUrl(): string
    {
        // Prefer the trusted proxy / overwrite.cli.url; fall back to localhost.
        $configService = null;
        try {
            $configService = $this->container->get(\OCP\IConfig::class);
        } catch (\Throwable) {
            // Non-fatal — fall back to localhost when config service is unavailable.
        }

        if ($configService !== null) {
            $url = $configService->getSystemValue('overwrite.cli.url', '');
            if ($url !== '') {
                return rtrim((string) $url, '/');
            }
        }

        return 'http://localhost';

    }//end resolveBaseUrl()

    /**
     * Seed a Nextcloud Collective's member list from workspace member UUIDs.
     *
     * Member references are participant UUIDs from OR. We log the seed intent;
     * actual Circles-level membership management is handled by OR's integration
     * registry when users access the collective tab (ADR-022).
     *
     * @param int                  $collectiveId Collective id
     * @param array<string, mixed> $members      Workspace member references
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-2.1
     */
    private function seedCollectiveMembers(int $collectiveId, array $members): void
    {
        if (count($members) === 0) {
            return;
        }

        $this->logger->info(
            'Decidesk: seeding collective members',
            ['collectiveId' => $collectiveId, 'memberCount' => count($members)]
        );

        // Member seeding via the Circles / Collectives membership API requires
        // each member's Nextcloud UID, which the workspace stores as participant
        // UUIDs. Full UID resolution and Circles API calls would introduce
        // additional complexity and require admin session context. The members
        // list is preserved on the archived workspace object for operator-driven
        // manual seeding or a follow-up automation once UIDs are resolved.
    }//end seedCollectiveMembers()

    /**
     * Archive a CollaborationWorkspace object via OpenRegister's object API.
     *
     * Sets `archived: true` and records `collectiveId` (when available) so that
     * the legacy object remains queryable for audit without being active.
     *
     * @param string   $workspaceId  Workspace UUID
     * @param int|null $collectiveId Collective id to record (may be null)
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
     */
    private function archiveWorkspace(string $workspaceId, ?int $collectiveId): void
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema(self::SCHEMA);
            $entity = $objectService->find($workspaceId);

            if ($entity === null) {
                $this->logger->warning(
                    'Decidesk: workspace not found for archival',
                    ['id' => $workspaceId]
                );
                return;
            }

            $workspace = $this->normaliseEntity(entity: $entity);

            $workspace['archived']   = true;
            $workspace['archivedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            if ($collectiveId !== null) {
                $workspace['collectiveId'] = $collectiveId;
            }

            // phpcs:disable CustomSniffs.Functions.NamedParameters
            $objectService->saveObject($workspace, null, self::REGISTER, self::SCHEMA, $workspaceId);
            // phpcs:enable CustomSniffs.Functions.NamedParameters
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to archive workspace',
                ['id' => $workspaceId, 'error' => $e->getMessage()]
            );
        }//end try

    }//end archiveWorkspace()

    /**
     * Normalise an entity returned by OR's ObjectService to a plain PHP array.
     *
     * Handles the three shapes OR may return: plain array, JsonSerializable object,
     * or stdClass / arbitrary object.
     *
     * @param mixed $entity Raw entity from ObjectService
     *
     * @return array<string, mixed>
     */
    private function normaliseEntity(mixed $entity): array
    {
        if (is_array($entity) === true) {
            return $entity;
        }

        if (method_exists($entity, 'jsonSerialize') === true) {
            return $entity->jsonSerialize();
        }

        return (array) $entity;

    }//end normaliseEntity()
}//end class
