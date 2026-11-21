<?php

/**
 * Contract of DossierDisposition
 *
 * The public surface of DossierDisposition that other components call, so they depend
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
 * Contract of DossierDisposition.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierDispositionInterface {

	/**
	 * Where the dossier goes and whether OpenRegister can take it there now.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The id, lifecycle, route, category, action, overridden, transferAvailable,
	 *                              settingsUrl, transferList and destructionList
	 *
	 * @throws MissingObjectException When the dossier does not exist
	 * @throws AccessDeniedException  When the caller is not an archivist or administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function describe(string $dossierId): array;

	/**
	 * Hand a closed dossier to OpenRegister: a transfer list for a category
	 * that keeps, a destruction list for one that destroys.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier does not exist
	 * @throws AccessDeniedException   When the caller is not an archivist or administrator
	 * @throws DossierRefusedException When it is not closed, already on a list, has no category,
	 *                                 or OpenRegister cannot take it
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function propose(string $dossierId): array;

	/**
	 * Read what OpenRegister did with the dossier's list: a completed transfer
	 * makes it transferred, an executed destruction list destroyed. Anything
	 * else leaves it as it is.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The id, lifecycle and listStatus
	 *
	 * @throws MissingObjectException When the dossier does not exist
	 * @throws AccessDeniedException  When the caller is not an archivist or administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	public function reflectOutcome(string $dossierId): array;
}//end interface
