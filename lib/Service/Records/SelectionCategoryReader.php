<?php

/**
 * Decidiq Selection Category Reader
 *
 * Reads the Selectielijst category of an archival dossier: the dossier's own
 * category when the schema's archive block points at a property for it
 * (`classificationProperty`) and the dossier sets one, otherwise the schema's
 * `archive.classification`, each matched against the rows the register ships
 * under `components.selectionLists`. OpenRegister reads the same keys when it
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
	 * The archive block key that names the property holding an object's own
	 * category: the pointer OpenRegister's per-object override reads
	 * (openregister#4228 follow-up; provisional until OpenRegister lands it).
	 */
	public const POINTER = 'classificationProperty';

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
	 * The category one object is archived under: its own category when its
	 * schema points at one (`archive.classificationProperty`) and the register
	 * ships a row for it, otherwise the schema's. This is the one place decidiq
	 * decides a dossier's category; OpenRegister reads the same pointer.
	 *
	 * @param string               $schemaKey The schema's key in the register, for example ArchivalDossier
	 * @param array<string, mixed> $object    The object
	 *
	 * @return array{category: string, action: string, retentionYears: int|null, description: string, overridden: bool}|null
	 *         Null when neither the object nor its schema names a category with a row
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function forObject(string $schemaKey, array $object): ?array {
		$schemaRow = $this->forSchema(schemaKey: $schemaKey);
		$property = $this->overrideProperty(schemaKey: $schemaKey);
		$own = '';
		if ($property !== null && is_string($object[$property] ?? null) === true) {
			$own = $object[$property];
		}

		$ownRow = null;
		if ($own !== '' && $own !== ($schemaRow['category'] ?? null)) {
			$ownRow = $this->row(rows: ($this->settings->mergedRegisterConfig()['components']['selectionLists'] ?? []), category: $own);
		}

		if ($ownRow !== null) {
			return $ownRow + ['overridden' => true];
		}

		if ($schemaRow === null) {
			return null;
		}

		return $schemaRow + ['overridden' => false];
	}//end forObject()

	/**
	 * The property a schema names for an object's own category, or null when it names none.
	 *
	 * @param string $schemaKey The schema's key in the register
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function overrideProperty(string $schemaKey): ?string {
		$archive = ($this->settings->mergedRegisterConfig()['components']['schemas'][$schemaKey]['archive'] ?? []);
		$property = null;
		if (is_array($archive) === true) {
			$property = ($archive[self::POINTER] ?? null);
		}

		if (is_string($property) === false || $property === '') {
			return null;
		}

		return $property;
	}//end overrideProperty()

	/**
	 * Whether the register ships a usable Selectielijst row for a category.
	 *
	 * @param string $category The category
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function isKnown(string $category): bool {
		return $this->row(rows: ($this->settings->mergedRegisterConfig()['components']['selectionLists'] ?? []), category: $category) !== null;
	}//end isKnown()

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
