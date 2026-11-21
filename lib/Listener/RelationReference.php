<?php

/**
 * Reads the id a relation value holds.
 *
 * A stored relation is a uuid string, or an expanded object carrying `id` or
 * `uuid`. Listeners that follow a relation read it the same way.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

/**
 * The id of a relation value.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class RelationReference {

	/**
	 * A relation value (a uuid or `{id}` / `{uuid}`) as a uuid, or null.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function idOf(mixed $value): ?string {
		$id = $value;
		if (is_array($value) === true) {
			$id = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end idOf()
}//end class
