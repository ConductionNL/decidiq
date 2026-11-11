<?php

/**
 * Decidiq Reference Id
 *
 * Reads the id out of a stored object reference: a string is the id itself, an
 * array carries it under `id` or `uuid`, anything else has none.
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
 * Id of a stored object reference.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class ReferenceId {
	/**
	 * The id a stored reference points at.
	 *
	 * @param mixed $ref A uuid string or a `{id|uuid}` reference
	 *
	 * @return string The id, or '' when the reference carries none
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function of(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === true) {
			return (string)($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return '';
	}//end of()
}//end class
