<?php

/**
 * Contract of DashboardWidgetService
 *
 * The public surface of DashboardWidgetService that other components call, so they depend
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
 * Contract of DashboardWidgetService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DashboardWidgetServiceInterface {

	/**
	 * Resolve the current user's dashboard summary.
	 *
	 * Fail-soft: any failure (OpenRegister absent, register missing, schema
	 * drift) yields a zero/empty summary so the Nextcloud dashboard never sees
	 * an exception.
	 *
	 * @param string $userId Current Nextcloud user id (passed by the platform)
	 * @param int $now Current unix timestamp
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return array{pendingVotes:int, nextMeeting:array<string,mixed>|null} Summary
	 */
	public function getUserSummary(string $userId, int $now): array;
}//end interface
