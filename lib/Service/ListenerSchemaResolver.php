<?php

/**
 * Decidiq Listener Schema Resolver
 *
 * Resolves an OpenRegister object entity's schema **slug** for the
 * object-lifecycle listeners, which compare against slug literals
 * (`meeting`, `decision`, `participant`, ...).
 *
 * Two independent defects made every one of those comparisons impossible, and
 * fixing either alone leaves the listener dead (decidiq#471):
 *
 * ⚠️ **The probe half is MOOT on current OpenRegister, and the class is still
 * required.** OpenRegister published its ObjectService/ObjectEntity interfaces
 * (ADR-084) and now DECLARES `getSchema()`, so `method_exists()` answers true
 * where defect 1 below says it answers false. `testGetSchemaIsReadableHoweverItIsDeclared()`
 * records that flip and deliberately leaves the redundancy question open. This
 * is the answer to it: **defect 2 is untouched, so the class stays.**
 * OpenRegister still stamps the schema's numeric id onto every entity it
 * materialises — `setSchema((string) $schema->getId())` in MagicMapper and in
 * every ObjectSource provider, verified 2026-08-16 — and a declared getter
 * returns that id, never the slug the listeners compare against. Only if
 * OpenRegister began stamping the slug itself would this class become
 * redundant. `readValue()` needs no change either: it probes
 * `method_exists() || property_exists()`, so it reads the value under both
 * shapes, which is what keeps this working against old and new OpenRegister.
 *
 * 1. **The probe.** `OCA\OpenRegister\Db\ObjectEntity` extends
 *    `OCP\AppFramework\Db\Entity` and USED TO declare `getSchema()` only as an
 *    `@method` docblock tag, with `Entity::__call()` serving it.
 *    `method_exists()` was therefore FALSE for it, so a `method_exists()`-guarded
 *    accessor read was skipped for every entity that actually has a schema.
 *    `is_callable()` is NOT the remedy: it is true for ANY name on a `__call`
 *    class, so swapping the probe makes the branch unconditionally true and the
 *    call then raises `BadFunctionCallException`. `Entity::__call()` routes
 *    `get*` to `Entity::getter()`, which decides on `property_exists()` — so
 *    `property_exists()` is the instrument the framework itself uses, and the
 *    call is additionally made exception-safe here.
 * 2. **The value.** `MagicMapper` and `SaveObject` stamp the schema's numeric
 *    database **id** onto every entity they materialise
 *    (`$entity->setSchema((string) $schema->getId())`). An id can never equal a
 *    slug literal, so even a correctly-probed read returns something like
 *    `"93"` and the comparison still fails. The id is resolved back to its slug
 *    through OpenRegister's `SchemaMapper`.
 *    **This half is unchanged and is why the class survives** — see the note
 *    above. A declared getter returns the same numeric id the magic one did.
 *
 * OpenRegister is a soft dependency: the mapper is pulled from the DI container
 * at call time and every failure degrades to `''`, which every caller reads as
 * "not my object" and returns early. An unresolvable entity is therefore never
 * mistaken for a match.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an OpenRegister entity's schema value into the slug Decidiq's
 * listeners compare against.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ListenerSchemaResolver {

	/**
	 * FQCN of OpenRegister's schema mapper.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Row keys that may carry the schema, in precedence order.
	 *
	 * `_schemaSlug` is a slug by name; `_schema` is the raw database column and
	 * holds an id. Both are normalised by {@see schemaSlug()} rather than
	 * trusted as slugs.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = [
		'_schemaSlug',
		'_schema',
		'schema',
	];

	/**
	 * Per-request memo of schema id to slug.
	 *
	 * The unfiltered-registration fallback in ObjectListenerRegistrar invokes a
	 * listener on every object write instance-wide, so an unmemoised lookup
	 * would be a database read per write.
	 *
	 * @var array<string, string>
	 */
	private array $slugById = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OpenRegister's mapper is
	 *                                      resolved lazily so Decidiq boots without it
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the schema slug of an OpenRegister object entity.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event
	 * @param array<string, mixed> $row Serialised payload, when the caller already has one
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string The lower-cased schema slug, or '' when unresolvable
	 */
	public function schemaSlug(?object $entity, array $row = []): string {
		$raw = $this->rawSchema(entity: $entity, row: $row);
		if ($raw === '') {
			return '';
		}

		// A wholly numeric value is OpenRegister's schema id (an all-digit slug
		// is not a shape its slug generator produces, and ObjectEventSubscription
		// makes the same classification). Anything else already IS the slug —
		// a hand-built entity, a fixture, or a future OpenRegister that stops
		// stamping ids.
		if (ctype_digit($raw) === false) {
			return strtolower($raw);
		}

		return strtolower($this->resolveSlug(id: $raw));
	}//end schemaSlug()

	/**
	 * Whether an entity belongs to the named schema.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event
	 * @param string $expectedSlug The schema slug to match
	 * @param array<string, mixed> $row Serialised payload, when the caller already has one
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return boolean True when the entity is an instance of that schema
	 */
	public function matchesSchema(?object $entity, string $expectedSlug, array $row = []): bool {
		if ($expectedSlug === '') {
			return false;
		}

		return $this->schemaSlug(entity: $entity, row: $row) === strtolower($expectedSlug);
	}//end matchesSchema()

	/**
	 * Read a scalar off an entity through an accessor that may be magic.
	 *
	 * `method_exists()` answers false for every accessor `Entity::__call()`
	 * serves — including `getId()` and `getUuid()` — so it cannot be used to
	 * decide whether the read is available. `Entity::__call()` delegates to
	 * `Entity::getter()`, which throws `BadFunctionCallException` unless
	 * `property_exists()` holds; asking that question first keeps a normal miss
	 * off the exception path, and the try/catch covers everything else (a
	 * property the subclass declares but refuses to serve, a mapper entity with
	 * its own getter override).
	 *
	 * @param object|null $entity The entity to read from
	 * @param string $getter The accessor name, e.g. 'getSchema'
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string The value as a string, or '' when unavailable
	 */
	public function readValue(?object $entity, string $getter): string {
		if ($entity === null || str_starts_with($getter, 'get') === false) {
			return '';
		}

		$property = lcfirst(substr($getter, 3));
		if (method_exists($entity, $getter) === false
			&& property_exists($entity, $property) === false
		) {
			return '';
		}

		try {
			$value = $entity->{$getter}();
		} catch (Throwable $e) {
			return '';
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end readValue()

	/**
	 * Collect the schema value from the row, then from the entity.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event
	 * @param array<string, mixed> $row Serialised payload
	 *
	 * @return string The raw schema value (a slug or an id), or '' when absent
	 */
	private function rawSchema(?object $entity, array $row): string {
		foreach (self::ROW_KEYS as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}

			if (is_int($candidate) === true) {
				return (string)$candidate;
			}
		}

		// No OpenRegister version shipped to date declares getSchemaSlug(), on
		// the entity or in its docblock, so this resolves nothing today. It is
		// consulted first so that an OpenRegister which adds it wins over the
		// id round-trip below without another change here.
		$slug = $this->readValue(entity: $entity, getter: 'getSchemaSlug');
		if ($slug !== '') {
			return $slug;
		}

		return $this->readValue(entity: $entity, getter: 'getSchema');
	}//end rawSchema()

	/**
	 * Look a schema's slug up by id through OpenRegister's SchemaMapper.
	 *
	 * @param string $id The schema id
	 *
	 * @return string The slug, or '' when unresolvable or OpenRegister is absent
	 */
	private function resolveSlug(string $id): string {
		if (array_key_exists($id, $this->slugById) === true) {
			return $this->slugById[$id];
		}

		$slug = '';
		try {
			// One positional argument only. `find()` declares
			// `(string|int $id, ?array $_extend, bool $_rbac, bool $_multitenancy)`
			// and slot 3 is a bool, so a second positional argument is a
			// signature-drift fatal waiting to happen.
			$schema = $this->container->get(self::SCHEMA_MAPPER)->find($id);

			// Schema::getSlug() is magic too — an `@method` tag over
			// `protected ?string $slug`.
			$slug = $this->readValue(entity: $schema, getter: 'getSlug');
		} catch (Throwable $e) {
			$this->logger->debug(
				'Decidiq: could not resolve an OpenRegister schema slug for a listener guard',
				[
					'schemaId' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		$this->slugById[$id] = $slug;

		return $slug;
	}//end resolveSlug()
}//end class
