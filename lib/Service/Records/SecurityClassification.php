<?php

/**
 * Decidiq Security Classification
 *
 * decidiq's four security labels and how they map onto OpenRegister's
 * confidentiality ordinal (ZaaktypeAuthorizationService::
 * VERTROUWELIJKHEIDAANDUIDING_LEVELS). The labels are a strict subset of that
 * ordinal in the same relative order, so "more restrictive" means the same
 * thing in both apps:
 *
 * | decidiq       | OpenRegister level | position |
 * |---------------|--------------------|----------|
 * | openbaar      | openbaar           | 1        |
 * | intern        | intern             | 3        |
 * | vertrouwelijk | vertrouwelijk      | 5        |
 * | geheim        | geheim             | 7        |
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

/**
 * The security labels, least restrictive first.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
 */
final class SecurityClassification {
	/**
	 * The labels, least restrictive first. The register fragment's enum.
	 */
	public const LABELS = ['openbaar', 'intern', 'vertrouwelijk', 'geheim'];

	/**
	 * Each label's level in OpenRegister's ordinal.
	 */
	private const OPENREGISTER_LEVELS = [
		'openbaar' => 'openbaar',
		'intern' => 'intern',
		'vertrouwelijk' => 'vertrouwelijk',
		'geheim' => 'geheim',
	];

	/**
	 * A label as one of the four; an absent or unknown label is public, the
	 * register's default.
	 *
	 * @param string|null $label The label
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
	 */
	public static function normalise(?string $label): string {
		$label = strtolower(trim((string)$label));
		if (in_array($label, self::LABELS, true) === true) {
			return $label;
		}

		return 'openbaar';
	}//end normalise()

	/**
	 * The label's level in OpenRegister's confidentiality ordinal.
	 *
	 * @param string|null $label The label
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
	 */
	public static function openRegisterLevel(?string $label): string {
		return self::OPENREGISTER_LEVELS[self::normalise(label: $label)];
	}//end openRegisterLevel()

	/**
	 * Whether one label is more restrictive than another.
	 *
	 * @param string|null $label The label
	 * @param string|null $than  The label it is compared with
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
	 */
	public static function isMoreRestrictive(?string $label, ?string $than): bool {
		return self::rank(label: $label) > self::rank(label: $than);
	}//end isMoreRestrictive()

	/**
	 * Whether a record with this label may be published: public only.
	 *
	 * @param string|null $label The label
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
	 */
	public static function isPublishable(?string $label): bool {
		return self::rank(label: $label) === 0;
	}//end isPublishable()

	/**
	 * The label's position, 0 for public.
	 *
	 * @param string|null $label The label
	 *
	 * @return int
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
	 */
	private static function rank(?string $label): int {
		return (int)array_search(self::normalise(label: $label), self::LABELS, true);
	}//end rank()
}//end class
