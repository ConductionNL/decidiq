<?php

/**
 * Unit tests for ClearableField.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/user-settings/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ClearableField;
use PHPUnit\Framework\TestCase;

/**
 * Tests for a clearable settings field's validation.
 *
 * @covers \OCA\Decidiq\Service\ClearableField
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class ClearableFieldTest extends TestCase {
	private function field(): ClearableField {
		return new ClearableField('locale', static fn (string $raw): bool => $raw === 'nl', 'Invalid locale');
	}

	public function testExposesItsParts(): void {
		$field = $this->field();

		$this->assertSame('locale', $field->param);
		$this->assertSame('Invalid locale', $field->errorMsg);
	}

	public function testAcceptsAValidString(): void {
		$this->assertTrue($this->field()->accepts('nl'));
	}

	public function testRefusesAnInvalidString(): void {
		$this->assertFalse($this->field()->accepts('xx'));
	}

	public function testRefusesNonStringsWithoutCallingTheValidator(): void {
		$called = false;
		$field  = new ClearableField(
			'x',
			static function () use (&$called): bool {
				$called = true;
				return true;
			},
			'e'
		);

		$this->assertFalse($field->accepts(null));
		$this->assertFalse($field->accepts(1));
		$this->assertFalse($called);
	}

	public function testOnlyStrictTrueFromTheValidatorCounts(): void {
		$field = new ClearableField('x', static fn (): int => 1, 'e');

		$this->assertFalse($field->accepts('a'));
	}
}
