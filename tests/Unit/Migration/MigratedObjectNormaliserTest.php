<?php

/**
 * Unit tests for MigratedObjectNormaliser.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\MigratedObjectNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Turning whatever OpenRegister hands back into an array, or null.
 *
 * @covers \OCA\Decidiq\Migration\MigratedObjectNormaliser
 */
final class MigratedObjectNormaliserTest extends TestCase {

	/**
	 * An array passes through unchanged.
	 *
	 * @return void
	 */
	public function testAnArrayPassesThrough(): void {
		self::assertSame(['id' => 'x'], (new MigratedObjectNormaliser())->toArray(['id' => 'x']));

	}//end testAnArrayPassesThrough()

	/**
	 * A serialisable entity is read through jsonSerialize(), before getObject().
	 *
	 * @return void
	 */
	public function testASerialisableEntityIsReadThroughJsonSerialize(): void {
		$entity = new class {
			public function jsonSerialize(): array {
				return ['id' => 'from-json'];
			}

			public function getObject(): array {
				return ['id' => 'from-object'];
			}
		};

		self::assertSame(['id' => 'from-json'], (new MigratedObjectNormaliser())->toArray($entity));

	}//end testASerialisableEntityIsReadThroughJsonSerialize()

	/**
	 * An entity offering only getObject() is read through it.
	 *
	 * @return void
	 */
	public function testAnEntityWithOnlyGetObjectIsReadThroughIt(): void {
		$entity = new class {
			public function getObject(): array {
				return ['id' => 'from-object'];
			}
		};

		self::assertSame(['id' => 'from-object'], (new MigratedObjectNormaliser())->toArray($entity));

	}//end testAnEntityWithOnlyGetObjectIsReadThroughIt()

	/**
	 * Anything that does not yield an array is null.
	 *
	 * @return void
	 */
	public function testNonArrayResultsAreNull(): void {
		$normaliser = new MigratedObjectNormaliser();
		$scalarJson = new class {
			public function jsonSerialize(): string {
				return 'not an array';
			}
		};

		self::assertNull($normaliser->toArray($scalarJson));
		self::assertNull($normaliser->toArray(new \stdClass()));
		self::assertNull($normaliser->toArray('row'));
		self::assertNull($normaliser->toArray(null));

	}//end testNonArrayResultsAreNull()
}//end class
