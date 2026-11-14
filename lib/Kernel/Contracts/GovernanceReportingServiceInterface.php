<?php

/**
 * Contract of GovernanceReportingService
 *
 * The public surface of GovernanceReportingService that other components call, so they depend
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
 * Contract of GovernanceReportingService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernanceReportingServiceInterface {

	/**
	 * Export a previously generated report.
	 *
	 * @param string $reportId UUID of the report row
	 * @param string $format One of json|csv
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{success: bool, body: string, contentType: string, message: string}
	 */
	public function exportReport(string $reportId, string $format = 'json'): array;

	/**
	 * Generate an annual governance report scoped to a single board.
	 *
	 * @param string $boardId UUID of the board
	 * @param int $year Calendar year to report on (YYYY)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{success: bool, report: array|null, message: string}
	 */
	public function generateAnnualReport(string $boardId, int $year): array;

	/**
	 * List historical reports for a board.
	 *
	 * @param string $boardId UUID of the board
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{success: bool, reports: array, count: int}
	 */
	public function listReports(string $boardId): array;
}//end interface
