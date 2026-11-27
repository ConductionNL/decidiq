<?php

/**
 * Unit tests for AuthorityDelegationRows.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuthorityDelegationRows;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Delegations are read from authority-delegation, and a pre-rename id finds its copy.
 *
 * @covers \OCA\Decidiq\Service\AuthorityDelegationRows
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
class AuthorityDelegationRowsTest extends TestCase {

	/**
	 * A store holding the given authority-delegation rows, recording every schema it is asked for.
	 *
	 * @param array<int, array<string, mixed>> $rows    The stored rows.
	 * @param array<int, string>               $schemas Collects the schemas read.
	 *
	 * @return RegisterObjectStore
	 */
	private function store(array $rows, array &$schemas): RegisterObjectStore {
		$store = $this->createMock(originalClassName: RegisterObjectStore::class);
		$store->method('find')->willReturnCallback(
			static function (string $schema, string $uuid) use ($rows, &$schemas): ?array {
				$schemas[] = $schema;
				foreach ($rows as $row) {
					if ($row['id'] === $uuid) {
						return $row;
					}
				}

				return null;
			}
		);
		$store->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use ($rows, &$schemas): array {
				$schemas[] = $schema;
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		return $store;
	}//end store()

	/**
	 * A delegation is found by its own id on authority-delegation.
	 *
	 * @return void
	 */
	public function testADelegationIsFoundByItsOwnId(): void {
		$schemas = [];
		$rows    = new AuthorityDelegationRows(store: $this->store(rows: [['id' => 'ad-1', 'status' => 'effective']], schemas: $schemas));

		self::assertSame(expected: 'ad-1', actual: ($rows->find(uuid: 'ad-1')['id'] ?? null));
		self::assertSame(expected: ['authority-delegation'], actual: array_values(array_unique($schemas)));
	}//end testADelegationIsFoundByItsOwnId()

	/**
	 * A reference written before the rename finds the copy that records it, never the retired row.
	 *
	 * @return void
	 */
	public function testAPreRenameIdFindsItsCopy(): void {
		$schemas = [];
		$rows    = new AuthorityDelegationRows(
			store: $this->store(rows: [['id' => 'ad-1', 'migratedFromObject' => 'bt-1', 'status' => 'withdrawn']], schemas: $schemas)
		);

		$found = $rows->find(uuid: 'bt-1');

		self::assertSame(expected: 'ad-1', actual: ($found['id'] ?? null));
		self::assertSame(expected: 'withdrawn', actual: ($found['status'] ?? null));
		self::assertNotContains(needle: 'bevoegdheidstoedeling', haystack: $schemas);
	}//end testAPreRenameIdFindsItsCopy()

	/**
	 * An unknown or empty id finds nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownOrEmptyIdFindsNothing(): void {
		$schemas = [];
		$rows    = new AuthorityDelegationRows(store: $this->store(rows: [['id' => 'ad-1']], schemas: $schemas));

		self::assertNull(actual: $rows->find(uuid: 'nope'));
		self::assertNull(actual: $rows->find(uuid: '  '));
	}//end testAnUnknownOrEmptyIdFindsNothing()

	/**
	 * A list read passes its filters to authority-delegation.
	 *
	 * @return void
	 */
	public function testAListReadFiltersAuthorityDelegation(): void {
		$schemas = [];
		$rows    = new AuthorityDelegationRows(
			store: $this->store(rows: [['id' => 'ad-1', 'status' => 'effective'], ['id' => 'ad-2', 'status' => 'draft']], schemas: $schemas)
		);

		self::assertSame(expected: ['ad-1'], actual: array_column($rows->findAll(filters: ['status' => 'effective']), 'id'));
		self::assertSame(expected: ['authority-delegation'], actual: $schemas);
	}//end testAListReadFiltersAuthorityDelegation()
}//end class
