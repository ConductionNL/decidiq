<?php

/**
 * Contract of RegulatorExportService
 *
 * The public surface of RegulatorExportService that other components call, so they depend
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
 * Contract of RegulatorExportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegulatorExportServiceInterface {

	/**
	 * Retrieve a previously generated export record and re-emit its body.
	 *
	 * @param string $exportId UUID of the persisted export record
	 * @param string $actor Acting user UID (for the audit log)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return array{success: bool, body: string, contentType: string, filename: string, export: array|null, message: string}
	 */
	public function download(string $exportId, string $actor): array;

	/**
	 * Build and persist a regulator export.
	 *
	 * @param string $boardId UUID of the board to export
	 * @param string $scope One of self::SCOPES
	 * @param string $format One of self::FORMATS
	 * @param string $actor Acting user UID (for the audit log)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return array{success: bool, export: array|null, body: string, contentType: string, filename: string, message: string}
	 */
	public function generate(string $boardId, string $scope, string $format, string $actor): array;

	/**
	 * List previously generated regulator exports for a board.
	 *
	 * @param string $boardId UUID of the board
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
	 *
	 * @return array{success: bool, exports: array<int, array<string, mixed>>, count: int}
	 */
	public function listExports(string $boardId): array;
}//end interface
