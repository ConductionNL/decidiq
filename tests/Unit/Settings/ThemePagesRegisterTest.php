<?php

/**
 * The theme schema and the municipality example theme (publication-theme-pages
 * task 1): the theme validates against the merged schema, links one object of
 * every kind, and each link names a seeded object of the schema it points at.
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
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\ThemeTimelineBuilder;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register and the municipality profile from disk.
 *
 * @coversNothing
 */
class ThemePagesRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The schema with every fragment that declares it merged in.
	 *
	 * @param string $slug The schema slug.
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
	 * The seed objects of the municipality profile, keyed by schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seeds.
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The example theme.
	 *
	 * @return array<string, mixed> The theme.
	 */
	private function poolTheme(): array {
		foreach ($this->seeds()['governance-theme'] ?? [] as $theme) {
			if (($theme['slug'] ?? '') === 'thema-nieuw-zwembad-de-hoge-dijk') {
				return $theme;
			}
		}

		self::fail('the municipality profile seeds the swimming pool theme');
	}//end poolTheme()

	/**
	 * The example theme is active, public, and valid against the merged schema (slugs read as the uuids the import resolves them to).
	 *
	 * @return void
	 */
	public function testTheSeededThemeIsValid(): void {
		$theme = $this->poolTheme();
		self::assertSame('Nieuw zwembad De Hoge Dijk', $theme['title']);
		self::assertSame('active', $theme['status']);
		self::assertTrue($theme['isPublic']);

		$schema = $this->mergedSchema('governance-theme');
		$properties = [];
		foreach ($schema['properties'] as $name => $property) {
			unset($property['$ref'], $property['format'], $property['facetable'], $property['visible'], $property['order']);
			if (isset($property['items']) === true && is_array($property['items']) === true) {
				unset($property['items']['$ref'], $property['items']['format']);
			}

			$properties[$name] = $property;
		}

		$data = $theme;
		unset($data['@self'], $data['slug']);
		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			(string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties, 'additionalProperties' => false])
		);
		$message = '';
		if ($result->isValid() === false) {
			$message = (string)json_encode((new ErrorFormatter())->format($result->error()));
		}

		self::assertTrue($result->isValid(), $message);
	}//end testTheSeededThemeIsValid()

	/**
	 * Every link kind holds one object, and each names a seeded object of the schema the timeline reads it from: five entries.
	 *
	 * @return void
	 */
	public function testEveryLinkNamesASeededObjectOfItsKind(): void {
		$theme = $this->poolTheme();
		$seeds = $this->seeds();
		$entries = 0;
		foreach (ThemeTimelineBuilder::LINKS as $property => $link) {
			self::assertCount(1, $theme[$property], $property . ' holds one object');
			$slugs = array_map(static fn (array $object): string => (string)($object['slug'] ?? ''), ($seeds[$link['schema']] ?? []));
			foreach ($theme[$property] as $slug) {
				self::assertContains($slug, $slugs, $property . ' names a seeded ' . $link['schema']);
				$entries++;
			}
		}

		self::assertSame(5, $entries);
	}//end testEveryLinkNamesASeededObjectOfItsKind()

	/**
	 * The profile's object count includes the theme and its four new objects.
	 *
	 * @return void
	 */
	public function testTheProfileCountsEveryObject(): void {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		$count = 0;
		foreach ($doc['x-openregister']['seedData']['objects'] as $objects) {
			$count += count($objects);
		}

		$declared = null;
		array_walk_recursive(
			$doc,
			static function ($value, $key) use (&$declared): void {
				if ($key === 'objectCount' && $declared === null) {
					$declared = $value;
				}
			}
		);
		self::assertSame($count, $declared);
	}//end testTheProfileCountsEveryObject()
}//end class
