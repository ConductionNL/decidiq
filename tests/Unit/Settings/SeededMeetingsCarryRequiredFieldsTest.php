<?php

/**
 * Every meeting an example set ships carries the meeting schema's required
 * properties with a value. A seeded meeting without one imports, but the
 * first save of it afterwards is refused: on 25 Nov 2026 "Informatieavond
 * windpark Noord" (municipality) had no meetingType, so pointing it at its
 * archival dossier failed with "Property 'meetingType' should be type 'string'
 * but is 'null'" and the archive dashboard counted it as a meeting without a
 * dossier.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests that seeded meetings can be saved again.
 */
final class SeededMeetingsCarryRequiredFieldsTest extends TestCase {

	/**
	 * Every shipped example set file.
	 *
	 * @return list<string>
	 */
	private static function sets(): array {
		$root = __DIR__ . '/../../../lib/Settings';
		$sets = [];
		foreach (['/profiles/*.json', '/example-sets/*.json'] as $pattern) {
			$found = glob($root . $pattern);
			if (is_array($found) === true) {
				$sets = array_merge($sets, $found);
			}
		}

		return $sets;
	}//end sets()

	/**
	 * No seeded meeting lacks a required property or names a meeting type the schema does not know.
	 *
	 * @return void
	 */
	public function testEverySeededMeetingCarriesItsRequiredProperties(): void {
		$schema   = MergedRegisterSchema::forSlug(slug: 'meeting');
		$required = (array)($schema['required'] ?? []);
		$types    = (array)($schema['properties']['meetingType']['enum'] ?? []);
		self::assertContains(needle: 'meetingType', haystack: $required);

		$missing = [];
		foreach (self::sets() as $file) {
			$set = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach ((array)($set['x-openregister']['seedData']['objects']['meeting'] ?? []) as $meeting) {
				$slug = basename($file) . ' ' . (string)($meeting['slug'] ?? '?');
				foreach ($required as $property) {
					if (($meeting[$property] ?? null) === null || $meeting[$property] === '') {
						$missing[] = $slug . ' lacks ' . $property;
					}
				}

				if (isset($meeting['meetingType']) === true && in_array($meeting['meetingType'], $types, true) === false) {
					$missing[] = $slug . ' names meeting type ' . (string)$meeting['meetingType'];
				}
			}
		}

		self::assertSame(expected: [], actual: $missing);
	}//end testEverySeededMeetingCarriesItsRequiredProperties()
}//end class
