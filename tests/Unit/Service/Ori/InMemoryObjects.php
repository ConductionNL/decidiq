<?php

/**
 * An in-memory OpenRegister object store for the ORI import tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Objects keyed by register, schema and uuid, behind a mocked ObjectServiceInterface.
 */
final class InMemoryObjects {

	/**
	 * register => schema => uuid => object.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public array $objects = [];

	/**
	 * Every write: [action, register, schema, uuid].
	 *
	 * @var array<int, array{string, string, string, string}>
	 */
	public array $writes = [];

	private int $next = 0;

	/**
	 * Put an object in place without counting it as a write.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $object   The object, with `id`.
	 *
	 * @return void
	 */
	public function seed(string $register, string $schema, array $object): void {
		$this->objects[$register][$schema][(string)$object['id']] = $object;
	}

	/**
	 * Every object of one schema.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(string $register, string $schema): array {
		return array_values($this->objects[$register][$schema] ?? []);
	}

	/**
	 * How many objects the register holds.
	 *
	 * @param string $register The register.
	 *
	 * @return int
	 */
	public function count(string $register): int {
		return array_sum(array_map('count', $this->objects[$register] ?? []));
	}

	/**
	 * The one object of a schema carrying a tag.
	 *
	 * @param string $schema The schema in the decidiq register.
	 * @param string $tag    The externalReference.
	 *
	 * @return array<string, mixed>
	 */
	public function tagged(string $schema, string $tag): array {
		$found = array_values(array_filter($this->all('decidiq', $schema), static fn (array $o): bool => ($o['externalReference'] ?? null) === $tag));
		TestCase::assertCount(1, $found, $schema . ' ' . $tag);
		return $found[0];
	}

	/**
	 * The mocked object service over these objects.
	 *
	 * @param TestCase $test The test.
	 *
	 * @return ObjectServiceInterface
	 */
	public function service(TestCase $test): ObjectServiceInterface {
		$service = (new \PHPUnit\Framework\MockObject\MockBuilder($test, ObjectServiceInterface::class))->getMock();
		$service->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				$filters = ($config['filters'] ?? []);
				return $this->all((string)$filters['register'], (string)$filters['schema']);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) use ($test): ObjectEntityInterface {
				$action = 'update';
				if ($uuid === null) {
					$action = 'create';
					$uuid = sprintf('00000000-0000-4000-8000-%012d', ++$this->next);
				}

				$this->objects[(string)$register][(string)$schema][$uuid] = ['id' => $uuid] + $object;
				$this->writes[] = [$action, (string)$register, (string)$schema, $uuid];
				$entity = (new \PHPUnit\Framework\MockObject\MockBuilder($test, ObjectEntityInterface::class))->getMock();
				$entity->method('getUuid')->willReturn($uuid);
				return $entity;
			}
		);
		$service->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register=null, $schema=null): bool {
				unset($this->objects[(string)$register][(string)$schema][$uuid]);
				$this->writes[] = ['delete', (string)$register, (string)$schema, $uuid];
				return true;
			}
		);

		return $service;
	}
}
