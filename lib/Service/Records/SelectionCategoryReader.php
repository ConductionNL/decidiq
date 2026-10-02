<?php

/**
 * Decidiq Selection Category Reader
 *
 * Reads the Selectielijst category of the archival dossier from the one place
 * it is declared: the dossier schema's `archive.classification` in the merged
 * register, matched against the rows the register ships under
 * `components.selectionLists`. OpenRegister reads the same two keys when it
 * resolves a dossier's retention, so decidiq never keeps a second copy.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use OCA\Decidiq\Service\SettingsService;

/**
 * The Selectielijst category of a schema, as the merged register declares it.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
 */
class SelectionCategoryReader {
	/**
	 * Keep and transfer to the archive.
	 */
	public const KEEP = 'bewaren';

	/**
	 * Destroy when the retention period has passed.
	 */
	public const DESTROY = 'vernietigen';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The merged register
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The category a schema is archived under, with its Selectielijst row.
	 *
	 * @param string $schemaKey The schema's key in the register, for example ArchivalDossier
	 *
	 * @return array{category: string, action: string, retentionYears: int|null, description: string}|null
	 *         Null when the schema names no category, or a category the register ships no row for
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function forSchema(string $schemaKey): ?array {
		$config = $this->settings->mergedRegisterConfig();
		$archive = ($config['components']['schemas'][$schemaKey]['archive'] ?? []);
		if (is_array($archive) === false || ($archive['enabled'] ?? false) !== true) {
			return null;
		}

		$category = (string)($archive['classification'] ?? '');
		if ($category === '') {
			return null;
		}

		return $this->row(rows: ($config['components']['selectionLists'] ?? []), category: $category);
	}//end forSchema()

	/**
	 * The Selectielijst row of a category, or null when the register ships none.
	 *
	 * @param mixed  $rows     The register's selectionLists
	 * @param string $category The category
	 *
	 * @return array{category: string, action: string, retentionYears: int|null, description: string}|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	private function row(mixed $rows, string $category): ?array {
		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['category'] ?? '') !== $category) {
				continue;
			}

			$action = (string)($row['action'] ?? '');
			if (in_array($action, [self::KEEP, self::DESTROY], true) === false) {
				return null;
			}

			$years = null;
			if (is_int($row['retentionYears'] ?? null) === true) {
				$years = $row['retentionYears'];
			}

			return [
				'category' => $category,
				'action' => $action,
				'retentionYears' => $years,
				'description' => (string)($row['description'] ?? ''),
			];
		}

		return null;
	}//end row()
}//end class
