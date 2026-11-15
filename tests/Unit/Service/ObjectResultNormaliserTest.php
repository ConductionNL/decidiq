<?php

/**
 * Unit tests for ObjectResultNormaliser.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Decidiq\Service\ObjectResultNormaliser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for reducing an object-service result to an array.
 *
 * @covers \OCA\Decidiq\Service\ObjectResultNormaliser
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
class ObjectResultNormaliserTest extends TestCase {
	public function testAnArrayIsReturnedAsIs(): void {
		$this->assertSame(['id' => 1], (new ObjectResultNormaliser())->toArray(['id' => 1]));
	}

	public function testAJsonSerializableObjectIsSerialized(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'x'];
			}

			public function getObject(): array {
				return ['wrong' => true];
			}
		};

		$this->assertSame(['id' => 'x'], (new ObjectResultNormaliser())->toArray($entity));
	}

	public function testANonArraySerializationFallsBackToGetObject(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): string {
				return 'scalar';
			}

			public function getObject(): array {
				return ['id' => 'y'];
			}
		};

		$this->assertSame(['id' => 'y'], (new ObjectResultNormaliser())->toArray($entity));
	}

	public function testGetObjectMustReturnAnArray(): void {
		$entity = new class {
			public function getObject(): string {
				return 'nope';
			}
		};

		$this->assertNull((new ObjectResultNormaliser())->toArray($entity));
	}

	public function testOtherValuesAreNull(): void {
		$normaliser = new ObjectResultNormaliser();

		$this->assertNull($normaliser->toArray(null));
		$this->assertNull($normaliser->toArray('id'));
		$this->assertNull($normaliser->toArray(new stdClass()));
	}
}
