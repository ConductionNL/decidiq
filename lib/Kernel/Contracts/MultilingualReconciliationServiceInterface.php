<?php

/**
 * Contract of MultilingualReconciliationService
 *
 * The public surface of MultilingualReconciliationService that other components call, so they depend
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
 * Contract of MultilingualReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MultilingualReconciliationServiceInterface {

	/**
	 * Process up to $maxEntries queued items through the translation adapter.
	 *
	 * Each successful translation creates a linked minutes record in
	 * the target language and flips the queue entry to `completed`.
	 *
	 * @param int $maxEntries Maximum entries processed per call
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, processed: int, completed: int, failed: int, message: string}
	 */
	public function processQueue(int $maxEntries = 10): array;

	/**
	 * Queue a minutes record for translation into one or more targets.
	 *
	 * The persisted queue entry references the source minutes record and
	 * one target locale; an array of targets fan-out to multiple entries.
	 *
	 * @param string $minutesId UUID of the source minutes record
	 * @param string $sourceLocale ISO 639-1 of the source content
	 * @param string[] $targetLocales ISO 639-1 list of locales to translate into
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, entries: array<int, array<string, mixed>>, message: string}
	 */
	public function queue(string $minutesId, string $sourceLocale, array $targetLocales): array;

	/**
	 * Return current queue status grouped by status value plus a queue listing.
	 *
	 * @param int $limit Optional max number of entries returned (default 50)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, summary: array<string, int>, entries: array<int, array<string, mixed>>, message: string}
	 */
	public function status(int $limit = 50): array;
}//end interface
