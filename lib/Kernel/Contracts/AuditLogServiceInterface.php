<?php

/**
 * Contract of AuditLogService
 *
 * The public surface of AuditLogService that other components call, so they depend
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
 * Contract of AuditLogService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AuditLogServiceInterface {

	/**
	 * Export audit log entries between two ISO-8601 UTC timestamps in the
	 * requested format.
	 *
	 * @param string $startDate Inclusive start (ISO-8601, e.g. "2026-01-01T00:00:00Z")
	 * @param string $endDate Inclusive end (ISO-8601)
	 * @param string $format 'json' (default) or 'csv'
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, format: string, body: string, count: int}
	 */
	public function export(string $startDate, string $endDate, string $format = 'json'): array;

	/**
	 * Filter the audit log by actor / action / date-range / object-uuid. All
	 * filters are optional and combined with AND semantics. Pagination defaults
	 * to 100 rows.
	 *
	 * @param array<string, mixed> $filters Filter criteria (actor / action / startDate / endDate / objectUuid / limit / offset)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, entries: array, count: int}
	 */
	public function query(array $filters = []): array;

	/**
	 * Verify the audit hash chain.
	 *
	 * Delegates to OR's AuditHashService::verifyChain() — decidiq rows sit in
	 * the platform-wide chain, so chain integrity is the platform's claim,
	 * not a parallel decidiq computation. When an entry uuid is given, the
	 * chain is verified from its start up to (and including) that entry.
	 *
	 * @param string|null $entryUuid Optional entry UUID to stop at; null verifies the
	 *                               entire chain.
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{valid: bool, checked: int, tampered: array<int, string>}
	 */
	public function verify(?string $entryUuid = null): array;

	/**
	 * Append a new entry to the audit log.
	 *
	 * Resolves the first referenced object uid to its OR ObjectEntity and
	 * writes one `decidiq.audit.{action}` entry onto it through
	 * AuditTrailMapper::createAuditTrailEntry(). The decidiq context (actor,
	 * the full uid list, the payload) is carried in the entry's `changed`
	 * column; hash chaining and sealing are the platform's.
	 *
	 * LOUD on failure: an unavailable audit surface, an unknown action or a
	 * uid list in which nothing resolves produces an error log plus
	 * success=false — never a silently absent row.
	 *
	 * @param string $actor Nextcloud UID of the acting user (or 'system')
	 * @param string $action One of self::ACTIONS
	 * @param string[] $objectUids List of object UUIDs the action touched
	 * @param array $payload Optional extra structured detail; stored in the entry context
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, entry: array|null, message: string}
	 */
	public function append(string $actor, string $action, array $objectUids, array $payload = []): array;
}//end interface
