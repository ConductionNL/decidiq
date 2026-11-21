<?php

/**
 * Contract of LiveDecisionService
 *
 * The public surface of LiveDecisionService that other components call, so they depend
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

use Exception;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of LiveDecisionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LiveDecisionServiceInterface {

	/**
	 * Record a decision during an active meeting.
	 *
	 * Verifies the Meeting lifecycle is 'opened'; creates a Decision object;
	 * calls ensureDraftMinutes to auto-create draft Minutes if needed; links
	 * the Decision to the Minutes via relation; returns the Decision's slug.
	 *
	 * @param string $meetingId The Meeting ID
	 * @param array $decisionData Decision data (title, text, outcome, legalBasis)
	 *
	 * @return string The slug of the created Decision
	 *
	 * @throws MissingObjectException If the Meeting is not found
	 * @throws Exception If the Meeting lifecycle is not 'opened'
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-2.1
	 */
	public function recordDecision(string $meetingId, array $decisionData): string;
}//end interface
