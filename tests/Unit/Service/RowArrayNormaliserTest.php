<?php

/**
 * Unit tests for RowArrayNormaliser.
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
use OCA\Decidiq\Service\RowArrayNormaliser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for reducing one row to an array.
 *
 * @covers \OCA\Decidiq\Service\RowArrayNormaliser
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
class RowArrayNormaliserTest extends TestCase {
	public function testSerializesAnEntity(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'e'];
			}
		};

		$this->assertSame(['id' => 'e'], (new RowArrayNormaliser())->toArray($entity));
	}

	public function testKeepsAnArray(): void {
		$this->assertSame(['id' => 'a'], (new RowArrayNormaliser())->toArray(['id' => 'a']));
	}

	public function testOtherValuesAreEmpty(): void {
		$normaliser = new RowArrayNormaliser();

		$this->assertSame([], $normaliser->toArray(null));
		$this->assertSame([], $normaliser->toArray('row'));
		$this->assertSame([], $normaliser->toArray(new stdClass()));
	}
}
