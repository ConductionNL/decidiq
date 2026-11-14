<?php

/**
 * Contract of MeetingSeriesService
 *
 * The public surface of MeetingSeriesService that other components call, so they depend
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
 * Contract of MeetingSeriesService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingSeriesServiceInterface {

	/**
	 * Generate the meeting instances for a recurrence pattern.
	 *
	 * Loads the template via ObjectService (OpenRegister RBAC: callers
	 * without read access get null → "not found"), derives or reuses the
	 * series slug, stamps `series` + `seriesPattern` on the template, and
	 * creates one independently-editable Meeting per expanded date.
	 *
	 * @param string $meetingId UUID of the template meeting
	 * @param array<string, mixed> $pattern Recurrence pattern (frequency, interval, until, exceptions)
	 * @param string $actor Acting user UID (for the audit log)
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{success: bool, series: string|null, instances: array<int, array<string, mixed>>, truncated: bool, message: string}
	 */
	public function generateSeries(string $meetingId, array $pattern, string $actor): array;
}//end interface
