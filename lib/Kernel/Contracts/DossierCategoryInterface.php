<?php

/**
 * Contract of DossierCategory
 *
 * The public surface of DossierCategory that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of DossierCategory.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierCategoryInterface {

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
	public function set(string $dossierId, string $category): array;
}//end interface
