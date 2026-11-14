<?php

/**
 * The Zuiddrecht example set carries accessibility scan reports.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment and the register from disk: the
 * reports the aggregate report and the badges show on a fresh install.
 *
 * @coversNothing
 */
class ExampleSetAccessibilityReportsTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	private const REGISTER = __DIR__ . '/../../../lib/Settings/decidesk_register.json';

	/**
	 * A JSON file as decoded data.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode(json: (string)file_get_contents(filename: $path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The example-set objects of one schema, by key.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function objects(string $schema): array {
		$found = [];
		foreach (self::json(path: self::FRAGMENT)['objects'] as $object) {
			if ($object['schema'] === $schema) {
				$found[$object['key']] = $object['data'];
			}
		}

		return $found;
	}//end objects()

	/**
	 * The key an `{{object:<key>.id}}` reference names.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string
	 */
	private static function keyOf(string $reference): string {
		return (string)preg_replace(pattern: '/^\{\{object:([^.}]+)\.id\}\}$/', replacement: '$1', subject: $reference);
	}//end keyOf()

	/**
	 * One report of each status, so every count of the aggregate report is non-zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
	 */
	public function testOneReportOfEachStatus(): void {
		$statuses = array_column(array: array_values(self::objects(schema: 'accessibility-scan-report')), column_key: 'status');
		sort($statuses);

		self::assertSame(expected: ['fail', 'not-scanned', 'pass', 'warnings'], actual: $statuses);
	}//end testOneReportOfEachStatus()

	/**
	 * Every report names an agenda item and a body of the set, and only fields the schema declares.
	 *
	 * @return void
	 */
	public function testEveryReportFitsTheSchemaAndTheSet(): void {
		$schema   = self::json(path: self::REGISTER)['components']['schemas']['AccessibilityScanReport'];
		$items    = self::objects(schema: 'agenda-item');
		$bodies   = self::objects(schema: 'governance-body');
		$severity = $schema['properties']['findings']['items']['properties']['severity']['enum'];
		foreach (self::objects(schema: 'accessibility-scan-report') as $key => $report) {
			self::assertSame(expected: [], actual: array_values(array_diff(array_keys($report), array_keys($schema['properties']))), message: $key);
			foreach ($schema['required'] as $field) {
				self::assertArrayHasKey(key: $field, array: $report, message: $key);
			}

			self::assertContains(needle: $report['status'], haystack: $schema['properties']['status']['enum'], message: $key);
			self::assertArrayHasKey(key: self::keyOf(reference: $report['sourceObject']), array: $items, message: $key);
			self::assertArrayHasKey(key: self::keyOf(reference: $report['governanceBody']), array: $bodies, message: $key);
			foreach ($report['findings'] as $finding) {
				self::assertContains(needle: $finding['severity'], haystack: $severity, message: $key);
			}

			self::assertSame(expected: ($report['status'] === 'pass'), actual: ($report['findings'] === []), message: $key . ': only a passing report has no findings');
		}
	}//end testEveryReportFitsTheSchemaAndTheSet()

	/**
	 * The failing report carries the findings of a scanned image.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function testTheFailingReportIsAScannedImage(): void {
		$fail = self::objects(schema: 'accessibility-scan-report')['scan-bijlage-kaart-lindelaan'];

		self::assertSame(expected: ['no-text-layer', 'not-tagged'], actual: array_column(array: $fail['findings'], column_key: 'code'));
	}//end testTheFailingReportIsAScannedImage()

	/**
	 * One published agenda carries an example override with a Dutch reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function testAPublishedAgendaCarriesAnOverride(): void {
		$overrides = [];
		foreach (self::json(path: self::FRAGMENT)['changes'] as $change) {
			if (isset($change['set']['accessibilityOverrides']) === true) {
				$overrides[$change['object']] = $change['set']['accessibilityOverrides'];
			}
		}

		self::assertSame(expected: ['meeting-raad-1'], actual: array_keys($overrides));
		self::assertArrayHasKey(key: 'meeting-raad-1', array: self::objects(schema: 'meeting'));
		self::assertSame(
			expected: 'Brondocument niet meer beschikbaar; publicatie wettelijk termijngebonden',
			actual: $overrides['meeting-raad-1'][0]['reason']
		);
	}//end testAPublishedAgendaCarriesAnOverride()
}//end class
