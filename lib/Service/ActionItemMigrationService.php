<?php

/**
 * Decidiq Action Item Migration Service
 *
 * Projects legacy app-local action-item data (`task`, `delegation` and
 * pre-projection `ActionItem` objects) onto action-item VTODOs in the owning
 * user's calendar. Runs inside that user's context, which the caller sets.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ActionItemWriterInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One-time, idempotent forward migration of legacy action-item objects.
 *
 * Per legacy object: create the VTODO, then stamp the marker, then soft-archive.
 * A failed VTODO write leaves the legacy object untouched for the next run; a
 * marked object is skipped; a VTODO that already carries the legacy uuid is
 * reused, so a run interrupted between create and mark creates no duplicate.
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 */
class ActionItemMigrationService {

	/**
	 * The register the legacy objects live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'decidiq';

	/**
	 * Legacy schemas whose objects become action items.
	 *
	 * @var list<string>
	 */
	public const ITEM_SCHEMAS = ['task', 'ActionItem'];

	/**
	 * The legacy delegation schema.
	 *
	 * @var string
	 */
	public const DELEGATION_SCHEMA = 'delegation';

	/**
	 * The marker stamped on a migrated legacy object; its value is the VTODO uid.
	 *
	 * @var string
	 */
	public const MARKER = '_migratedToVtodo';

	/**
	 * The VTODO field holding the legacy object's uuid.
	 *
	 * @var string
	 */
	public const SOURCE_FIELD = '_migratedFromLegacyUuid';

	/**
	 * Delegation states in which the item is back with the delegator.
	 *
	 * @var list<string>
	 */
	private const RECLAIMED = ['revoked', 'expired', 'reclaimed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface    $objectService The OpenRegister object service.
	 * @param ActionItemWriterInterface $writer        The VTODO write path, acting as the current user.
	 * @param LoggerInterface           $logger        The logger.
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ActionItemWriterInterface $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The users who own legacy objects not migrated yet.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	public function pendingOwners(): array {
		$owners = [];
		foreach (array_merge(self::ITEM_SCHEMAS, [self::DELEGATION_SCHEMA]) as $schema) {
			foreach ($this->pending(schema: $schema) as $object) {
				if ($object['owner'] !== '') {
					$owners[$object['owner']] = true;
				}
			}
		}

		return array_keys($owners);
	}//end pendingOwners()

	/**
	 * Migrate one user's legacy objects; the caller has made that user the acting user.
	 *
	 * @param string $userId The owning user.
	 *
	 * @return array{migrated: int, delegations: int, skipped: int, failed: int}
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-012-delegation-folds-onto-the-vtodo-assignee
	 */
	public function migrateUser(string $userId): array {
		$counts = ['migrated' => 0, 'delegations' => 0, 'skipped' => 0, 'failed' => 0];
		foreach (self::ITEM_SCHEMAS as $schema) {
			foreach ($this->pending(schema: $schema) as $object) {
				if ($object['owner'] !== $userId) {
					continue;
				}

				$outcome = $this->migrateItem(schema: $schema, object: $object);
				$counts[$outcome]++;
			}
		}

		foreach ($this->pending(schema: self::DELEGATION_SCHEMA) as $object) {
			if ($object['owner'] !== $userId) {
				continue;
			}

			$outcome = $this->migrateDelegation(object: $object);
			if ($outcome === 'migrated') {
				$outcome = 'delegations';
			}

			$counts[$outcome]++;
		}

		$this->logger->info('Decidiq: action-item migration for a user finished', ['user' => $userId] + $counts);
		return $counts;
	}//end migrateUser()

	/**
	 * The action item a legacy task or ActionItem becomes.
	 *
	 * @param array<string, mixed> $data The legacy object's data.
	 * @param string               $uuid The legacy object's uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
	 */
	public static function toActionItem(array $data, string $uuid): array {
		$item = [
			'title' => (string)($data['title'] ?? 'Untitled action item'),
			'taskStatus' => self::mapStatus(status: (string)($data['taskStatus'] ?? $data['status'] ?? 'open')),
			self::SOURCE_FIELD => $uuid,
		];
		foreach (['assignee', 'dueDate', 'description', 'meeting', 'agendaItem', 'decision'] as $key) {
			if (is_string($data[$key] ?? null) === true && $data[$key] !== '') {
				$item[$key] = $data[$key];
			}
		}

		return $item;
	}//end toActionItem()

	/**
	 * The assignee a delegation leaves the item with: the substitute while it runs, the delegator after.
	 *
	 * @param array<string, mixed> $delegation The legacy delegation.
	 *
	 * @return string The assignee, or '' when the delegation names none.
	 *
	 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-012-delegation-folds-onto-the-vtodo-assignee
	 */
	public static function effectiveAssignee(array $delegation): string {
		$status = (string)($delegation['status'] ?? $delegation['delegationStatus'] ?? 'active');
		if (in_array($status, self::RECLAIMED, true) === true) {
			return (string)($delegation['delegator'] ?? '');
		}

		return (string)($delegation['substitute'] ?? $delegation['delegate'] ?? '');
	}//end effectiveAssignee()

	/**
	 * Map a legacy status onto the action-item vocabulary.
	 *
	 * @param string $status The legacy status.
	 *
	 * @return string `open`, `in-progress`, `completed` or `cancelled`.
	 */
	private static function mapStatus(string $status): string {
		return match (strtolower($status)) {
			'completed', 'done' => 'completed',
			'in-progress', 'in_progress' => 'in-progress',
			'cancelled' => 'cancelled',
			default => 'open',
		};
	}//end mapStatus()

	/**
	 * Migrate one legacy task or ActionItem.
	 *
	 * @param string                                                     $schema The legacy schema.
	 * @param array{uuid: string, owner: string, data: array<string, mixed>} $object The legacy object.
	 *
	 * @return string `migrated` or `failed`.
	 */
	private function migrateItem(string $schema, array $object): string {
		try {
			$vtodo = $this->writer->findByField(field: self::SOURCE_FIELD, value: $object['uuid']);
			if ($vtodo === null) {
				$vtodo = $this->writer->create(item: self::toActionItem(data: $object['data'], uuid: $object['uuid']));
			}
		} catch (Throwable $e) {
			$vtodo = null;
			$this->logger->warning('Decidiq: a legacy action item could not be projected', ['uuid' => $object['uuid'], 'error' => $e->getMessage()]);
		}

		if ($vtodo === null) {
			// Left as it is, unmarked, for the next run.
			return 'failed';
		}

		return $this->archive(schema: $schema, object: $object, vtodoUid: (string)($vtodo['uid'] ?? $vtodo['id'] ?? ''));
	}//end migrateItem()

	/**
	 * Fold one legacy delegation onto its target item's VTODO.
	 *
	 * @param array{uuid: string, owner: string, data: array<string, mixed>} $object The legacy delegation.
	 *
	 * @return string `migrated`, `skipped` (nothing to apply yet) or `failed`.
	 */
	private function migrateDelegation(array $object): string {
		$data     = $object['data'];
		$target   = (string)($data['task'] ?? $data['actionItem'] ?? '');
		$assignee = self::effectiveAssignee(delegation: $data);
		if ($target === '' || $assignee === '') {
			return 'skipped';
		}

		try {
			$vtodo = $this->writer->findByField(field: self::SOURCE_FIELD, value: $target);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the item of a legacy delegation could not be read', ['uuid' => $object['uuid'], 'error' => $e->getMessage()]);
			return 'failed';
		}

		if ($vtodo === null) {
			// The target is not projected (yet); the delegation stays for a later run.
			return 'skipped';
		}

		$uid     = (string)($vtodo['uid'] ?? $vtodo['id'] ?? '');
		$note    = sprintf('Delegated to %s (migrated from legacy delegation %s)', $assignee, $object['uuid']);
		$updated = $this->writer->update(uid: $uid, changes: ['assignee' => $assignee, 'delegationNote' => $note]);
		if ($updated === null) {
			return 'failed';
		}

		return $this->archive(schema: self::DELEGATION_SCHEMA, object: $object, vtodoUid: $uid);
	}//end migrateDelegation()

	/**
	 * Stamp the marker on a legacy object, then soft-archive it.
	 *
	 * @param string                                                     $schema   The legacy schema.
	 * @param array{uuid: string, owner: string, data: array<string, mixed>} $object   The legacy object.
	 * @param string                                                     $vtodoUid The VTODO it went into.
	 *
	 * @return string `migrated`, or `failed` when the legacy object could not be marked.
	 */
	private function archive(string $schema, array $object, string $vtodoUid): string {
		$data = $object['data'];
		$data[self::MARKER] = $vtodoUid;
		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: $schema,
				uuid: $object['uuid'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// The VTODO carries the legacy uuid, so the next run reuses it instead of creating another.
			$this->logger->warning('Decidiq: a migrated legacy object could not be marked', ['uuid' => $object['uuid'], 'error' => $e->getMessage()]);
			return 'failed';
		}

		try {
			// Not permanent: OpenRegister archives the object, recoverable, instead of purging it.
			$this->objectService->deleteObject(
				uuid: $object['uuid'],
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				permanent: false
			);
		} catch (Throwable $e) {
			// Marked, so it is skipped from now on; archiving can be retried by hand.
			$this->logger->warning('Decidiq: a migrated legacy object could not be archived', ['uuid' => $object['uuid'], 'error' => $e->getMessage()]);
		}

		return 'migrated';
	}//end archive()

	/**
	 * The legacy objects of a schema that carry no marker yet.
	 *
	 * A schema that no longer exists has no legacy objects.
	 *
	 * @param string $schema The legacy schema.
	 *
	 * @return list<array{uuid: string, owner: string, data: array<string, mixed>}>
	 */
	private function pending(string $schema): array {
		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq: no legacy objects to migrate in a schema', ['schema' => $schema, 'error' => $e->getMessage()]);
			return [];
		}

		$pending = [];
		foreach ((array)($rows['results'] ?? $rows) as $row) {
			$object = $this->legacyObject(row: $row);
			if ($object !== null && ($object['data'][self::MARKER] ?? null) === null) {
				$pending[] = $object;
			}
		}

		return $pending;
	}//end pending()

	/**
	 * A row as uuid, owner and data, or null when it is not an object with a uuid.
	 *
	 * @param mixed $row An object entity or its data.
	 *
	 * @return array{uuid: string, owner: string, data: array<string, mixed>}|null
	 */
	private function legacyObject(mixed $row): ?array {
		if ($row instanceof ObjectEntityInterface) {
			$data  = $row->getObject();
			$uuid  = (string)$row->getUuid();
			$owner = (string)$row->getOwner();
		} else if (is_array($row) === true) {
			$data  = $row;
			$uuid  = (string)($row['id'] ?? $row['uuid'] ?? $row['@self']['id'] ?? '');
			$owner = (string)($row['@self']['owner'] ?? $row['owner'] ?? '');
		} else {
			return null;
		}

		if ($uuid === '') {
			return null;
		}

		return ['uuid' => $uuid, 'owner' => $owner, 'data' => $data];
	}//end legacyObject()
}//end class
