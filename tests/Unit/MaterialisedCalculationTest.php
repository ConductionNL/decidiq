<?php

/**
 * Materialised-calculation conformance for the decidiq registers.
 *
 * A `materialise: true` calculation is PERSISTED on save, not computed on read.
 * `CalculationOnSaveListener` patches the value into the payload before
 * persistence and `RenderObject::applyVirtualCalculations()` deliberately skips
 * it. The value therefore has to land in a column, and
 * `MagicMapper::prepareObjectDataForTable()` is a whitelist by omission over the
 * schema's DECLARED properties: it copies out the properties the schema
 * declares and never reads anything else, with no JSON blob column to fall back
 * on. A materialised calculation the schema does not declare as a property is
 * computed correctly, written onto the entity, and then thrown away at the
 * database boundary, with a warning in nextcloud.log and a 200 on the wire.
 *
 * That is how `Decision.routeComplete` was discarded 60 times in one install,
 * and how `Meeting.quorumWith` left MeetingTransitionGuard reading an absent
 * value as false on every meeting.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts every materialised calculation can actually be stored and read.
 *
 * @coversNothing
 */
class MaterialisedCalculationTest extends TestCase {

	/**
	 * The register files whose schemas OpenRegister imports.
	 *
	 * @var array<int, string>
	 */
	private const REGISTERS = [
		'decidesk_register.json',
		'decidiq_mock_register.json',
	];

	/**
	 * Load every schema in every shipped register, keyed by file and name.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function schemas(): array {
		$out = [];
		foreach (self::REGISTERS as $file) {
			$path = __DIR__ . '/../../lib/Settings/' . $file;
			$data = json_decode(file_get_contents($path), true);
			$this->assertIsArray(actual: $data, message: $file . ' must parse as JSON');

			foreach (($data['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === true) {
					$out[] = [$file, (string)$name, $schema];
				}
			}
		}

		$this->assertNotEmpty(actual: $out, message: 'the registers must carry schemas');

		return $out;
	}//end schemas()

	/**
	 * Every materialised calculation is declared as a property of its schema.
	 *
	 * @return void
	 */
	public function testMaterialisedCalculationsAreDeclaredProperties(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			$properties = ($schema['properties'] ?? []);
			foreach (($schema['x-openregister-calculations'] ?? []) as $calc => $spec) {
				if (is_array($spec) === false || ($spec['materialise'] ?? false) !== true) {
					continue;
				}

				if (array_key_exists((string)$calc, $properties) === false) {
					$findings[] = $file . ' / ' . $name . ': calculation "' . $calc
						. '" is materialise: true but the schema declares no such property,'
						. ' so every write discards it';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "A materialised calculation is persisted on save, and only a DECLARED\n"
			. "property gets a column to be persisted into. Declare it, or make the\n"
			. "calculation virtual (materialise: false) so it is computed on read.\n\n"
			. implode("\n", $findings)
		);

	}//end testMaterialisedCalculationsAreDeclaredProperties()

	/**
	 * Calculations read aggregate counts through `@aggregate`, never bare.
	 *
	 * `CalculationPayloadBuilder::build()` resolves `x-openregister-aggregate-refs`
	 * and injects each result under `@aggregate.<name>`. It does NOT read
	 * `x-openregister-aggregations`, which is the reporting annotation
	 * `AggregationController` serves. A calculation naming an aggregation as a
	 * bare `prop` therefore resolves to null, and the surrounding comparison
	 * quietly answers false rather than throwing: `routeComplete` came out false
	 * on every decision whatever its route, and `quorumPercentage` came out 0.
	 *
	 * @return void
	 */
	public function testCalculationsReadAggregatesThroughTheAggregatePrefix(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			$aggregations = array_keys(($schema['x-openregister-aggregations'] ?? []));
			$refs = array_keys(($schema['x-openregister-aggregate-refs'] ?? []));
			if ($aggregations === []) {
				continue;
			}

			foreach ($this->propTokens(node: ($schema['x-openregister-calculations'] ?? [])) as $token) {
				if (in_array($token, $aggregations, true) === true) {
					$findings[] = $file . ' / ' . $name . ': calculation reads "' . $token
						. '" as a bare prop, which resolves to null. Declare it under'
						. ' x-openregister-aggregate-refs and read it as "@aggregate.' . $token . '"';
					continue;
				}

				if (str_starts_with($token, '@aggregate.') === false) {
					continue;
				}

				$referenced = explode('.', substr($token, strlen('@aggregate.')))[0];
				if (in_array($referenced, $refs, true) === false) {
					$findings[] = $file . ' / ' . $name . ': calculation reads "' . $token
						. '" but x-openregister-aggregate-refs declares no "' . $referenced . '"';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "Calculations must read aggregate counts through @aggregate, backed by an\n"
			. "x-openregister-aggregate-refs declaration. A bare aggregation name is null.\n\n"
			. implode("\n", $findings)
		);

	}//end testCalculationsReadAggregatesThroughTheAggregatePrefix()

	/**
	 * An aggregate-reference filters with `filters`, not `filter`.
	 *
	 * `AggregateReferenceResolver::resolveOne()` reads `$spec['filters']`, while
	 * the reporting annotation next door uses `filter`. A block copied across
	 * without the rename resolves unfiltered and counts every row in the schema.
	 *
	 * @return void
	 */
	public function testAggregateReferencesUseTheFiltersKey(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			foreach (($schema['x-openregister-aggregate-refs'] ?? []) as $ref => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				if (array_key_exists('filter', $spec) === true) {
					$findings[] = $file . ' / ' . $name . ': aggregate-reference "' . $ref
						. '" uses "filter"; the resolver reads "filters"';
				}
			}
		}

		$this->assertSame(expected: [], actual: $findings, message: implode("\n", $findings));

	}//end testAggregateReferencesUseTheFiltersKey()

	/**
	 * Collect every `prop` token used anywhere in a calculations block.
	 *
	 * @param mixed $node The calculations block, or any node inside it.
	 *
	 * @return array<int, string> The prop tokens, in encounter order.
	 */
	private function propTokens(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$tokens = [];
		foreach ($node as $key => $value) {
			if ($key === 'prop') {
				if (is_string($value) === true) {
					$tokens[] = $value;
				} elseif (is_array($value) === true && is_string(($value[0] ?? null)) === true) {
					$tokens[] = $value[0];
				}

				continue;
			}

			$tokens = array_merge($tokens, $this->propTokens(node: $value));
		}

		return $tokens;
	}//end propTokens()
}//end class
