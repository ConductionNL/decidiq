<?php

/**
 * Contract of MeetingReminderService
 *
 * The public surface of MeetingReminderService that other components call, so they depend
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
 * Contract of MeetingReminderService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingReminderServiceInterface {

	/**
	 * Send every notice that is due, and stamp it.
	 *
	 * @param int $now Unix time of the run
	 *
	 * @return int Deliveries made
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-a-notice-sent-with-nobody-signed-in-runs-as-the-system-req-mrd-010
	 */
	public function run(int $now): int;
}//end interface
