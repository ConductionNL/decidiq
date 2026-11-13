<?php

/**
 * Decidiq Row List Normaliser
 *
 * Normalises the heterogeneous output of OpenRegister's ObjectService::findAll()
 * to a list of plain row arrays: serialisable objects are serialised, arrays
 * are kept, anything else drops out.
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
 * List normalisation for OpenRegister findAll() results.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class RowListNormaliser {
	/**
	 * Normalise heterogeneous findAll() output to plain arrays.
	 *
	 * @param mixed $rows Raw findAll() result
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function rows(mixed $rows): array {
		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end rows()
}//end class
