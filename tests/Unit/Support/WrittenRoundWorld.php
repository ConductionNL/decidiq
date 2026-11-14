<?php

/**
 * An in-memory OpenRegister for the written-round tests.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Rows by schema and id behind a mocked ObjectServiceInterface: find, findAll
 * (filtered on the given properties) and saveObject (which assigns an id and
 * records every save in order).
 */
final class WrittenRoundWorld {

	/**
	 * Rows by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every save, in order: [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $saves = [];

	/**
	 * The config of every findAll call.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Ids handed out so far.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test that owns the mocks.
	 */
	public function __construct(private readonly TestCase $test) {
	}//end __construct()

	/**
	 * Put a row in.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The row, with an id.
	 *
	 * @return void
	 */
	public function put(string $schema, array $row): void {
		$this->rows[$schema][(string)$row['id']] = $row;
	}//end put()

	/**
	 * The saves of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function savesOf(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save[1], array_filter($this->saves, static fn (array $save): bool => $save[0] === $schema)));
	}//end savesOf()

	/**
	 * The mocked object service over these rows.
	 *
	 * @return ObjectServiceInterface
	 */
	public function objectService(): ObjectServiceInterface {
		$service = $this->test->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$service->method('find')->willReturnCallback(
			fn (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntityInterface
				=> isset($this->rows[(string)$schema][(string)$id]) ? $this->entity(row: $this->rows[(string)$schema][(string)$id]) : null
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				$this->queries[] = $config;
				$filters = (array)($config['filters'] ?? []);
				$schema  = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $this->entity(row: $row);
				}

				return array_slice($found, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 500));
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null): ObjectEntityInterface {
				if (($object['id'] ?? null) === null) {
					$object['id'] = (string)$schema . '-' . (++$this->next);
				}

				$this->saves[] = [(string)$schema, $object];
				$this->rows[(string)$schema][(string)$object['id']] = $object;

				return $this->entity(row: $object);
			}
		);

		return $service;
	}//end objectService()

	/**
	 * An entity serialising to the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->test->getMockBuilder(ObjectEntityInterface::class)->getMock();
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getObject')->willReturn($row);
		$entity->method('getUuid')->willReturn((string)($row['id'] ?? ''));

		return $entity;
	}//end entity()
}//end class
