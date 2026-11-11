<?php

/**
 * Decidiq Entity Payload Reader
 *
 * Reads the stored payload of the OpenRegister object entity an object event
 * carries: getObject() first, jsonSerialize() when that is empty.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

/**
 * Payload of an OpenRegister object entity.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
final class EntityPayloadReader {
	/**
	 * Extract the stored payload from an OR object entity.
	 *
	 * @param object $entity OR object entity
	 *
	 * @return array<string, mixed> The payload, or [] when the entity yields none
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function payloadOf(object $entity): array {
		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
			$row = (array)$entity->jsonSerialize();
		}

		return $row;
	}//end payloadOf()
}//end class
