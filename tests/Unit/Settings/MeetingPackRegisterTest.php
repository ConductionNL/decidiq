<?php

/**
 * A compiled meeting pack is its own versioned record per meeting, an agenda
 * item says how confidential it is, and a new pack is notified declaratively
 * (meeting-pack-board-book tasks 2 and 3).
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
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MeetingPackRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const WRITERS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * The schema with this slug, merged over the base register and every fragment.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed> The merged schema.
	 */
	private function mergedSchema(string $slug): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * An example set's seed objects per schema.
	 *
	 * @param string $profile The example set
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seeds.
	 */
	private function seeds(string $profile): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The pack schema exists with every field a version needs, is listed on
	 * the register, and only staff write it.
	 *
	 * @return void
	 */
	public function testThePackSchemaIsDeclared(): void {
		$schema = $this->mergedSchema('meeting-pack');
		self::assertNotSame([], $schema, 'meeting-pack exists in the merged register');
		self::assertSame('schema:DigitalDocument', ($schema['x-schema-org'] ?? null));
		self::assertSame('meeting', ($schema['properties']['meeting']['$ref'] ?? null));
		self::assertSame(['meeting', 'version', 'generatedAt', 'fingerprint'], ($schema['required'] ?? null));
		self::assertSame(1, ($schema['properties']['version']['minimum'] ?? null));
		foreach (['changeNote', 'generatedAt', 'generatedBy', 'fingerprint', 'filePath', 'pageCount', 'skipped', 'deliveryFailures'] as $property) {
			self::assertArrayHasKey($property, $schema['properties']);
		}

		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
			self::assertNotSame('', (string)($property['description'] ?? ''), $key . ' has a description');
		}

		foreach (['create', 'update', 'delete'] as $verb) {
			self::assertSame(self::WRITERS, ($schema['authorization'][$verb] ?? null), $verb . ' is the secretariat and administrators only');
		}

		// The register baseline also grants anonymous reads; a pack bundles a
		// meeting's papers, so its own block keeps reading to signed-in users.
		self::assertSame(['authenticated'], ($schema['authorization']['read'] ?? null));
		self::assertSame(['authenticated'], ($schema['authorization']['list'] ?? null));

		$listed = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$listed = array_merge($listed, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('meeting-pack', $listed, 'the decidiq register lists meeting-pack');
	}//end testThePackSchemaIsDeclared()

	/**
	 * A new pack is notified to everyone who may read it, declaratively, in
	 * both languages.
	 *
	 * @return void
	 */
	public function testANewPackIsNotifiedDeclaratively(): void {
		$notifications = $this->mergedSchema('meeting-pack')['x-openregister-notifications'] ?? [];
		self::assertSame(['meetingPackAvailable'], array_keys($notifications));
		$entry = $notifications['meetingPackAvailable'];
		self::assertSame(['type' => 'created'], $entry['trigger']);
		self::assertTrue($entry['enabled']);
		self::assertSame(['nc-notification'], $entry['channels']);
		self::assertSame([['kind' => 'object-acl', 'permission' => 'read']], $entry['recipients']);
		self::assertSame(['nl', 'en'], array_keys($entry['subject']));
		foreach ($entry['subject'] as $subject) {
			self::assertStringContainsString('{{version}}', $subject);
		}
	}//end testANewPackIsNotifiedDeclaratively()

	/**
	 * An agenda item says how confidential it is, internal unless set.
	 *
	 * @return void
	 */
	public function testAnAgendaItemCarriesItsConfidentiality(): void {
		$schema = $this->mergedSchema('agenda-item');
		$property = $schema['properties']['confidentiality'] ?? [];
		self::assertSame(['public', 'internal', 'confidential'], ($property['enum'] ?? null));
		self::assertSame('internal', ($property['default'] ?? null));
		self::assertNotContains('confidentiality', ($schema['required'] ?? []), 'existing items need no value');
		$fragment = json_decode((string)file_get_contents(self::SETTINGS . 'register.d/132-meeting-pack.json'), true);
		self::assertSame('1.4.0', $fragment['components']['schemas']['AgendaItem']['version'], 'the fragment raises the agenda item version past 1.3.0');
	}//end testAnAgendaItemCarriesItsConfidentiality()

	/**
	 * Four seeded packs over three seeded meetings: the council meeting has
	 * two versions, the second with its change note and a paper left out,
	 * and every pack validates against the merged schema.
	 *
	 * @return void
	 */
	public function testTheExampleSetsSeedFourPacks(): void {
		$byProfile = ['municipality' => 2, 'corporate' => 2];
		$all = [];
		foreach ($byProfile as $profile => $count) {
			$seeds = $this->seeds(profile: $profile);
			$meetings = array_column($seeds['meeting'], null, 'slug');
			$packs = ($seeds['meeting-pack'] ?? []);
			self::assertCount($count, $packs, $profile);
			foreach ($packs as $pack) {
				self::assertArrayHasKey($pack['meeting'], $meetings, 'the pack names a seeded meeting');
				self::assertSame('meeting-pack', $pack['@self']['schema']);
				$this->assertValidSeed(slug: 'meeting-pack', object: $pack);
				$all[$pack['slug']] = $pack;
			}
		}

		self::assertSame(1, $all['vergaderbundel-raad-2025-01-15-v1']['version']);
		self::assertSame('', $all['vergaderbundel-raad-2025-01-15-v1']['changeNote']);
		$second = $all['vergaderbundel-raad-2025-01-15-v2'];
		self::assertSame(2, $second['version']);
		self::assertNotSame('', $second['changeNote'], 'a second version says what changed');
		self::assertCount(1, $second['skipped']);
		self::assertStringContainsString('begroting-detail.xlsx', $second['skipped'][0]);
		self::assertSame(
			['raadsvergadering-2025-01-15', 'rvc-vergadering-q1-2025', 'directieoverleg-2025-04-14'],
			array_values(array_unique(array_column($all, 'meeting')))
		);
	}//end testTheExampleSetsSeedFourPacks()

	/**
	 * The annual accounts item is seeded confidential, so its pack section
	 * shows the placeholder; the other items keep the default.
	 *
	 * @return void
	 */
	public function testTheAnnualAccountsItemIsSeededConfidential(): void {
		$items = $this->seeds(profile: 'municipality')['agenda-item'];
		$confidential = array_values(array_filter($items, static fn (array $item): bool => ($item['confidentiality'] ?? 'internal') === 'confidential'));
		self::assertSame(['jaarrekening-2024'], array_column($confidential, 'slug'));
		$this->assertValidSeed(slug: 'agenda-item', object: $confidential[0], strict: false);
	}//end testTheAnnualAccountsItemIsSeededConfidential()

	/**
	 * Assert a seed validates against the merged schema.
	 *
	 * @param string               $slug   The schema slug
	 * @param array<string, mixed> $object The seed
	 * @param bool                 $strict Whether unknown properties are refused
	 *
	 * @return void
	 */
	private function assertValidSeed(string $slug, array $object, bool $strict=true): void {
		$schema = $this->mergedSchema($slug);
		unset($object['@self'], $object['slug']);
		$properties = [];
		foreach ($schema['properties'] as $key => $property) {
			if (isset($property['$ref']) === true) {
				unset($property['format']);
			}

			unset($property['$ref'], $property['facetable']);
			$properties[$key] = $property;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => ($strict === false)]))
		);
		self::assertTrue($result->isValid(), sprintf('The %s seed validates: %s', $slug, json_encode($object)));
	}//end assertValidSeed()
}//end class
