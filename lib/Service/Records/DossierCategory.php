<?php

/**
 * Decidiq Dossier Category
 *
 * Sets the Selectielijst category of one archival dossier when it differs
 * from its schema's. The category itself is decided in one place,
 * SelectionCategoryReader::forObject(); this class only writes the override
 * an archivist chose, on the property the dossier schema names for it.
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

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;

/**
 * An archivist's per-dossier Selectielijst category.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
 */
class DossierCategory {
	/**
	 * The dossier schema's key in the register, where its category lives.
	 */
	public const SCHEMA_KEY = 'ArchivalDossier';

	private const SCHEMA = 'archival-dossier';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService OpenRegister's object facade
	 * @param SelectionCategoryReader $categories    The one place a dossier's category is decided
	 * @param ArchivistGuard          $guard         Archivist or administrator
	 * @param IL10N                   $l10n          Translations
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SelectionCategoryReader $categories,
		private readonly ArchivistGuard $guard,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Set a dossier's category. A category that differs from the schema's is
	 * written on the dossier; the schema's own category removes the override.
	 *
	 * @param string $dossierId The dossier
	 * @param string $category  A category the register ships a Selectielijst row for
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier does not exist
	 * @throws AccessDeniedException   When the caller is not an archivist or administrator
	 * @throws DossierRefusedException When the category is unknown, the schema names no
	 *                                 override property, or the dossier is already on a list
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	public function set(string $dossierId, string $category): array {
		$this->guard->requireArchivist();
		$dossier = $this->dossier(dossierId: $dossierId);
		if (isset($dossier['transferList']) === true || isset($dossier['destructionList']) === true) {
			throw new DossierRefusedException(
				message: $this->l10n->t('The dossier is already on a list in OpenRegister, so its category is fixed.'),
				reason: DossierRefusedException::ALREADY_PROPOSED
			);
		}

		$property = $this->categories->overrideProperty(schemaKey: self::SCHEMA_KEY);
		if ($property === null || $this->categories->isKnown(category: $category) === false) {
			throw new DossierRefusedException(
				message: $this->l10n->t('The register ships no Selectielijst category %1$s for dossiers.', [$category]),
				reason: DossierRefusedException::NO_CATEGORY
			);
		}

		unset($dossier[$property]);
		if ($category !== ($this->categories->forSchema(schemaKey: self::SCHEMA_KEY)['category'] ?? null)) {
			$dossier[$property] = $category;
		}

		$saved = $this->objectService->saveObject(
			object: $dossier,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $dossierId,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end set()

	/**
	 * Read the dossier in system context.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MissingObjectException When the dossier does not exist
	 */
	private function dossier(string $dossierId): array {
		$entity = null;
		if ($dossierId !== '') {
			$entity = $this->objectService->find(id: $dossierId, register: 'decidiq', schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		}

		if ($entity === null) {
			throw new MissingObjectException(message: 'Dossier not found.');
		}

		$dossier = $entity->jsonSerialize();
		unset($dossier['id'], $dossier['@self']);
		return $dossier;
	}//end dossier()
}//end class
