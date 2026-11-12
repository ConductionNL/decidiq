<?php

/**
 * Decidiq Object Result Normaliser
 *
 * Normalises the result of OpenRegister's ObjectService find/findAll/saveObject
 * to a plain array, or null when the value carries no array: an array passes
 * through, an object answers through jsonSerialize() and, when that is not an
 * array, through getObject().
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

/**
 * Nullable array normalisation for OpenRegister object results.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class ObjectResultNormaliser {
	/**
	 * Normalise an ObjectEntity, array or null to an array.
	 *
	 * @param mixed $entity An ObjectEntity, array, or null
	 *
	 * @return array<string, mixed>|null The object array, or null when unusable
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function toArray(mixed $entity): ?array {
		if (is_array($entity) === true) {
			return $entity;
		}

		$serialized = $this->serialized(entity: $entity);
		if ($serialized !== null) {
			return $serialized;
		}

		return $this->objectOf(entity: $entity);
	}//end toArray()

	/**
	 * The jsonSerialize() result of an object, when it is an array.
	 *
	 * @param mixed $entity The value to read
	 *
	 * @return array<string, mixed>|null The serialised array, or null
	 */
	private function serialized(mixed $entity): ?array {
		if (is_object($entity) === false || method_exists($entity, 'jsonSerialize') === false) {
			return null;
		}

		$serialized = $entity->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end serialized()

	/**
	 * The getObject() result of an object, when it is an array.
	 *
	 * @param mixed $entity The value to read
	 *
	 * @return array<string, mixed>|null The object array, or null
	 */
	private function objectOf(mixed $entity): ?array {
		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return null;
		}

		$object = $entity->getObject();
		if (is_array($object) === true) {
			return $object;
		}

		return null;
	}//end objectOf()
}//end class
