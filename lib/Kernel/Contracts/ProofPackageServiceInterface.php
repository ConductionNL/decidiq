<?php

/**
 * Contract of ProofPackageService
 *
 * The public surface of ProofPackageService that other components call, so they depend
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

use OCA\Decidiq\Exception\MissingObjectException;
use RuntimeException;

/**
 * Contract of ProofPackageService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProofPackageServiceInterface {

	/**
	 * Assemble the notarial proof package for a meeting and store it.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $generatedBy Display name of the requesting user (server session)
	 *
	 * @throws MissingObjectException When the meeting is not found
	 * @throws RuntimeException When OpenRegister or Files is unavailable
	 *
	 * @return array<string,mixed> Keys: files (string[]), sha256 (string), generatedAt (string).
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function assemble(string $meetingId, string $generatedBy): array;
}//end interface
