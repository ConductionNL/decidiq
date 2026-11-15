<?php

/**
 * Contract of SubjectClearanceService
 *
 * The public surface of SubjectClearanceService that other components call, so they depend
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
 * Contract of SubjectClearanceService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubjectClearanceServiceInterface {

	/**
	 * Whether a subject is cleared, and what is holding it up if not.
	 *
	 * @param array<int, array<string, mixed>> $routes The routes on the subject, each with its stages.
	 *
	 * @return array{cleared: bool, waitingOn: array<int, array<string, mixed>>} The answer.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function clearanceFor(array $routes): array;

	/**
	 * A one-line answer for a consumer that wants to show why.
	 *
	 * @param array{cleared: bool, waitingOn: array<int, array<string, mixed>>} $clearance The answer.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function describe(array $clearance): string;
}//end interface
