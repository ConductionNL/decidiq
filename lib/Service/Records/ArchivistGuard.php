<?php

/**
 * Decidiq Archivist Guard
 *
 * Who may hand a dossier to OpenRegister's transfer or destruction list and
 * render its certificate: an archivist (OpenRegister's `archivaris` group,
 * the group its own archival routes accept) or an administrator.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Archivist or administrator, or refused.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
class ArchivistGuard {
	/**
	 * OpenRegister's archivist group.
	 */
	public const GROUP = 'archivaris';

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The signed-in account
	 * @param IGroupManager $groupManager Group and administrator check
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse a caller who is neither an archivist nor an administrator.
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the caller is neither
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function requireArchivist(): void {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new AccessDeniedException(message: 'Not signed in.');
		}

		if ($this->groupManager->isAdmin($uid) === true || $this->groupManager->isInGroup($uid, self::GROUP) === true) {
			return;
		}

		throw new AccessDeniedException(message: 'Only an archivist or an administrator sends a dossier to the archive or to destruction.');
	}//end requireArchivist()
}//end class
