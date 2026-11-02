<?php

/**
 * Decidiq AuditRowFormat
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail;

/**
 * The shapes the audit log answers in: an OR audit trail row as a decidiq
 * audit row, the export bodies, a query page and a chain verification.
 * It holds no state.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
final class AuditRowFormat {

	/**
	 * Map an OR AuditTrail entity onto the decidiq audit row shape.
	 *
	 * The decidiq context written by append() (actorUuid, objectUids,
	 * payload) is read back out of the `changed` column; the platform's own
	 * chain hash is exposed as `currentHash` (empty until AuditSealJob's next
	 * sweep seals the row).
	 *
	 * @param AuditTrail $entry The OR audit trail entity
	 *
	 * @return array<string, mixed> The mapped decidiq audit row
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function mapRow(AuditTrail $entry): array {
		$changed = self::changedOf(entry: $entry);
		return [
			'id' => (string)($entry->getUuid() ?? $entry->getId()),
			'actorUuid' => (string)($changed['actorUuid'] ?? ($entry->getUser() ?? '')),
			'action' => self::decidiqAction(entry: $entry),
			'objectUids' => self::objectUidsOf(entry: $entry, changed: $changed),
			'timestamp' => self::timestampOf(entry: $entry),
			'payload' => (array)($changed['payload'] ?? []),
			'currentHash' => (string)($entry->getHash() ?? ''),
			'previousHash' => (string)($entry->getPreviousHash() ?? ''),
		];
	}//end mapRow()

	/**
	 * The rows whose timestamp lies inside the inclusive period.
	 *
	 * @param array<int, array<string, mixed>> $chain  The mapped rows
	 * @param array{0: string, 1: string}      $period The start and end
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function inPeriod(array $chain, array $period): array {
		[$startDate, $endDate] = $period;
		return array_values(
			array_filter(
				$chain,
				static function (array $row) use ($startDate, $endDate): bool {
					$timestamp = ($row['timestamp'] ?? '');
					return ($timestamp >= $startDate && $timestamp <= $endDate);
				}
			)
		);
	}//end inPeriod()

	/**
	 * The export answer: the rows as CSV or JSON.
	 *
	 * @param string                           $format 'json' or 'csv'
	 * @param list<array<string, mixed>>       $rows   The rows
	 *
	 * @return array{success: bool, format: string, body: string, count: int}
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function exportResult(string $format, array $rows): array {
		$body = self::jsonBody(rows: $rows);
		if ($format === 'csv') {
			$body = self::csvBody(rows: $rows);
		}

		return [
			'success' => true,
			'format' => $format,
			'body' => $body,
			'count' => count($rows),
		];
	}//end exportResult()

	/**
	 * The answer of an export that did not run.
	 *
	 * @param string $format The requested format
	 *
	 * @return array{success: false, format: string, body: string, count: int}
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function exportFailure(string $format): array {
		return [
			'success' => false,
			'format' => $format,
			'body' => '',
			'count' => 0,
		];
	}//end exportFailure()

	/**
	 * One page of the matching rows, 100 by default, with the total count.
	 *
	 * @param list<array<string, mixed>> $filtered The matching rows
	 * @param array<string, mixed>       $filters  Filter criteria (limit / offset)
	 *
	 * @return array{success: bool, entries: array, count: int}
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function queryPage(array $filtered, array $filters): array {
		$limit = (int)($filters['limit'] ?? 100);
		$offset = (int)($filters['offset'] ?? 0);
		if ($limit <= 0) {
			$limit = 100;
		}

		return [
			'success' => true,
			'entries' => array_slice($filtered, $offset, $limit),
			'count' => count($filtered),
		];
	}//end queryPage()

	/**
	 * The verification answer from what verifyChain() returned.
	 *
	 * @param array<string, mixed> $result The verifyChain() result
	 *
	 * @return array{valid: bool, checked: int, tampered: array<int, string>}
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function verification(array $result): array {
		$tampered = [];
		$brokenAt = ($result['brokenAt'] ?? null);
		if ($brokenAt !== null) {
			$tampered[] = (string)$brokenAt;
		}

		return [
			'valid' => (bool)($result['valid'] ?? false),
			'checked' => (int)($result['entriesVerified'] ?? 0),
			'tampered' => $tampered,
		];
	}//end verification()

	/**
	 * The answer when the chain could not be verified.
	 *
	 * @return array{valid: false, checked: 0, tampered: array<int, string>}
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 */
	public function notVerified(): array {
		return [
			'valid' => false,
			'checked' => 0,
			'tampered' => [],
		];
	}//end notVerified()

	/**
	 * The decidiq context of an entry; empty when the column holds none.
	 *
	 * @param AuditTrail $entry The OR audit trail entity
	 *
	 * @return array<string, mixed>
	 */
	private static function changedOf(AuditTrail $entry): array {
		$changed = ($entry->getChanged() ?? []);
		if (is_array($changed) === false) {
			return [];
		}

		return $changed;
	}//end changedOf()

	/**
	 * The entry's action without the decidiq namespace.
	 *
	 * @param AuditTrail $entry The OR audit trail entity
	 *
	 * @return string
	 */
	private static function decidiqAction(AuditTrail $entry): string {
		$action = (string)($entry->getAction() ?? '');
		if (str_starts_with($action, AuditLogService::ACTION_NAMESPACE) === true) {
			return substr($action, strlen(AuditLogService::ACTION_NAMESPACE));
		}

		return $action;
	}//end decidiqAction()

	/**
	 * The object uids the append carried, or the row's own object uuid.
	 *
	 * @param AuditTrail           $entry   The OR audit trail entity
	 * @param array<string, mixed> $changed The decidiq context of the entry
	 *
	 * @return list<string>
	 */
	private static function objectUidsOf(AuditTrail $entry, array $changed): array {
		$objectUids = ($changed['objectUids'] ?? null);
		if (is_array($objectUids) === false || $objectUids === []) {
			$objectUids = array_filter([(string)($entry->getObjectUuid() ?? '')]);
		}

		return array_values(array_map('strval', $objectUids));
	}//end objectUidsOf()

	/**
	 * The entry's creation time as ISO-8601 UTC, empty when it has none.
	 *
	 * @param AuditTrail $entry The OR audit trail entity
	 *
	 * @return string
	 */
	private static function timestampOf(AuditTrail $entry): string {
		$created = $entry->getCreated();
		if ($created === null) {
			return '';
		}

		return $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end timestampOf()

	/**
	 * The rows as CSV with a header line.
	 *
	 * @param list<array<string, mixed>> $rows The rows
	 *
	 * @return string
	 */
	private static function csvBody(array $rows): string {
		$lines = ['id,timestamp,actor,action,objectUids,previousHash,currentHash'];
		foreach ($rows as $row) {
			$lines[] = self::csvLine(row: $row);
		}

		return implode("\n", $lines);
	}//end csvBody()

	/**
	 * One row as a CSV line; the object uids are quoted and joined with `|`.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return string
	 */
	private static function csvLine(array $row): string {
		return implode(
			',',
			[
				(string)($row['id'] ?? ''),
				(string)($row['timestamp'] ?? ''),
				(string)($row['actorUuid'] ?? ''),
				(string)($row['action'] ?? ''),
				'"' . implode('|', (array)($row['objectUids'] ?? [])) . '"',
				(string)($row['previousHash'] ?? ''),
				(string)($row['currentHash'] ?? ''),
			]
		);
	}//end csvLine()

	/**
	 * The rows as a JSON document, `{}` when they cannot be encoded.
	 *
	 * @param list<array<string, mixed>> $rows The rows
	 *
	 * @return string
	 */
	private static function jsonBody(array $rows): string {
		$body = json_encode(['entries' => $rows], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($body === false) {
			return '{}';
		}

		return $body;
	}//end jsonBody()
}//end class
