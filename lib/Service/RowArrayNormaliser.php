<?php

/**
 * Decidiq Row Array Normaliser
 *
 * Normalises one OpenRegister ObjectService row to an array: a serialisable
 * object is serialised, an array passes through, anything else is empty.
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
 * Array normalisation for one OpenRegister row.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class RowArrayNormaliser {
	/**
	 * Normalise an ObjectEntity or array to a plain array.
	 *
	 * @param mixed $row ObjectEntity or array
	 *
	 * @return array<string, mixed> The row as an array, or [] when it is neither
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()
}//end class
