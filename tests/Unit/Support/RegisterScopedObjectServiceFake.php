<?php

/**
 * Decidiq RegisterScopedObjectServiceFake
 *
 * An ObjectService stand-in that enforces the boundary the real one enforces.
 *
 * 🔴 WHY A FAKE THAT REFUSES, RATHER THAN ONE THAT ANSWERS.
 * The publish-path tests already had a fake ObjectService. Its `setSchema()`
 * stored the slug and returned, so every schema in the world resolved and the
 * suite was green while production threw. The defect it could not see:
 * `decision-template` was declared in `components.schemas` and missing from
 * `components.registers.decidiq.schemas`, so the register carried no such
 * schema, `setSchema('decision-template')` raised, the caller's
 * `catch (\Throwable)` logged a warning, and every decision published with no
 * remedy clause and a 200 OK.
 *
 * So this fake resolves a slug against the register decidiq ACTUALLY SHIPS,
 * read through `SettingsService::shippedRegisterDescriptor()` — the same merge
 * the importer hands to OpenRegister. Detach a schema from that list and every
 * test built on this fake reddens, which is the property the old one lacked.
 *
 * It mirrors `ObjectService::setSchema()`: a scoped miss THROWS rather than
 * falling back to a global lookup, and the exception is a
 * `DoesNotExistException`, which is what `SchemaNotInRegisterException`
 * extends.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\Decidiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * A register whose schema boundary is the one the app ships.
 */
final class RegisterScopedObjectServiceFake {

	/**
	 * The register the caller named, or null.
	 *
	 * @var string|null
	 */
	private ?string $register = null;

	/**
	 * The schema the caller named.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Every object written through this fake, newest last.
	 *
	 * @var array<int, array{register: string, schema: string, uuid: string|null, object: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows Stored rows: schema slug => uuid => object.
	 * @param list<string>|null $carried The slugs the register carries; the SHIPPED list when null.
	 * @param string $registerSlug The register these rows live in.
	 *
	 * @return void
	 */
	public function __construct(
		private array $rows = [],
		private readonly ?array $carried = null,
		private readonly string $registerSlug = 'decidiq',
	) {
	}//end __construct()

	/**
	 * The schema slugs the shipped register carries.
	 *
	 * @return list<string> The carried slugs.
	 */
	public static function shippedSchemaSlugs(): array {
		$descriptor = SettingsService::shippedRegisterDescriptor();
		$carried = ($descriptor['components']['registers']['decidiq']['schemas'] ?? []);

		return array_values(array_map(static fn ($slug): string => (string)$slug, (array)$carried));
	}//end shippedSchemaSlugs()

	/**
	 * Name the register every following call is scoped by.
	 *
	 * @param string $register The register slug.
	 *
	 * @return self This fake.
	 */
	public function setRegister(string $register): self {
		$this->register = $register;

		return $this;
	}//end setRegister()

	/**
	 * Name the schema, refusing one the named register does not carry.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return self This fake.
	 *
	 * @throws DoesNotExistException When the register does not carry the slug.
	 */
	public function setSchema(string $schema): self {
		if ($this->register !== null && in_array($schema, ($this->carried ?? self::shippedSchemaSlugs()), true) === false) {
			throw new DoesNotExistException(
				sprintf('Schema slug "%s" is not carried by register "%s"', $schema, $this->register)
			);
		}

		$this->schema = $schema;

		return $this;
	}//end setSchema()

	/**
	 * The object with this uuid in the schema last named.
	 *
	 * @param string $id The uuid.
	 *
	 * @return object|null The entity, or null when there is no such row.
	 */
	public function find(string $id): ?object {
		$row = ($this->rows[$this->schema][$id] ?? null);
		if ($row === null) {
			return null;
		}

		return self::entity(row: $row);
	}//end find()

	/**
	 * Store an object and answer what was stored.
	 *
	 * Writes land in `$this->rows` as well as the write log, so a caller that
	 * saves and then reads back sees its own write — which is what makes a
	 * two-step path (publish a decision, then build its publication) a real
	 * chain rather than two isolated calls.
	 *
	 * @param array<string, mixed> $object The object to store.
	 * @param string $register The register to store it in.
	 * @param string $schema The schema to store it under.
	 * @param string|null $uuid The uuid, when replacing an existing object.
	 *
	 * @return object The stored entity.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): object {
		$key = ($uuid ?? (string)($object['id'] ?? ('generated-' . (count($this->writes) + 1))));
		$stored = ($object + ['id' => $key]);

		$this->rows[$schema][$key] = $stored;
		$this->writes[] = [
			'register' => $register,
			'schema' => $schema,
			'uuid' => $uuid,
			'object' => $stored,
		];

		return self::entity(row: $stored);
	}//end saveObject()

	/**
	 * Every object stored in a schema, as the read path answers them.
	 *
	 * @param array<string, mixed> $config The findAll configuration; only `filters.schema` is honoured.
	 *
	 * @return array<int, object> The entities.
	 */
	public function findAll(array $config = []): array {
		$schema = (string)($config['filters']['schema'] ?? $this->schema);

		return array_values(
			array_map(
				static fn (array $row): object => self::entity(row: $row),
				($this->rows[$schema] ?? [])
			)
		);
	}//end findAll()

	/**
	 * Everything written through this fake.
	 *
	 * @return array<int, array{register: string, schema: string, uuid: string|null, object: array<string, mixed>}> The writes.
	 */
	public function writes(): array {
		return $this->writes;
	}//end writes()

	/**
	 * The last object written to a schema, or null when none was.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	public function lastWriteTo(string $schema): ?array {
		for ($i = (count($this->writes) - 1); $i >= 0; $i--) {
			if ($this->writes[$i]['schema'] === $schema) {
				return $this->writes[$i]['object'];
			}
		}

		return null;
	}//end lastWriteTo()

	/**
	 * Wrap a row in the entity shape OpenRegister answers with.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return object The entity.
	 */
	private static function entity(array $row): object {
		return new class ($row) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $row The row.
			 *
			 * @return void
			 */
			public function __construct(private readonly array $row) {
			}//end __construct()

			/**
			 * The stored object.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function getObject(): array {
				return $this->row;
			}//end getObject()
		};
	}//end entity()
}//end class
