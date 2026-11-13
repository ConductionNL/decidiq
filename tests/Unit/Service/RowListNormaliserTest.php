<?php

/**
 * Unit tests for RowListNormaliser.
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
use OCA\Decidiq\Service\RowListNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reducing a list of rows to arrays.
 *
 * @covers \OCA\Decidiq\Service\RowListNormaliser
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
class RowListNormaliserTest extends TestCase {
	public function testKeepsArraysAndSerializesObjectsDroppingTheRest(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'e'];
			}
		};

		$this->assertSame(
			[['id' => 'a'], ['id' => 'e']],
			(new RowListNormaliser())->rows(['k' => ['id' => 'a'], 'x' => 'scalar', 'y' => $entity, 'z' => null])
		);
	}

	public function testAScalarInputIsAListOfNothing(): void {
		$this->assertSame([], (new RowListNormaliser())->rows(null));
		$this->assertSame([], (new RowListNormaliser())->rows('abc'));
	}
}
