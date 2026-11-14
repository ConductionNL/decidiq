<?php

/**
 * Contract of ActionItemAnalyticsService
 *
 * The public surface of ActionItemAnalyticsService that other components call, so they depend
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
 * Contract of ActionItemAnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActionItemAnalyticsServiceInterface {

	/**
	 * Get action items assigned to the current user, grouped by urgency.
	 *
	 * Queries ActionItems where assignee matches the Participant UUID resolved
	 * from the caller's Nextcloud UID, then taskStatus != 'completed'; groups into:
	 * - overdue: dueDate < today
	 * - thisWeek: dueDate <= today + 7 days
	 * - later: dueDate > today + 7 days
	 *
	 * Using NC UID (not display name) prevents cross-user PII leaks via display name
	 * spoofing (display names are user-settable and non-unique).
	 *
	 * @param string $nextcloudUid The caller's Nextcloud user UID
	 *
	 * @return array<string, array<array>> Grouped array with keys 'overdue', 'thisWeek', 'later'
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-1.1
	 */
	public function getMyItems(string $nextcloudUid): array;
}//end interface
