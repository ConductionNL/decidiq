<?php

/**
 * An in-memory object service for the urgent decision procedure tests.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use JsonSerializable;
use RuntimeException;

/**
 * Keeps objects per schema, answers find/findAll/saveObject like OpenRegister's
 * ObjectService does for these callers, and records every save.
 */
class UrgentProcedureStore {

	/**
	 * The objects, per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $objects = [];

	/**
	 * Every save, in order: [schema, uuid-or-null, object].
	 *
	 * @var list<array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	public array $saves = [];

	/**
	 * Schemas whose save throws.
	 *
	 * @var list<string>
	 */
	public array $failingSaves = [];

	/**
	 * Created ids handed out so far.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * Put an object in the store.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object, with an `id`.
	 *
	 * @return void
	 */
	public function put(string $schema, array $object): void {
		$this->objects[$schema][(string)$object['id']] = $object;
	}//end put()

	/**
	 * Find one object.
	 *
	 * @param string      $id       The id.
	 * @param string|null $register The register.
	 * @param string|null $schema   The schema.
	 *
	 * @return JsonSerializable|null
	 */
	public function find(string $id, ?string $register = null, ?string $schema = null): ?JsonSerializable {
		$row = ($this->objects[(string)$schema][$id] ?? null);
		if ($row === null) {
			return null;
		}

		return self::entity(row: $row);
	}//end find()

	/**
	 * Every object of a schema matching the other filters.
	 *
	 * @param array<string, mixed> $config The query, with `filters`.
	 *
	 * @return list<JsonSerializable>
	 */
	public function findAll(array $config = []): array {
		$filters = (array)($config['filters'] ?? []);
		$schema  = (string)($filters['schema'] ?? '');
		unset($filters['register'], $filters['schema']);
		$found = [];
		foreach (($this->objects[$schema] ?? []) as $row) {
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$found[] = self::entity(row: $row);
		}

		return $found;
	}//end findAll()

	/**
	 * Save (create or replace) an object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param string|null          $register The register.
	 * @param string|null          $schema   The schema.
	 * @param string|null          $uuid     The id to replace, or null to create.
	 *
	 * @return JsonSerializable
	 *
	 * @throws RuntimeException When the schema's saves are set to fail.
	 */
	public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null): JsonSerializable {
		if (in_array((string)$schema, $this->failingSaves, true) === true) {
			throw new RuntimeException('save refused');
		}

		$this->saves[] = [(string)$schema, $uuid, $object];
		$id            = $uuid ?? ((string)$schema . '-new-' . (++$this->next));
		$object['id']  = $id;
		$this->objects[(string)$schema][$id] = $object;
		return self::entity(row: $object);
	}//end saveObject()

	/**
	 * An entity-like wrapper.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return JsonSerializable
	 */
	private static function entity(array $row): JsonSerializable {
		return new class ($row) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $row The object.
			 */
			public function __construct(private readonly array $row) {
			}

			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}

			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end entity()
}//end class
