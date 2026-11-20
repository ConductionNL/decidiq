<?php

/**
 * Refuses a record that leaves a group of fields wholly empty, where at least
 * one field of the group must be filled.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * "At least one of these fields" per group, which a JSON schema `required`
 * list cannot say and OpenRegister's `anyOf` (schema composition by
 * reference) does not either.
 *
 * A field counts as filled when it holds a non-blank string, a number, a
 * boolean, or a non-empty array (a reference object or a list).
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema
 */
class AnyOfFieldsGuard {

	/**
	 * The labels of the groups that have no filled field, in group order.
	 *
	 * @param array<string, mixed>        $row    The record.
	 * @param array<string, list<string>> $groups Group label to the fields of which one must be filled.
	 *
	 * @return list<string> The labels of the empty groups; empty when every group has a filled field.
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema
	 */
	public function emptyGroups(array $row, array $groups): array {
		$empty = [];
		foreach ($groups as $label => $fields) {
			if ($this->anyFilled(row: $row, fields: $fields) === false) {
				$empty[] = (string)$label;
			}
		}

		return $empty;
	}//end emptyGroups()

	/**
	 * Whether any of the fields holds a value.
	 *
	 * @param array<string, mixed> $row    The record.
	 * @param list<string>         $fields The fields.
	 *
	 * @return bool
	 */
	private function anyFilled(array $row, array $fields): bool {
		foreach ($fields as $field) {
			if ($this->isFilled(value: ($row[$field] ?? null)) === true) {
				return true;
			}
		}

		return false;
	}//end anyFilled()

	/**
	 * Whether one value counts as filled.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isFilled(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return is_int($value) === true || is_float($value) === true || is_bool($value) === true;
	}//end isFilled()
}//end class
