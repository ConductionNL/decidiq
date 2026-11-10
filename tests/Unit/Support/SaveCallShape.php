<?php

/**
 * Flat views of a saveObject() call.
 *
 * openregister #9123 moves everything but the object and the two access
 * flags of ObjectService::saveObject() into one ObjectSaveOptions. These
 * helpers spread a call back into the old parameter list, so doubles and
 * recordings written for it keep describing the write, not the call shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a saveObject() call.
 */
final class SaveCallShape {

	/**
	 * The old parameter names, in the old order.
	 */
	public const NAMES = [
		'object',
		'extend',
		'register',
		'schema',
		'uuid',
		'_rbac',
		'_multitenancy',
		'silent',
		'_validation',
		'uploadedFiles',
		'currentUser',
		'failIfExists',
	];

	/**
	 * The call as the old positional list.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function save(array $args): array {
		$options = ($args[1] ?? null) ?? new ObjectSaveOptions();

		return [
			$args[0],
			$options->extend,
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
		];
	}//end save()

	/**
	 * Adapt a saveObject() double written for the old parameter list.
	 *
	 * @param callable $callback The callback written for the old parameter list.
	 *
	 * @return \Closure The callback for the new shape.
	 */
	public static function legacy(callable $callback): \Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::save(args: $args));
	}//end legacy()

	/**
	 * The call keyed by the old parameter names.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array<string, mixed> The arguments by old name.
	 */
	public static function byName(array $args): array {
		return array_combine(self::NAMES, self::save(args: $args));
	}//end byName()

	/**
	 * The `with()` constraints for a saveObject() call, given in the old parameter order.
	 *
	 * An omitted trailing value is not checked, as with `with()`; a value may be a constraint.
	 *
	 * @param mixed ...$flat The old positional values.
	 *
	 * @return array<int, Constraint> One constraint per saveObject() parameter.
	 */
	public static function saveArgs(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 12, new IsAnything()));
		$as   = static fn (mixed $value): Constraint => ($value instanceof Constraint) ? $value : new IsEqual($value);

		return [
			$as($flat[0]),
			new Callback(
				static function (?ObjectSaveOptions $options) use ($flat, $as): bool {
					$actual = self::save(args: [null, $options]);
					foreach ([1, 2, 3, 4, 7, 8, 9, 10, 11] as $index) {
						if ($as($flat[$index])->evaluate($actual[$index], '', true) !== true) {
							return false;
						}
					}

					return true;
				}
			),
			$as($flat[5]),
			$as($flat[6]),
		];
	}//end saveArgs()

	/**
	 * Expand a call recorded by parameter name into the old names, defaults left out.
	 *
	 * @param array<string, mixed> $named The arguments keyed by parameter name.
	 *
	 * @return array<string, mixed> The arguments keyed by the old parameter names.
	 */
	public static function legacyNamed(array $named): array {
		if (array_key_exists('object', $named) === false) {
			return $named;
		}

		$defaults = self::save(args: [null]);
		$flat     = self::save(args: [$named['object'], ($named['options'] ?? null), ($named['_rbac'] ?? true), ($named['_multitenancy'] ?? true)]);
		$legacy   = [];
		foreach (self::NAMES as $index => $name) {
			if ($index === 0 || $flat[$index] !== $defaults[$index]) {
				$legacy[$name] = $flat[$index];
			}
		}

		return $legacy;
	}//end legacyNamed()
}//end class
