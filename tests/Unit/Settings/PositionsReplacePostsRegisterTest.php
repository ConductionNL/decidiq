<?php

/**
 * Register test for the fragment that points appointments at position types.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The merged register points an appointment's targets at position types.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
final class PositionsReplacePostsRegisterTest extends TestCase {

	/**
	 * The merged schemas, keyed by schema key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $schemas = [];

	/**
	 * Merge the shipped register once, the way the importer does.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$schemas = SettingsService::shippedRegisterDescriptor()['components']['schemas'];

	}//end setUpBeforeClass()

	/**
	 * Target positions reference position types, and stay a list of uuids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testTargetPositionsReferencePositionTypes(): void {
		$targets = self::$schemas['Decision']['properties']['targetPosts'];

		self::assertSame(expected: 'array', actual: $targets['type']);
		self::assertSame(expected: 'position-type', actual: $targets['items']['$ref']);
		self::assertSame(expected: 'uuid', actual: $targets['items']['format']);
		self::assertSame(expected: 'Target positions', actual: $targets['title']);

	}//end testTargetPositionsReferencePositionTypes()

	/**
	 * The Decision version that lands is above every version any fragment declares.
	 *
	 * Fragments merge in name order and a later scalar wins, so a bump in a
	 * fragment that sorts early is overwritten by a lower one that sorts late.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testTheLandedDecisionVersionIsTheHighest(): void {
		$highest = '0.0.0';
		foreach (glob(SettingsService::fragmentDirectory() . '/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$version  = (string)($fragment['components']['schemas']['Decision']['version'] ?? '0.0.0');
			if (version_compare($version, $highest, '>') === true) {
				$highest = $version;
			}
		}

		self::assertSame(expected: $highest, actual: self::$schemas['Decision']['version']);

	}//end testTheLandedDecisionVersionIsTheHighest()

	/**
	 * Post stays readable but says what replaced it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testPostIsMarkedSupersededAndKept(): void {
		$post = self::$schemas['Post'];

		self::assertStringStartsWith(prefix: 'SUPERSEDED', string: $post['description']);
		self::assertFalse(condition: $post['x-openregister']['hardDelete']);
		self::assertSame(expected: 'post', actual: $post['slug']);

	}//end testPostIsMarkedSupersededAndKept()

	/**
	 * The schemas the migration and the writer fill exist with the fields they write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function testTheTargetSchemasDeclareWhatIsWritten(): void {
		foreach (['name', 'key', 'governanceBody', 'migratedFromObject'] as $field) {
			self::assertArrayHasKey(key: $field, array: self::$schemas['PositionType']['properties']);
		}

		foreach (['membership', 'position', 'governanceBody', 'holdType', 'startDate', 'endDate', 'appointedBy'] as $field) {
			self::assertArrayHasKey(key: $field, array: self::$schemas['PositionHold']['properties']);
		}

	}//end testTheTargetSchemasDeclareWhatIsWritten()
}//end class
