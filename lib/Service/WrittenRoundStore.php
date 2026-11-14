<?php

/**
 * Reads and writes the objects of a written voting round.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The OpenRegister reads and writes of a written round, as plain arrays.
 *
 * Reads run in system context: who may open, answer or read a round is decided
 * by the opener and the answerer before they call this store, never here.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */
class WrittenRoundStore {

	/**
	 * The register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'decidiq';

	/**
	 * How many objects are read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * One object as an array, or null when it does not exist.
	 *
	 * @param string $id     The object id, uuid or slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
	 */
	public function find(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);

		return self::arrayOf(row: $entity);
	}//end find()

	/**
	 * Every object of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
	 */
	public function findAll(string $schema, array $filters): array {
		$rows   = [];
		$offset = 0;
		do {
			$found = $this->objectService->findAll(
				config: [
					'filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters),
					'limit' => self::PAGE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			$page  = (array)($found['results'] ?? $found);
			foreach ($page as $row) {
				$array = self::arrayOf(row: $row);
				if ($array !== null && self::matches(row: $array, filters: $filters) === true) {
					$rows[] = $array;
				}
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);

		return $rows;
	}//end findAll()

	/**
	 * Save an object and return it as stored.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
	 */
	public function save(string $schema, array $object): array {
		$saved = $this->objectService->saveObject(object: $object, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);

		return (self::arrayOf(row: $saved) ?? $object);
	}//end save()

	/**
	 * The id a reference holds: a uuid string or an expanded object.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
	 */
	public static function idOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? null)));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end idOf()

	/**
	 * A found row as an array, with its id; null when it is none.
	 *
	 * @param mixed $row An entity, an array or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function arrayOf(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		if (isset($row['id']) === false) {
			$id = self::idOf(value: ($row['@self'] ?? null));
			if ($id !== null) {
				$row['id'] = $id;
			}
		}

		return $row;
	}//end arrayOf()

	/**
	 * Whether a row carries every filter value (the store does not trust the backend filter alone).
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private static function matches(array $row, array $filters): bool {
		foreach ($filters as $key => $expected) {
			$actual = $row[$key] ?? null;
			if (is_array($actual) === true) {
				$actual = self::idOf(value: $actual);
			}

			if ($actual !== $expected) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
