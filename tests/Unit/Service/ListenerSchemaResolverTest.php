<?php

/**
 * Unit tests for ListenerSchemaResolver.
 *
 * These tests are deliberately built on the REAL stubs
 * (tests/Stubs/Db/ObjectEntity.php, tests/Stubs/Db/Schema.php), never on a
 * PHPUnit mock of them. Both stubs honour the decidesk#399 signature parity
 * contract — they declare their accessors only as `@method` docblock tags. A
 * mock would let PHPUnit declare those accessors concretely.
 *
 * That used to match production exactly. It no longer does: OpenRegister has
 * since declared `getSchema()` for real on ObjectEntity, so where CI resolves
 * the real class `method_exists()` is true and the stub's is false. Nothing
 * here may assume either answer — see testGetSchemaIsReadableHoweverItIsDeclared.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the two defects decidesk#471 records: the magic-accessor probe and
 * the id-versus-slug value.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ListenerSchemaResolverTest extends TestCase {

	/**
	 * Calls the mapper double has received, for the memoisation assertion.
	 *
	 * @var array<int, string>
	 */
	private array $mapperCalls = [];

	/**
	 * Build an entity the way OpenRegister materialises one: the schema is the
	 * schema's numeric database id, and the payload carries no schema key.
	 *
	 * @param string $schemaId The numeric schema id OR stamps on the entity
	 * @param array<string, mixed> $payload The stored object payload
	 *
	 * @return ObjectEntity
	 */
	private function productionEntity(string $schemaId, array $payload = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('meet-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);

		return $entity;
	}//end productionEntity()

	/**
	 * Build a resolver whose container answers with a SchemaMapper double.
	 *
	 * @param array<string, string> $slugsById Slug per schema id the mapper knows
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(array $slugsById): ListenerSchemaResolver {
		$this->mapperCalls = [];
		$recorder = function (string $id) use ($slugsById): Schema {
			$this->mapperCalls[] = $id;
			if (array_key_exists($id, $slugsById) === false) {
				// Production's SchemaMapper::find() declares a non-nullable
				// Schema return and throws when the row is absent.
				throw new RuntimeException('Schema ' . $id . ' does not exist');
			}

			$schema = new Schema();
			$schema->setSlug($slugsById[$id]);

			return $schema;
		};

		$mapper = new class($recorder) {

			/**
			 * @param callable $recorder Delegate performing the lookup
			 */
			public function __construct(
				private $recorder,
			) {
			}

			/**
			 * Mirrors OCA\OpenRegister\Db\SchemaMapper::find()'s first parameter.
			 *
			 * @param string|integer $id The schema id, uuid or slug
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				return ($this->recorder)((string)$id);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new ListenerSchemaResolver(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end resolver()

	/**
	 * The regression this class exists for: an entity carrying a numeric schema
	 * id resolves to the slug the listeners compare against.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testNumericSchemaIdResolvesToItsSlug(): void {
		$resolver = $this->resolver(['93' => 'meeting']);

		self::assertSame(
			'meeting',
			$resolver->schemaSlug(entity: $this->productionEntity('93', ['title' => 'Q3 Meeting']))
		);

	}//end testNumericSchemaIdResolvesToItsSlug()

	/**
	 * The probe half of decidesk#471. It was pinned as `getSchema()` being served
	 * by Entity::__call() — `method_exists()` false, the read still succeeding.
	 * OpenRegister has since declared the method for real, so that half has
	 * FLIPPED and the assertion moved to what holds either way: the schema reads.
	 *
	 * @spec exclude Pins a framework property the fix depends on; no decidesk business rule.
	 *
	 * @return void
	 */
	public function testGetSchemaIsReadableHoweverItIsDeclared(): void {
		$entity = $this->productionEntity('93');

		// It FLIPPED. OpenRegister now declares `public function getSchema():
		// ?string` on ObjectEntity rather than serving it through
		// `Entity::__call()`, so `method_exists()` is true where this test
		// pinned it false. That is the condition the original docblock named:
		// the `method_exists()` guard this resolver replaced would work again,
		// and the resolver may now be redundant.
		//
		// Removing it is a decision about decidesk#471, not something to slip
		// into a translation change — so this asserts the property that
		// actually matters and that holds either way: the schema READS. The
		// redundancy question is left open deliberately.
		self::assertTrue(property_exists($entity, 'schema'), 'property_exists() is the working instrument');
		self::assertSame('93', $entity->getSchema());

	}//end testGetSchemaIsReadableHoweverItIsDeclared()

	/**
	 * A value that is not all digits is already a slug and is not round-tripped
	 * through the mapper.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testNonNumericSchemaIsTreatedAsASlug(): void {
		$resolver = $this->resolver([]);

		self::assertSame('meeting', $resolver->schemaSlug(entity: $this->productionEntity('Meeting')));
		self::assertSame([], $this->mapperCalls, 'a slug must not cost a database lookup');

	}//end testNonNumericSchemaIsTreatedAsASlug()

	/**
	 * An explicit slug on the row wins over the entity.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testRowSlugWinsOverTheEntity(): void {
		$resolver = $this->resolver(['93' => 'meeting']);

		self::assertSame(
			'decision',
			$resolver->schemaSlug(
				entity: $this->productionEntity('93'),
				row: ['_schemaSlug' => 'decision']
			)
		);

	}//end testRowSlugWinsOverTheEntity()

	/**
	 * An unknown schema, an absent OpenRegister and a null entity all degrade
	 * to '' — which every caller reads as "not my object".
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testUnresolvableSubjectsYieldEmptyString(): void {
		self::assertSame('', $this->resolver([])->schemaSlug(entity: $this->productionEntity('93')));
		self::assertSame('', $this->resolver(['93' => 'meeting'])->schemaSlug(entity: null));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('openregister not installed'));
		$absent = new ListenerSchemaResolver(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame('', $absent->schemaSlug(entity: $this->productionEntity('93')));

	}//end testUnresolvableSubjectsYieldEmptyString()

	/**
	 * The lookup is memoised: the unfiltered-registration fallback invokes a
	 * listener on every object write instance-wide, so one database read per
	 * write would be the openregister#2420 shape.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testSchemaLookupIsMemoisedPerRequest(): void {
		$resolver = $this->resolver(['93' => 'meeting']);

		$resolver->schemaSlug(entity: $this->productionEntity('93'));
		$resolver->schemaSlug(entity: $this->productionEntity('93'));
		$resolver->schemaSlug(entity: $this->productionEntity('94'));

		self::assertSame(['93', '94'], $this->mapperCalls);

	}//end testSchemaLookupIsMemoisedPerRequest()

	/**
	 * matchesSchema() is case-insensitive on the expected slug and refuses an
	 * empty expectation rather than matching everything.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testMatchesSchema(): void {
		$resolver = $this->resolver(['93' => 'meeting']);
		$entity = $this->productionEntity('93');

		self::assertTrue($resolver->matchesSchema(entity: $entity, expectedSlug: 'Meeting'));
		self::assertFalse($resolver->matchesSchema(entity: $entity, expectedSlug: 'decision'));
		self::assertFalse($resolver->matchesSchema(entity: $entity, expectedSlug: ''));

	}//end testMatchesSchema()

	/**
	 * readValue() reads a magic accessor and refuses a name that exists
	 * nowhere — the property `is_callable()` does NOT have, which is why the
	 * probe was not simply swapped.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testReadValueHandlesMagicAndAbsentAccessors(): void {
		$resolver = $this->resolver([]);
		$entity = $this->productionEntity('93');

		self::assertTrue(is_callable([$entity, 'getNoSuchThing']), 'is_callable() is true for ANY name here');

		self::assertSame('meet-1', $resolver->readValue(entity: $entity, getter: 'getUuid'));
		self::assertSame('', $resolver->readValue(entity: $entity, getter: 'getNoSuchThing'));
		self::assertSame('', $resolver->readValue(entity: $entity, getter: 'setUuid'));
		self::assertSame('', $resolver->readValue(entity: null, getter: 'getUuid'));

		// getObject() is genuinely concrete and returns an array, not a scalar.
		self::assertSame('', $resolver->readValue(entity: $entity, getter: 'getObject'));

	}//end testReadValueHandlesMagicAndAbsentAccessors()
}//end class
