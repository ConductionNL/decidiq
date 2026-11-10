<?php

// Copied from openregister tests/Unit/Support/FindCallShape.php (openregister #9116),
// namespace moved to decidiq's test tree; legacy() and legacyNamed() are decidiq's own.

/**
 * OpenRegister FindCallShape
 *
 * Spreads the arguments a find() or findSilent() double receives into the
 * flat parameter list the recordings of the characterisation tests hold, so
 * a recording keeps describing the read, not the call shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a find() / findSilent() call.
 */
final class FindCallShape {

	/**
	 * The find() arguments as id, extend, files, register, schema, rbac, multitenancy, render, audit.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function find(array $args): array {
		$options = self::options(args: $args);

		return [
			$args[0],
			$options->extend,
			$options->files,
			$options->register,
			$options->schema,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->render,
			$options->audit,
		];
	}//end find()

	/**
	 * The findSilent() arguments as id, extend, files, register, schema, rbac, multitenancy.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function findSilent(array $args): array {
		return array_slice(self::find(args: $args), 0, 7);
	}//end findSilent()

	/**
	 * A find() answer per call, as `willReturnMap()` rows in the flat list.
	 *
	 * @param array $rows Each row: the nine flat values, then the answer.
	 *
	 * @return \Closure The callback; null for a call no row names.
	 */
	public static function returnMap(array $rows): \Closure {
		return static function (mixed ...$args) use ($rows): mixed {
			$flat = self::find(args: $args);
			foreach ($rows as $row) {
				if (array_slice($row, 0, 9) === $flat) {
					return $row[9];
				}
			}

			return null;
		};
	}//end returnMap()

	/**
	 * The `with()` constraints for a find() call, given as the flat list.
	 *
	 * An omitted trailing value is not checked, as with `with()`; a value may be a constraint.
	 *
	 * @param mixed ...$flat Id, extend, files, register, schema, rbac, multitenancy, render, audit.
	 *
	 * @return array<int, Constraint> One constraint per find() parameter.
	 */
	public static function findArgs(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 9, new IsAnything()));

		return [
			self::constraint(expected: $flat[0]),
			new Callback(
				fn (?ObjectFindOptions $options): bool => self::allMatch(
					expected: [$flat[1], $flat[2], $flat[3], $flat[4], $flat[7], $flat[8]],
					actual: self::flatOptions(options: $options)
				)
			),
			self::constraint(expected: $flat[5]),
			self::constraint(expected: $flat[6]),
		];
	}//end findArgs()

	/**
	 * The `with()` constraints for a findSilent() call, given as the flat list.
	 *
	 * @param mixed ...$flat Id, extend, files, register, schema, rbac, multitenancy.
	 *
	 * @return array<int, Constraint> One constraint per findSilent() parameter.
	 */
	public static function findSilentArgs(mixed ...$flat): array {
		return self::findArgs(...array_slice($flat, 0, 7));
	}//end findSilentArgs()

	/**
	 * Extend, files, register, schema, render and audit of the options.
	 *
	 * @param ObjectFindOptions|null $options The options passed.
	 *
	 * @return array The six values.
	 */
	private static function flatOptions(?ObjectFindOptions $options): array {
		$options ??= new ObjectFindOptions();

		return [$options->extend, $options->files, $options->register, $options->schema, $options->render, $options->audit];
	}//end flatOptions()

	/**
	 * Whether every actual value meets its expected value or constraint.
	 *
	 * @param array $expected The expected values or constraints.
	 * @param array $actual   The actual values.
	 *
	 * @return bool True when all match.
	 */
	private static function allMatch(array $expected, array $actual): bool {
		foreach ($expected as $index => $value) {
			if (self::constraint(expected: $value)->evaluate($actual[$index], '', true) !== true) {
				return false;
			}
		}

		return true;
	}//end allMatch()

	/**
	 * The value as a constraint.
	 *
	 * @param mixed $expected A value or a constraint.
	 *
	 * @return Constraint The constraint.
	 */
	private static function constraint(mixed $expected): Constraint {
		if ($expected instanceof Constraint) {
			return $expected;
		}

		return new IsEqual($expected);
	}//end constraint()

	/**
	 * The options of the call, the defaults when it passed none.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return ObjectFindOptions The options.
	 */
	private static function options(array $args): ObjectFindOptions {
		return ($args[1] ?? null) ?? new ObjectFindOptions();
	}//end options()
	/**
	 * Adapt a find() double written for the old parameter list.
	 *
	 * The callback is called with the flat list (id, extend, files, register,
	 * schema, rbac, multitenancy, render, audit), so a closure typed for the
	 * old signature keeps working unchanged.
	 *
	 * @param callable $callback The callback written for the old parameter list.
	 *
	 * @return \Closure The callback for the new shape.
	 */
	public static function legacy(callable $callback): \Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::find(args: $args));
	}//end legacy()

	/**
	 * Expand the options of a call recorded by parameter name into the old names.
	 *
	 * Golden recordings name each find() argument after its parameter and leave
	 * out the ones at their default. Expanding `options` back into `_extend`,
	 * `files`, `register`, `schema`, `_render` and `_audit` keeps the recorded
	 * fixtures identical, which is the proof that the call itself did not change.
	 *
	 * @param array<string, mixed> $named The arguments keyed by parameter name.
	 *
	 * @return array<string, mixed> The arguments keyed by the old parameter names.
	 */
	public static function legacyNamed(array $named): array {
		if (array_key_exists('options', $named) === false && array_key_exists('id', $named) === false) {
			return $named;
		}

		$options  = ($named['options'] ?? null) ?? new ObjectFindOptions();
		$defaults = new ObjectFindOptions();
		$legacy   = [];
		if (array_key_exists('id', $named) === true) {
			$legacy['id'] = $named['id'];
		}

		foreach (['_extend' => 'extend', 'files' => 'files', 'register' => 'register', 'schema' => 'schema'] as $old => $new) {
			if ($options->{$new} !== $defaults->{$new}) {
				$legacy[$old] = $options->{$new};
			}
		}

		foreach (['_rbac', '_multitenancy'] as $flag) {
			if (array_key_exists($flag, $named) === true) {
				$legacy[$flag] = $named[$flag];
			}
		}

		foreach (['_render' => 'render', '_audit' => 'audit'] as $old => $new) {
			if ($options->{$new} !== $defaults->{$new}) {
				$legacy[$old] = $options->{$new};
			}
		}

		return $legacy;
	}//end legacyNamed()
}//end class
