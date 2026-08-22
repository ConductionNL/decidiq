<?php

/**
 * Decidiq Migrate Legacy Templates To DecisionTemplate Repair Step
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * unified-decision-templates schema-declaration change (ADR-032 chain link 1
 * of 3). Reads every live `process-template` and `vve-decision-template`
 * object and creates the equivalent `decision-template` object, tagged with
 * a `migratedFrom` provenance marker. Never deletes or edits a source
 * object — OpenRegister seed import is create-only, so an already-created
 * `process-template`/`vve-decision-template` object would otherwise be
 * stranded under a schema no consumer reads once the new fragment's built-in
 * `decision-template` seeds land (design.md Decision 4).
 *
 * Idempotent: re-running the step is a no-op for objects it already
 * migrated, matched by `migratedFrom.sourceSchema`/`migratedFrom.sourceUuid`
 * (migration.md step 2). Resume-safe: a partial run leaves some objects
 * migrated and some not; the next run picks up exactly where it left off.
 *
 * Graceful no-op: instances that never instantiated the legacy schemas (or
 * that have no OpenRegister available) have nothing to migrate; findAll()
 * throwing or returning empty exits cleanly with no error.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 * @spec openspec/changes/unified-decision-templates/migration.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step migrating legacy ProcessTemplate/VveDecisionTemplate objects
 * onto the unified DecisionTemplate schema (non-destructive; sources are
 * never modified or deleted).
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */
class MigrateLegacyTemplatesToDecisionTemplate implements IRepairStep {

	/**
	 * The decidesk register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidesk';

	/**
	 * The legacy process-template schema slug.
	 *
	 * @var string
	 */
	private const SOURCE_PROCESS_TEMPLATE = 'process-template';

	/**
	 * The legacy vve-decision-template schema slug.
	 *
	 * @var string
	 */
	private const SOURCE_VVE_DECISION_TEMPLATE = 'vve-decision-template';

	/**
	 * The unified target schema slug.
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'decision-template';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Detects OpenRegister availability.
	 * @param ContainerInterface $container DI container (lazy-loads OR ObjectService).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
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
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	public function getName(): string {
		return 'Migrate legacy Decidiq ProcessTemplate/VveDecisionTemplate objects to the unified DecisionTemplate schema';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available — skipping DecisionTemplate migration.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService — skipping DecisionTemplate migration.');
			$this->logger->warning(
				'Decidiq: DecisionTemplate migration could not resolve ObjectService',
				['error' => $e->getMessage()]
			);
			return;
		}

		$alreadyMigrated = $this->buildMigratedIndex(objectService: $objectService);

		$migrated = 0;
		$skipped = 0;

		$this->migrateSchema(
			objectService: $objectService,
			sourceSchema: self::SOURCE_PROCESS_TEMPLATE,
			alreadyMigrated: $alreadyMigrated,
			mapper: $this->mapProcessTemplate(...),
			output: $output,
			migrated: $migrated,
			skipped: $skipped,
		);

		$this->migrateSchema(
			objectService: $objectService,
			sourceSchema: self::SOURCE_VVE_DECISION_TEMPLATE,
			alreadyMigrated: $alreadyMigrated,
			mapper: $this->mapVveDecisionTemplate(...),
			output: $output,
			migrated: $migrated,
			skipped: $skipped,
		);

		$output->info(
			'Decidiq DecisionTemplate migration complete: ' . $migrated . ' migrated, ' . $skipped . ' skipped.'
		);

	}//end run()

	/**
	 * Read every existing `decision-template` object and build a lookup of
	 * already-migrated source objects (migration.md step 2, idempotency).
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keys of the form '<sourceSchema>|<sourceUuid>'.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	private function buildMigratedIndex(object $objectService): array {
		$index = [];

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET_SCHEMA);
			$existing = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			// The decision-template schema/seeds have not been imported yet —
			// nothing has been migrated. InitializeSettings runs before this
			// step (info.xml ordering), so this is an unexpected but
			// non-fatal state; the loop below simply migrates everything.
			$this->logger->info(
				'Decidiq: DecisionTemplate migration found no existing decision-template objects yet',
				['error' => $e->getMessage()]
			);
			return $index;
		}

		foreach ($existing as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object === null) {
				continue;
			}

			$migratedFrom = ($object['migratedFrom'] ?? null);
			if (is_array($migratedFrom) === false) {
				continue;
			}

			$sourceSchema = (string)($migratedFrom['sourceSchema'] ?? '');
			$sourceUuid = (string)($migratedFrom['sourceUuid'] ?? '');
			if ($sourceSchema === '' || $sourceUuid === '') {
				continue;
			}

			$index[$sourceSchema . '|' . $sourceUuid] = true;
		}//end foreach

		return $index;
	}//end buildMigratedIndex()

	/**
	 * Migrate every live object of one legacy schema into `decision-template`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $sourceSchema The legacy schema slug being read.
	 * @param array<string,bool> $alreadyMigrated Idempotency index built before this call (migration.md step 2).
	 * @param callable $mapper Maps a source object array to a decision-template payload.
	 * @param IOutput $output Progress reporting.
	 * @param integer $migrated Running count of migrated objects (by-ref accumulator).
	 * @param integer $skipped Running count of skipped objects (by-ref accumulator).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function migrateSchema(
		object $objectService,
		string $sourceSchema,
		array $alreadyMigrated,
		callable $mapper,
		IOutput $output,
		int &$migrated,
		int &$skipped,
	): void {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($sourceSchema);
			$sourceObjects = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			$output->info('No legacy ' . $sourceSchema . ' objects found — nothing to migrate.');
			$this->logger->info(
				'Decidiq: DecisionTemplate migration found no legacy ' . $sourceSchema . ' schema/objects',
				['error' => $e->getMessage()]
			);
			return;
		}

		foreach ($sourceObjects as $entity) {
			$source = $this->toArray(entity: $entity);
			if ($source === null) {
				$skipped++;
				continue;
			}

			$uuid = (string)($source['id'] ?? $source['uuid'] ?? '');
			if ($uuid === '') {
				$skipped++;
				continue;
			}

			if (($alreadyMigrated[$sourceSchema . '|' . $uuid] ?? false) === true) {
				$skipped++;
				continue;
			}

			try {
				$payload = $mapper($source, $uuid);
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema(self::TARGET_SCHEMA);
				$objectService->saveObject(
					register: self::REGISTER,
					schema: self::TARGET_SCHEMA,
					object: $payload,
				);
				$migrated++;
				$this->logger->info(
					'Decidiq: migrated ' . $sourceSchema . ' to decision-template',
					['sourceUuid' => $uuid]
				);
			} catch (Throwable $e) {
				$skipped++;
				$output->warning('Failed to migrate ' . $sourceSchema . ' ' . $uuid . ': ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: DecisionTemplate migration failed for one object',
					['sourceSchema' => $sourceSchema, 'sourceUuid' => $uuid, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach

	}//end migrateSchema()

	/**
	 * Map a legacy `process-template` object to a `decision-template` payload
	 * (migration.md step 3). Every carried-forward field is copied verbatim;
	 * `decisionType`/`templateCategory` are left absent (generic default).
	 *
	 * @param array<string,mixed> $source The legacy process-template object.
	 * @param string $uuid The source object's UUID.
	 *
	 * @return array<string,mixed> The decision-template payload.
	 *
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function mapProcessTemplate(array $source, string $uuid): array {
		$payload = [
			'name' => ($source['name'] ?? ''),
			'description' => ($source['description'] ?? ''),
			'context' => ($source['context'] ?? null),
			'builtIn' => ($source['builtIn'] ?? false),
			'initialState' => ($source['initialState'] ?? null),
			'stateMachine' => ($source['stateMachine'] ?? null),
			'votingRule' => ($source['votingRule'] ?? null),
			'quorumRequired' => ($source['quorumRequired'] ?? null),
			'quorumRule' => ($source['quorumRule'] ?? null),
			'allowDecideWithoutVote' => ($source['allowDecideWithoutVote'] ?? null),
			'checklist' => [],
			'migratedFrom' => [
				'sourceSchema' => self::SOURCE_PROCESS_TEMPLATE,
				'sourceUuid' => $uuid,
			],
		];

		if (isset($source['urgencyPolicy']) === true) {
			$payload['urgencyPolicy'] = $source['urgencyPolicy'];
		}

		return $payload;
	}//end mapProcessTemplate()

	/**
	 * Map a legacy `vve-decision-template` object to a `decision-template`
	 * payload (migration.md step 4): `decisionCategory` -> `templateCategory`,
	 * `defaultVoteThreshold` -> `votingRule.voteThreshold`,
	 * `defaultQuorumFraction` -> `quorumRule`, `context` fixed to
	 * `association`, `decisionType` fixed to `resolution`.
	 *
	 * @param array<string,mixed> $source The legacy vve-decision-template object.
	 * @param string $uuid The source object's UUID.
	 *
	 * @return array<string,mixed> The decision-template payload.
	 *
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function mapVveDecisionTemplate(array $source, string $uuid): array {
		$payload = [
			'name' => ($source['name'] ?? ''),
			'description' => ($source['description'] ?? ''),
			'context' => 'association',
			'decisionType' => 'resolution',
			'templateCategory' => ($source['decisionCategory'] ?? null),
			'builtIn' => ($source['builtIn'] ?? false),
			'proposedText' => ($source['proposedText'] ?? null),
			'regulationSource' => ($source['regulationSource'] ?? null),
			'checklist' => [],
			'migratedFrom' => [
				'sourceSchema' => self::SOURCE_VVE_DECISION_TEMPLATE,
				'sourceUuid' => $uuid,
			],
		];

		if (isset($source['defaultVoteThreshold']) === true) {
			$payload['votingRule'] = ['voteThreshold' => $source['defaultVoteThreshold']];
		}

		// `isset()` is already false for null — the `!== null` this replaces
		// could never be reached as false.
		if (isset($source['defaultQuorumFraction']) === true) {
			$payload['quorumRule'] = $source['defaultQuorumFraction'];
		}

		return $payload;
	}//end mapVveDecisionTemplate()

	/**
	 * Normalise an OR find/findAll result into a plain array.
	 *
	 * @param mixed $entity An ObjectEntity, array, or null.
	 *
	 * @return array<string,mixed>|null The object array, or null when unusable.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	private function toArray(mixed $entity): ?array {
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
