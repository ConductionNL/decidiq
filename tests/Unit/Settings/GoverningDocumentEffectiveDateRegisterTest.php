<?php

/**
 * The governing document carries a planned and an actual effective date.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register from disk.
 *
 * @coversNothing
 */
class GoverningDocumentEffectiveDateRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The governing document schema merged over the base register and every fragment, and the highest version any file names.
	 *
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private function merged(): array {
		$files  = array_merge([self::SETTINGS . 'decidesk_register.json'], ((function (): array { $f = (glob(self::SETTINGS . 'register.d/*.json') ?: []); sort($f); return $f; })()));
		$schema = [];
		$top    = '0.0.0';
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === 'governing-document' || $name === 'GoverningDocument') {
					$schema = array_replace_recursive($schema, $fragment);
					if (version_compare((string)($fragment['version'] ?? '0.0.0'), $top, '>') === true) {
						$top = (string)$fragment['version'];
					}
				}
			}
		}

		return [$schema, $top];
	}//end merged()

	/**
	 * Both dates are optional dates, and the merged version is the fragment's.
	 *
	 * @return void
	 */
	public function testBothDatesAreDeclared(): void {
		[$schema, $top] = $this->merged();
		foreach (['plannedEffectiveDate', 'effectiveDate'] as $field) {
			self::assertSame('date', ($schema['properties'][$field]['format'] ?? null), $field);
			self::assertTrue(($schema['properties'][$field]['nullable'] ?? false), $field);
			self::assertNotContains($field, ($schema['required'] ?? []), $field . ' is optional');
			self::assertNotSame('', (string)($schema['properties'][$field]['description'] ?? ''), $field);
		}

		self::assertSame('0.3.0', $top);
		self::assertSame('0.3.0', $schema['version'], 'the last fragment to name the schema carries the highest version');
		self::assertContains('adopted', $schema['properties']['status']['enum'], 'the release reads adopted as approved');
	}//end testBothDatesAreDeclared()
}//end class
