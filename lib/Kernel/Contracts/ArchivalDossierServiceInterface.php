<?php

/**
 * Contract of ArchivalDossierService
 *
 * The public surface of ArchivalDossierService that other components call, so they depend
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
 * Contract of ArchivalDossierService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArchivalDossierServiceInterface {

	/**
	 * Gather a forming dossier's records again.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier or its meeting does not exist
	 * @throws AccessDeniedException   When the caller lacks authority on the meeting
	 * @throws DossierRefusedException When the dossier is no longer forming
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function assemble(string $dossierId): array;

	/**
	 * Close a forming dossier: gather once more, refuse when gaps remain and
	 * no reason is given, then freeze it.
	 *
	 * @param string      $dossierId      The dossier
	 * @param string|null $overrideReason Why it closes despite its gaps
	 *
	 * @return array<string, mixed> The closed dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier or its meeting does not exist
	 * @throws AccessDeniedException   When the caller lacks authority on the meeting
	 * @throws DossierRefusedException When it is no longer forming, or has gaps and no reason
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function close(string $dossierId, ?string $overrideReason): array;

	/**
	 * Form the dossier of a meeting, or answer the one it already has.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException When the meeting does not exist
	 * @throws AccessDeniedException  When the caller is not the meeting's chair, secretary or an administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function formForMeeting(string $meetingId): array;
}//end interface
