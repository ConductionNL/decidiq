<?php

/**
 * Contract of MeetingFolderService
 *
 * The public surface of MeetingFolderService that other components call, so they depend
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
 * Contract of MeetingFolderService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingFolderServiceInterface {

	/**
	 * Ensure the folder tree for a meeting exists (idempotent, fail-soft).
	 *
	 * @param array<string, mixed> $meeting Meeting object payload (post-create)
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string|null The created meeting folder path, or null on failure
	 */
	public function ensureMeetingFolders(array $meeting): ?string;
}//end interface
