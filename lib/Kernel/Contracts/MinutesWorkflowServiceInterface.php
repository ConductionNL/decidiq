<?php

/**
 * Contract of MinutesWorkflowService
 *
 * The public surface of MinutesWorkflowService that other components call, so they depend
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
 * Contract of MinutesWorkflowService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MinutesWorkflowServiceInterface {

	/**
	 * Extract action item candidates from the minutes content.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array<int,array<string,mixed>> The extracted candidates
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	public function extractActionItems(string $minutesId): array;

	/**
	 * Persist the action items a user confirmed.
	 *
	 * @param string $minutesId The Minutes ID
	 * @param array<int,mixed> $confirmed The confirmed candidates
	 *
	 * @return int The count of action items saved
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 * @throws RuntimeException When the Minutes have already been published (HTTP 400)
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	public function saveExtractedActionItems(string $minutesId, array $confirmed): int;

	/**
	 * Move draft Minutes into review and notify the approvers.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array{lifecycle:string,notified:int} The new lifecycle and notification count
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 * @throws RuntimeException When the Minutes are not in draft (HTTP 409)
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
	 */
	public function submitForApproval(string $minutesId): array;
}//end interface
