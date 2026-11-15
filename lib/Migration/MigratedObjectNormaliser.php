<?php

/**
 * Decidiq Migrated Object Normaliser
 *
 * Normalises an OpenRegister find/findAll result read by a repair-step
 * migration to a plain array, or null when the value carries no array. The
 * first accessor an object offers decides: jsonSerialize() when present,
 * otherwise getObject(); an array passes through.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Migration;

/**
 * Nullable array normalisation for the objects a migration reads.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class MigratedObjectNormaliser {
	/**
	 * Normalise an OR find/findAll result into a plain array.
	 *
	 * @param mixed $entity An ObjectEntity, array, or null.
	 *
	 * @return array<string,mixed>|null The object array, or null when unusable.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function toArray(mixed $entity): ?array {
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			return $this->arrayOrNull(value: $entity->jsonSerialize());
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			return $this->arrayOrNull(value: $entity->getObject());
		}

		return $this->arrayOrNull(value: $entity);
	}//end toArray()

	/**
	 * The value when it is an array, otherwise null.
	 *
	 * @param mixed $value The value to check.
	 *
	 * @return array<string,mixed>|null The array, or null.
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()
}//end class
