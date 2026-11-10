<?php

/**
 * Flat views of a patchObject() call.
 *
 * openregister #9096 moves the object id, data, register, schema and acting
 * user of ObjectService::patchObject() into one ObjectPatch. These helpers
 * spread a call back into the old parameter list, so doubles and recordings
 * written for it keep describing the write, not the call shape.
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

use OCA\OpenRegister\Service\Object\ObjectPatch;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a patchObject() call.
 */
final class PatchCallShape {

	/**
	 * The call as objectId, data, register, schema, rbac, multitenancy, currentUser.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function patch(array $args): array {
		/** @var ObjectPatch $patch */
		$patch = $args[0];

		return [
			$patch->objectId,
			$patch->data,
			$patch->target->register,
			$patch->target->schema,
			($args[1] ?? true),
			($args[2] ?? true),
			$patch->currentUser,
		];
	}//end patch()

	/**
	 * Adapt a patchObject() double written for the old parameter list.
	 *
	 * @param callable $callback The callback written for the old parameter list.
	 *
	 * @return \Closure The callback for the new shape.
	 */
	public static function legacy(callable $callback): \Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::patch(args: $args));
	}//end legacy()

	/**
	 * The `with()` constraints for a patchObject() call, given in the old parameter order.
	 *
	 * @param mixed ...$flat ObjectId, data, register, schema, rbac, multitenancy; a value may be a constraint.
	 *
	 * @return array<int, Constraint> One constraint per patchObject() parameter.
	 */
	public static function patchArgs(mixed ...$flat): array {
		$constraints = [
			new Callback(
				static function (ObjectPatch $patch) use ($flat): bool {
					$actual = [$patch->objectId, $patch->data, $patch->target->register, $patch->target->schema];
					foreach (array_slice($flat, 0, 4) as $index => $expected) {
						$constraint = ($expected instanceof Constraint) ? $expected : new IsEqual($expected);
						if ($constraint->evaluate($actual[$index], '', true) !== true) {
							return false;
						}
					}

					return true;
				}
			),
		];
		foreach ([4, 5] as $index) {
			if (array_key_exists($index, $flat) === true) {
				$constraints[] = ($flat[$index] instanceof Constraint) ? $flat[$index] : new IsEqual($flat[$index]);
			}
		}

		return $constraints;
	}//end patchArgs()

	/**
	 * Expand a call recorded by parameter name into the old names.
	 *
	 * @param array<string, mixed> $named The arguments keyed by parameter name.
	 *
	 * @return array<string, mixed> The arguments keyed by the old parameter names.
	 */
	public static function legacyNamed(array $named): array {
		$patch = ($named['patch'] ?? null);
		if (($patch instanceof ObjectPatch) === false) {
			return $named;
		}

		$legacy = ['objectId' => $patch->objectId, 'data' => $patch->data];
		if ($patch->target->register !== null) {
			$legacy['register'] = $patch->target->register;
		}

		if ($patch->target->schema !== null) {
			$legacy['schema'] = $patch->target->schema;
		}

		foreach (['_rbac', '_multitenancy'] as $flag) {
			if (array_key_exists($flag, $named) === true) {
				$legacy[$flag] = $named[$flag];
			}
		}

		if ($patch->currentUser !== null) {
			$legacy['currentUser'] = $patch->currentUser;
		}

		return $legacy;
	}//end legacyNamed()
}//end class
