<?php

/**
 * Contract of MeetingPackageService
 *
 * The public surface of MeetingPackageService that other components call, so they depend
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

/**
 * Contract of MeetingPackageService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingPackageServiceInterface {

	/**
	 * Assemble the meeting document package.
	 *
	 * Loads the meeting via ObjectService (OpenRegister RBAC: callers
	 * without read access get null → "not found"), collects its agenda
	 * items sorted by orderNumber, ensures the meeting folder exists, and
	 * builds the package subtree with the table of contents.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId Acting user UID (logged with the assembly)
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{success: bool, path: string|null, items: int, files: int, skipped: array<int, string>, message: string}
	 */
	public function assemble(string $meetingId, string $userId): array;
}//end interface
