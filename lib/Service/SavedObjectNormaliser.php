<?php

/**
 * Decidesk Saved Object Normaliser
 *
 * Normalises the return value of OpenRegister's ObjectService::saveObject() to
 * a plain array so that callers declaring an `: array` return type do not raise
 * a TypeError on the ObjectEntity that saveObject() actually returns.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

/**
 * Array normalisation for OpenRegister save results.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class SavedObjectNormaliser {
	/**
	 * Normalise the result of ObjectService::saveObject() to an array.
	 *
	 * Falls back to the original payload when the saved value is neither an
	 * ObjectEntity nor an array.
	 *
	 * @param mixed $saved The value returned by saveObject()
	 * @param array<string, mixed> $fallback The original object payload
	 *
	 * @return array<string, mixed> The persisted object as an array
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function toArray(mixed $saved, array $fallback): array {
		if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
			return $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $fallback;
	}//end toArray()
}//end class
