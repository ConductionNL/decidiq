<?php

/**
 * Contract of CommitmentProgressService
 *
 * The public surface of CommitmentProgressService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of CommitmentProgressService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitmentProgressServiceInterface {

	/**
	 * Append a progress entry dated today and save the commitment.
	 *
	 * The caller's right is checked by canAddProgress() first; OpenRegister's
	 * own update rule (administrators only) is therefore bypassed here.
	 *
	 * @param string $commitmentId The commitment UUID
	 * @param string $note         What happened
	 *
	 * @return list<array{date: string, note: string}> The progress entries after the addition
	 *
	 * @throws InvalidArgumentException When the note is empty or too long
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
	 */
	public function addEntry(string $commitmentId, string $note): array;

	/**
	 * Whether the caller may add progress to this commitment: chair or
	 * secretary of its meeting, or an administrator.
	 *
	 * @param string $commitmentId The commitment UUID
	 * @param string $uid          The caller's Nextcloud UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
	 */
	public function canAddProgress(string $commitmentId, string $uid): bool;
}//end interface
