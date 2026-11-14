<?php

/**
 * Contract of MeetingTransitionGuard
 *
 * The public surface of MeetingTransitionGuard that other components call, so they depend
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
 * Contract of MeetingTransitionGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingTransitionGuardInterface {

	/**
	 * Check whether the open transition is allowed for the given meeting.
	 *
	 * Reads the declaratively-computed quorumMet field set by
	 * x-openregister-calculations on the Meeting schema (chain spec 1).
	 * When quorumRequired is null the calculation returns true, so
	 * meetings without a quorum rule are always allowed to open.
	 *
	 * @param array<string, mixed> $meeting Meeting object array (already loaded by the caller)
	 *
	 * @spec openspec/changes/archive/2026-05-11-quorum-guard-rewrite/tasks.md#task-1
	 *
	 * @return bool True when quorum is met or no quorum is required, false otherwise
	 */
	public function isOpenAllowed(array $meeting): bool;
}//end interface
