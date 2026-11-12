<?php

/**
 * Decidiq Governance Reporting Service
 *
 * Phase 5 aggregations: per-quarter (or per-year) governance reports
 * surfacing meeting count, resolution count, vote tallies, attendance,
 * independence ratio and conflict-of-interest flags. Reports are persisted
 * on the `governance-report` schema so historical reports remain queryable.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Governance reporting aggregation service.
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
 */
class GovernanceReportingService {

	/**
	 * Slug of the OpenRegister schema storing report rows.
	 *
	 * @var string
	 */
	public const SCHEMA = 'governance-report';

	/**
	 * The rowsets the annual report reads: schema, query limit and whether the
	 * query is filtered on the board.
	 *
	 * @var array<string, array{schema: string, limit: int, byBoard: bool}>
	 */
	private const GOVERNANCE_ROWSETS = [
		'meetings' => ['schema' => 'meeting', 'limit' => 5000, 'byBoard' => true],
		'resolutions' => ['schema' => 'decision', 'limit' => 5000, 'byBoard' => false],
		'votes' => ['schema' => 'vote', 'limit' => 50000, 'byBoard' => false],
		'members' => ['schema' => 'membership', 'limit' => 1000, 'byBoard' => true],
		'conflicts' => ['schema' => 'conflict-of-interest', 'limit' => 5000, 'byBoard' => false],
	];

	/**
	 * The CSV export rows: label, report field and the value of a missing field.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string|int}>
	 */
	private const CSV_FIELDS = [
		['boardKoppeling', 'boardIntegration', ''],
		['year', 'year', ''],
		['meetingCount', 'meetingCount', 0],
		['resolutionCount', 'resolutionCount', 0],
		['memberCount', 'memberCount', 0],
		['independenceRatio', 'independenceRatio', 0],
		['attendanceRate', 'attendanceRate', 0],
		['conflictCount', 'conflictCount', 0],
	];

	/**
	 * The compliance rules: counter, cast, limit, whether exceeding (rather
	 * than falling below) the limit breaches it, and the flag raised.
	 *
	 * @var array<int, array{key: string, cast: string, limit: int|float, above: bool, flag: string}>
	 */
	private const COMPLIANCE_RULES = [
		['key' => 'meetingCount', 'cast' => 'int', 'limit' => 4, 'above' => false, 'flag' => 'meeting-frequency-below-minimum'],
		['key' => 'independenceRatio', 'cast' => 'float', 'limit' => 0.5, 'above' => false, 'flag' => 'independence-ratio-below-50pct'],
		['key' => 'attendanceRate', 'cast' => 'float', 'limit' => 0.75, 'above' => false, 'flag' => 'attendance-rate-below-75pct'],
		['key' => 'conflictCount', 'cast' => 'int', 'limit' => 25, 'above' => true, 'flag' => 'unusual-conflict-of-interest-volume'],
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

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
	public function generateAnnualReport(string $boardId, int $year): array {
		if ($boardId === '' || $year < 1900 || $year > 9999) {
			return ['success' => false, 'report' => null, 'message' => 'boardId and a valid 4-digit year are required.'];
		}

		$data = $this->loadGovernanceData(boardId: $boardId);
		if ($data === null) {
			return ['success' => false, 'report' => null, 'message' => 'Failed to load governance data.'];
		}

		$scope = ['boardIntegration' => $boardId, 'year' => $year];
		$report = $this->annualReport(scope: $scope, rows: $this->yearRows(data: $data, year: $year));
		return ['success' => true, 'report' => $this->persistReport(report: $report), 'message' => 'Annual report generated.'];

	}//end generateAnnualReport()

	/**
	 * The governance rows that belong to one year: meetings in the year, the
	 * resolutions of those meetings, the votes on those resolutions, every
	 * member and the conflicts declared in the year.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data The loaded rowsets
	 * @param int $year The report year
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function yearRows(array $data, int $year): array {
		$window = ['start' => $year . '-01-01T00:00:00Z', 'end' => $year . '-12-31T23:59:59Z'];
		$meetings = $this->filterByDate(rows: $data['meetings'], window: $window + ['field' => 'meetingDate']);
		$resolutions = $this->rowsLinkedTo(rows: $data['resolutions'], link: ['field' => 'meetingIntegration', 'ids' => array_column($meetings, 'id')]);
		return [
			'meetings' => $meetings,
			'resolutions' => $resolutions,
			'votes' => $this->rowsLinkedTo(rows: $data['votes'], link: ['field' => 'resolutionKoppeling', 'ids' => array_column($resolutions, 'id')]),
			'members' => $data['members'],
			'conflicts' => $this->filterByDate(rows: $data['conflicts'], window: $window + ['field' => 'declarationTimestamp']),
		];

	}//end yearRows()

	/**
	 * The rows whose link field names one of the given ids.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows
	 * @param array{field: string, ids: array<int, mixed>} $link The link field and the ids
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsLinkedTo(array $rows, array $link): array {
		$ids = array_map('strval', $link['ids']);
		return array_values(
			array_filter($rows, static fn (array $row): bool => in_array((string)($row[$link['field']] ?? ''), $ids, true))
		);

	}//end rowsLinkedTo()

	/**
	 * The annual report of one board and year.
	 *
	 * @param array{boardIntegration: string, year: int} $scope The board and year
	 * @param array<string, array<int, array<string, mixed>>> $rows The year's rows
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, mixed>
	 */
	private function annualReport(array $scope, array $rows): array {
		$metrics = $this->yearMetrics(rows: $rows);
		return $scope + $metrics + ['flags' => $this->complianceFlagCheck(data: $metrics), 'generatedAt' => gmdate('Y-m-d\TH:i:s\Z')];

	}//end annualReport()

	/**
	 * The counters, vote tally and ratios of one year's rows, in report order.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The year's rows
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, mixed>
	 */
	private function yearMetrics(array $rows): array {
		$meetingCount = count($rows['meetings']);
		$memberCount = count($rows['members']);
		return [
			'meetingCount' => $meetingCount,
			'resolutionCount' => count($rows['resolutions']),
			'voteTally' => $this->tallyVotes(votes: $rows['votes']),
			'memberCount' => $memberCount,
			'independenceRatio' => $this->computeIndependenceRatio(members: $rows['members']),
			'attendanceRate' => $this->computeAttendanceRate(votes: $rows['votes'], expected: ($meetingCount * $memberCount)),
			'conflictCount' => count($rows['conflicts']),
		];

	}//end yearMetrics()

	/**
	 * Load every governance rowset the annual report is computed from.
	 *
	 * @param string $boardId UUID of the board
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, array<int, array<string, mixed>>>|null Rowsets keyed
	 *                                                              by meetings/resolutions/votes/members/conflicts, or null on failure
	 */
	private function loadGovernanceData(string $boardId): ?array {
		try {
			$data = [];
			foreach (self::GOVERNANCE_ROWSETS as $key => $rowset) {
				$data[$key] = $this->normalize(rows: $this->objectService->findAll($this->rowsetQuery(rowset: $rowset, boardId: $boardId)));
			}

			return $data;
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: GovernanceReportingService::generateAnnualReport failed', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end loadGovernanceData()

	/**
	 * The findAll() query of one rowset.
	 *
	 * @param array{schema: string, limit: int, byBoard: bool} $rowset The rowset
	 * @param string $boardId UUID of the board
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, mixed>
	 */
	private function rowsetQuery(array $rowset, string $boardId): array {
		$query = ['register' => 'decidiq', 'schema' => $rowset['schema']];
		if ($rowset['byBoard'] === true) {
			$query['filters'] = ['boardIntegration' => $boardId];
		}

		$query['limit'] = $rowset['limit'];
		return $query;

	}//end rowsetQuery()

	/**
	 * Persist the report row; returns the saved payload, or the input on failure.
	 *
	 * A failed bookkeeping write must not fail the generation itself, so the
	 * in-memory report is handed back unchanged when persistence throws.
	 *
	 * @param array<string, mixed> $report The generated report payload
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, mixed> The persisted payload or the input report
	 */
	private function persistReport(array $report): array {
		try {
			$saved = $this->objectService->saveObject(
				object: $report,
				register: 'decidiq',
				schema: self::SCHEMA
			);
			// OpenRegister's saveObject() returns ObjectEntityInterface — is_object() could
			// never be false, so this always returned here.
			return (array)$saved->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: GovernanceReportingService failed to persist report',
				['exception' => $e->getMessage()]
			);
		}//end try

		return $report;
	}//end persistReport()

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
	public function exportReport(string $reportId, string $format = 'json'): array {
		$format = strtolower($format);
		if (in_array($format, ['json', 'csv'], true) === false) {
			return $this->exportFailure(message: 'Unsupported export format: ' . $format);
		}

		$report = $this->loadReport(reportId: $reportId);
		if (is_string($report) === true) {
			return $this->exportFailure(message: $report);
		}

		if ($format === 'csv') {
			return ['success' => true, 'body' => $this->reportCsv(report: $report), 'contentType' => 'text/csv', 'message' => 'Report exported.'];
		}

		$body = (string)json_encode($report, (JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		return ['success' => true, 'body' => $body, 'contentType' => 'application/json', 'message' => 'Report exported.'];

	}//end exportReport()

	/**
	 * Load a stored report, or say why it cannot be loaded.
	 *
	 * @param string $reportId UUID of the report row
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array<string, mixed>|string The report, or the failure message
	 */
	private function loadReport(string $reportId): array|string {
		try {
			$entity = $this->objectService->find(id: $reportId, register: 'decidiq', schema: self::SCHEMA);
			if ($entity === null) {
				return 'Report not found.';
			}

			return $entity->getObject();
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: GovernanceReportingService::exportReport failed', ['exception' => $e->getMessage()]);
			return 'Failed to load report.';
		}

	}//end loadReport()

	/**
	 * The unsuccessful exportReport() envelope.
	 *
	 * @param string $message The failure message
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{success: bool, body: string, contentType: string, message: string}
	 */
	private function exportFailure(string $message): array {
		return ['success' => false, 'body' => '', 'contentType' => 'text/plain', 'message' => $message];

	}//end exportFailure()

	/**
	 * A report as key,value CSV lines, each value through CsvCell::escape().
	 *
	 * @param array<string, mixed> $report The report
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return string
	 */
	private function reportCsv(array $report): string {
		$rows = ['key,value'];
		$csv  = new CsvCell();
		foreach (self::CSV_FIELDS as [$label, $field, $missing]) {
			$rows[] = $label . ',' . $csv->escape(cell: (string)($report[$field] ?? $missing));
		}

		return implode("\n", $rows);

	}//end reportCsv()

	/**
	 * Run compliance checks against an aggregated payload.
	 *
	 * @param array<string, mixed> $data Aggregated counters
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{passed: bool, flags: array<int, string>}
	 */
	public function complianceFlagCheck(array $data): array {
		$flags = [];
		foreach (self::COMPLIANCE_RULES as $rule) {
			if ($this->breaches(rule: $rule, value: ($data[$rule['key']] ?? 0)) === true) {
				$flags[] = $rule['flag'];
			}
		}

		return ['passed' => ($flags === []), 'flags' => $flags];

	}//end complianceFlagCheck()

	/**
	 * Whether a counter breaches one compliance rule.
	 *
	 * @param array{key: string, cast: string, limit: int|float, above: bool, flag: string} $rule The rule
	 * @param mixed $value The counter's raw value
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return bool
	 */
	private function breaches(array $rule, mixed $value): bool {
		$cast = (float)$value;
		if ($rule['cast'] === 'int') {
			$cast = (int)$value;
		}

		if ($rule['above'] === true) {
			return $cast > $rule['limit'];
		}

		return $cast < $rule['limit'];

	}//end breaches()

	/**
	 * List historical reports for a board.
	 *
	 * @param string $boardId UUID of the board
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.3
	 *
	 * @return array{success: bool, reports: array, count: int}
	 */
	public function listReports(string $boardId): array {
		try {
			$query = ['register' => 'decidiq', 'schema' => self::SCHEMA, 'filters' => ['boardIntegration' => $boardId], 'limit' => 500];
			$rows = $this->objectService->findAll($query);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: GovernanceReportingService::listReports failed', ['exception' => $e->getMessage()]);
			return ['success' => false, 'reports' => [], 'count' => 0];
		}

		$out = $this->normalize(rows: $rows);
		return ['success' => true, 'reports' => $out, 'count' => count($out)];

	}//end listReports()

	/**
	 * Normalize a heterogeneous result set into a list of plain arrays.
	 *
	 * @param mixed $rows Raw findAll() result
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize(mixed $rows): array {
		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end normalize()

	/**
	 * Filter rows whose `$field` is within [$start, $end] (inclusive).
	 *
	 * @param array<int, array<string, mixed>> $rows Rows
	 * @param array{field: string, start: string, end: string} $window The date field and the ISO-8601 bounds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filterByDate(array $rows, array $window): array {
		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($window): bool {
					$timestamp = (string)($row[$window['field']] ?? '');
					return $timestamp !== '' && $timestamp >= $window['start'] && $timestamp <= $window['end'];
				}
			)
		);

	}//end filterByDate()

	/**
	 * Tally votes by vote enum.
	 *
	 * @param array<int, array<string, mixed>> $votes Votes in scope
	 *
	 * @return array<string, int>
	 */
	private function tallyVotes(array $votes): array {
		$tally = [
			'in-favor' => 0,
			'against' => 0,
			'abstain' => 0,
			'absent' => 0,
			'recused-due-to-conflict' => 0,
		];
		foreach ($votes as $v) {
			$vote = (string)($v['vote'] ?? '');
			if (isset($tally[$vote]) === true) {
				$tally[$vote]++;
			}
		}

		return $tally;
	}//end tallyVotes()

	/**
	 * Compute the share of members marked independence-status=independent.
	 *
	 * @param array<int, array<string, mixed>> $members Board members
	 *
	 * @return float
	 */
	private function computeIndependenceRatio(array $members): float {
		$total = count($members);
		if ($total === 0) {
			return 0.0;
		}

		$independent = 0;
		foreach ($members as $m) {
			if (((string)($m['independenceStatus'] ?? '')) === 'independent') {
				$independent++;
			}
		}

		return round(($independent / $total), 4);
	}//end computeIndependenceRatio()

	/**
	 * Compute the attendance rate as the fraction of expected votes that
	 * landed as in-favor / against / abstain (i.e. non-absent, non-recused).
	 *
	 * @param array<int, array<string, mixed>> $votes Votes
	 * @param int $expected Expected attendances: meetings times members
	 *
	 * @return float
	 */
	private function computeAttendanceRate(array $votes, int $expected): float {
		if ($expected <= 0) {
			return 0.0;
		}

		$present = 0;
		foreach ($votes as $v) {
			if (in_array((string)($v['vote'] ?? ''), ['in-favor', 'against', 'abstain'], true) === true) {
				$present++;
			}
		}

		return round(min(1.0, ($present / $expected)), 4);
	}//end computeAttendanceRate()
}//end class
