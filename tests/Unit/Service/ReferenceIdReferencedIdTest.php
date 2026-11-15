<?php

/**
 * Unit tests for ReferenceId::referencedId().
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

use OCA\Decidiq\Service\ReferenceId;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading the id out of a reference.
 *
 * @covers \OCA\Decidiq\Service\ReferenceId
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
class ReferenceIdReferencedIdTest extends TestCase {
	public function testAStringIsTheId(): void {
		$this->assertSame('abc', (new ReferenceId())->referencedId('abc'));
	}

	public function testAnArrayPrefersIdOverUuid(): void {
		$reference = new ReferenceId();

		$this->assertSame('1', $reference->referencedId(['id' => '1', 'uuid' => 'u']));
		$this->assertSame('u', $reference->referencedId(['uuid' => 'u']));
		$this->assertSame('7', $reference->referencedId(['id' => 7]));
		$this->assertSame('', $reference->referencedId([]));
	}

	public function testOtherValuesHaveNoId(): void {
		$this->assertSame('', (new ReferenceId())->referencedId(null));
		$this->assertSame('', (new ReferenceId())->referencedId(42));
	}
}
