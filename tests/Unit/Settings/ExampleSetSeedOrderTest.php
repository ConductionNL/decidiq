<?php

/**
 * OpenRegister imports a profile's seed objects schema by schema, in the order
 * the profile lists them, and resolves a slug in a reference property only
 * against objects already imported. A reference to a schema listed later is
 * kept as the slug, so the widget reading it as an id finds nothing. Every
 * profile therefore lists a referenced schema before the schemas pointing at it,
 * except inside the decision, decision-stage and voting-round cycle.
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
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class ExampleSetSeedOrderTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Forward references no order can avoid: these schemas point at each other.
	 */
	private const CYCLE = ['decision', 'decision-stage', 'voting-round'];

	/**
	 * Each schema's merged properties, keyed by slug, and every name a `$ref` may use for it.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
	 */
	private static function schemas(): array {
		$files = array_merge([self::SETTINGS . '/decidesk_register.json'], glob(self::SETTINGS . '/register.d/*.json'));
		sort($files);
		$properties = [];
		$aliases    = [];
		foreach ($files as $file) {
			$register = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($register['components']['schemas'] ?? []) as $name => $schema) {
				$slug = (string)($schema['slug'] ?? strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', (string)$name)));
				$aliases[(string)$name]             = $slug;
				$aliases[strtolower((string)$name)] = $slug;
				$aliases[$slug]                     = $slug;
				$properties[$slug]                  = array_merge(($properties[$slug] ?? []), ($schema['properties'] ?? []));
			}
		}

		return [$properties, $aliases];
	}//end schemas()

	/**
	 * The schema a property refers to, or null.
	 *
	 * @param mixed                 $property The property definition.
	 * @param array<string, string> $aliases  Names to slugs.
	 *
	 * @return string|null
	 */
	private static function target(mixed $property, array $aliases): ?string {
		if (is_array($property) === false) {
			return null;
		}

		$ref = $property['$ref'] ?? null;
		if (($property['type'] ?? null) === 'array') {
			$ref = $property['items']['$ref'] ?? null;
		}

		if (is_string($ref) === false) {
			return null;
		}

		$segments = explode('/', rtrim($ref, '/#'));
		$name     = (string)end($segments);

		return $aliases[$name] ?? $aliases[strtolower($name)] ?? null;
	}//end target()

	/**
	 * Whether any seed object writes a slug (not a uuid) into the property.
	 *
	 * @param list<array<string, mixed>> $objects The seed objects.
	 * @param string                     $name    The property.
	 *
	 * @return bool
	 */
	private static function holdsSlug(array $objects, string $name): bool {
		foreach ($objects as $object) {
			$values = $object[$name] ?? null;
			if (is_array($values) === false) {
				$values = [$values];
			}

			foreach ($values as $item) {
				if (is_string($item) === true && trim($item) !== '' && preg_match(self::UUID, $item) !== 1) {
					return true;
				}
			}
		}

		return false;
	}//end holdsSlug()

	/**
	 * The `schema -> referenced schema` pairs where the referenced schema is listed later.
	 *
	 * @param string $file The profile file.
	 *
	 * @return list<string>
	 */
	private static function forwardReferences(string $file): array {
		[$properties, $aliases] = self::schemas();
		$profile  = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$seeds    = $profile['x-openregister']['seedData']['objects'];
		$position = array_flip(array_keys($seeds));
		$forward  = [];
		foreach ($seeds as $slug => $objects) {
			foreach (($properties[$slug] ?? []) as $name => $property) {
				$target = self::target(property: $property, aliases: $aliases);
				if ($target === null || $target === $slug || isset($position[$target]) === false) {
					continue;
				}

				$later  = $position[$target] > $position[$slug];
				$cyclic = in_array($slug, self::CYCLE, true) === true && in_array($target, self::CYCLE, true) === true;
				if ($later === true && $cyclic === false && self::holdsSlug(objects: $objects, name: (string)$name) === true) {
					$forward[] = $slug . '.' . $name . ' -> ' . $target;
				}
			}
		}

		return $forward;
	}//end forwardReferences()

	/**
	 * The profiles to check.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function profiles(): array {
		$cases = [];
		foreach (glob(self::SETTINGS . '/profiles/*.json') as $file) {
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}//end profiles()

	/**
	 * A profile lists every referenced schema before the schemas that point at it.
	 *
	 * @param string $file The profile file.
	 *
	 * @dataProvider profiles
	 *
	 * @return void
	 */
	public function testReferencedSchemasAreSeededFirst(string $file): void {
		self::assertSame(expected: [], actual: self::forwardReferences(file: $file));
	}//end testReferencedSchemasAreSeededFirst()
}//end class
