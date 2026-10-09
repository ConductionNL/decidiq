<?php

/**
 * The shipped register schema for one slug, merged the way OpenRegister merges it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

/**
 * Reads decidesk_register.json plus every register.d fragment, and checks a
 * payload against the merged result.
 *
 * A fake that accepts anything lets a migration write what the real schema
 * refuses. This helper is the control: the payload a test captured is held
 * against the schema the app actually ships.
 */
final class MergedRegisterSchema {

	/**
	 * The merged schema for a slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed> The schema, empty when no fragment carries the slug.
	 */
	public static function forSlug(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files    = glob($settings . 'register.d/*.json');
		if ($files === false) {
			$files = [];
		}

		sort($files);
		$schema = [];
		foreach (array_merge([$settings . 'decidesk_register.json'], $files) as $file) {
			$doc = (array)json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $fragment) {
				if (is_array($fragment) === true && ($fragment['slug'] ?? null) === $slug) {
					$schema = self::merge(base: $schema, fragment: $fragment);
				}
			}
		}

		return $schema;

	}//end forSlug()

	/**
	 * What the shipped schema refuses in a payload.
	 *
	 * Checks undeclared properties, missing required ones, the JSON type and enum
	 * membership. Formats are not checked: a test fake hands out identifiers that
	 * are not UUIDs on purpose.
	 *
	 * @param string              $slug    The schema slug.
	 * @param array<string,mixed> $payload The payload as written.
	 *
	 * @return list<string> One line per refusal, empty when the payload fits.
	 */
	public static function violations(string $slug, array $payload): array {
		$schema = self::forSlug(slug: $slug);
		if ($schema === []) {
			return ['no schema carries the slug ' . $slug];
		}

		$properties = (array)($schema['properties'] ?? []);
		$out        = [];
		foreach (($schema['required'] ?? []) as $required) {
			if (array_key_exists($required, $payload) === false) {
				$out[] = 'missing required property ' . $required;
			}
		}

		foreach ($payload as $key => $value) {
			if (isset($properties[$key]) === false) {
				$out[] = 'undeclared property ' . $key;
				continue;
			}

			$declared = (array)$properties[$key];
			$type     = ($declared['type'] ?? null);
			if (is_string($type) === true && self::fitsType(type: $type, value: $value) === false) {
				$out[] = $key . ' is not of type ' . $type;
			}

			if (isset($declared['enum']) === true && in_array($value, (array)$declared['enum'], true) === false) {
				$out[] = $key . ' is not one of the enum values';
			}
		}

		return $out;

	}//end violations()

	/**
	 * Whether a value fits a JSON schema type.
	 *
	 * @param string $type  The declared type.
	 * @param mixed  $value The value.
	 *
	 * @return bool True when it fits.
	 */
	private static function fitsType(string $type, mixed $value): bool {
		return match ($type) {
			'string' => is_string($value),
			'number' => (is_int($value) === true || is_float($value) === true),
			'integer' => is_int($value),
			'boolean' => is_bool($value),
			'array' => (is_array($value) === true && array_is_list($value) === true),
			'object' => is_array($value),
			default => true,
		};

	}//end fitsType()

	/**
	 * Merge a fragment into a schema; a list is concatenated, a map merged.
	 *
	 * @param array<mixed> $base     The schema so far.
	 * @param array<mixed> $fragment The fragment.
	 *
	 * @return array<mixed> The merged schema.
	 */
	private static function merge(array $base, array $fragment): array {
		if (array_is_list($fragment) === true && array_is_list($base) === true) {
			return array_values(array_unique(array_merge($base, $fragment), SORT_REGULAR));
		}

		foreach ($fragment as $key => $value) {
			if (is_array($value) === true && is_array($base[$key] ?? null) === true) {
				$base[$key] = self::merge(base: $base[$key], fragment: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;

	}//end merge()
}//end class
