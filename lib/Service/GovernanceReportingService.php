<?php
/**
 * Decidesk Governance Reporting Service
 *
 * Phase 5 aggregations: per-quarter (or per-year) governance reports
 * surfacing meeting count, resolution count, vote tallies, attendance,
 * independence ratio and conflict-of-interest flags. Reports are persisted
 * on the `governance-report` schema so historical reports remain queryable.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Governance reporting aggregation service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */
class GovernanceReportingService
{

    /**
     * Slug of the OpenRegister schema storing report rows.
     *
     * @var string
     */
    public const SCHEMA = 'governance-report';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container
     * @param LoggerInterface    $logger    Logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate an annual governance report scoped to a single board.
     *
     * @param string $boardId UUID of the board
     * @param int    $year    Calendar year to report on (YYYY)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array{success: bool, report: array|null, message: string}
     */
    public function generateAnnualReport(string $boardId, int $year): array
    {
        if ($boardId === '' || $year < 1900 || $year > 9999) {
            return [
                'success' => false,
                'report'  => null,
                'message' => 'boardId and a valid 4-digit year are required.',
            ];
        }

        $start = $year.'-01-01T00:00:00Z';
        $end   = $year.'-12-31T23:59:59Z';

        $data = $this->loadGovernanceData(boardId: $boardId);
        if ($data === null) {
            return [
                'success' => false,
                'report'  => null,
                'message' => 'Failed to load governance data.',
            ];
        }

        $meetings    = $data['meetings'];
        $resolutions = $data['resolutions'];
        $votes       = $data['votes'];
        $members     = $data['members'];
        $conflicts   = $data['conflicts'];

        $meetingsInYear      = $this->filterByDate(rows: $meetings, field: 'meetingDate', start: $start, end: $end);
        $meetingIdsInYear    = array_column($meetingsInYear, 'id');
        $resolutionsInYear   = array_values(
            array_filter(
                $resolutions,
                static fn(array $r): bool => in_array((string) ($r['meetingKoppeling'] ?? ''), array_map('strval', $meetingIdsInYear), true)
            )
        );
        $resolutionIdsInYear = array_column($resolutionsInYear, 'id');
        $votesInYear         = array_values(
            array_filter(
                $votes,
                static fn(array $v): bool => in_array((string) ($v['resolutionKoppeling'] ?? ''), array_map('strval', $resolutionIdsInYear), true)
            )
        );
        $conflictsInYear     = $this->filterByDate(rows: $conflicts, field: 'declarationTimestamp', start: $start, end: $end);

        $voteTally = $this->tallyVotes(votes: $votesInYear);

        $independenceRatio = $this->computeIndependenceRatio(members: $members);
        $attendanceRate    = $this->computeAttendanceRate(votes: $votesInYear, meetingCount: count($meetingsInYear), memberCount: count($members));

        $flags = $this->complianceFlagCheck(
            data: [
                'meetingCount'      => count($meetingsInYear),
                'resolutionCount'   => count($resolutionsInYear),
                'independenceRatio' => $independenceRatio,
                'attendanceRate'    => $attendanceRate,
                'conflictCount'     => count($conflictsInYear),
            ]
        );

        $report = [
            'boardKoppeling'    => $boardId,
            'year'              => $year,
            'meetingCount'      => count($meetingsInYear),
            'resolutionCount'   => count($resolutionsInYear),
            'voteTally'         => $voteTally,
            'memberCount'       => count($members),
            'independenceRatio' => $independenceRatio,
            'attendanceRate'    => $attendanceRate,
            'conflictCount'     => count($conflictsInYear),
            'flags'             => $flags,
            'generatedAt'       => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return [
            'success' => true,
            'report'  => $this->persistReport(report: $report),
            'message' => 'Annual report generated.',
        ];

    }//end generateAnnualReport()

    /**
     * Load every governance rowset the annual report is computed from.
     *
     * @param string $boardId UUID of the board
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array<string, array<int, array<string, mixed>>>|null Rowsets keyed
     *         by meetings/resolutions/votes/members/conflicts, or null on failure
     */
    private function loadGovernanceData(string $boardId): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            return [
                'meetings'    => $this->normalize(
                    rows: $objectService->findAll(
                        [
                            'register' => 'decidesk',
                            'schema'   => 'meeting',
                            'filters'  => ['boardKoppeling' => $boardId],
                            'limit'    => 5000,
                        ]
                    )
                ),
                'resolutions' => $this->normalize(
                    rows: $objectService->findAll(
                        [
                            'register' => 'decidesk',
                            'schema'   => 'decision',
                            'limit'    => 5000,
                        ]
                    )
                ),
                'votes'       => $this->normalize(
                    rows: $objectService->findAll(
                        [
                            'register' => 'decidesk',
                            'schema'   => 'vote',
                            'limit'    => 50000,
                        ]
                    )
                ),
                'members'     => $this->normalize(
                    rows: $objectService->findAll(
                        [
                            'register' => 'decidesk',
                            'schema'   => 'membership',
                            'filters'  => ['boardKoppeling' => $boardId],
                            'limit'    => 1000,
                        ]
                    )
                ),
                'conflicts'   => $this->normalize(
                    rows: $objectService->findAll(
                        [
                            'register' => 'decidesk',
                            'schema'   => 'conflict-of-interest',
                            'limit'    => 5000,
                        ]
                    )
                ),
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: GovernanceReportingService::generateAnnualReport failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end loadGovernanceData()

    /**
     * Persist the report row; returns the saved payload, or the input on failure.
     *
     * A failed bookkeeping write must not fail the generation itself, so the
     * in-memory report is handed back unchanged when persistence throws.
     *
     * @param array<string, mixed> $report The generated report payload
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array<string, mixed> The persisted payload or the input report
     */
    private function persistReport(array $report): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $report,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if (is_object($saved) === true) {
                return (array) $saved->jsonSerialize();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: GovernanceReportingService failed to persist report',
                ['exception' => $e->getMessage()]
            );
        }//end try

        return $report;

    }//end persistReport()

    /**
     * Export a previously generated report.
     *
     * @param string $reportId UUID of the report row
     * @param string $format   One of json|csv
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array{success: bool, body: string, contentType: string, message: string}
     */
    public function exportReport(string $reportId, string $format='json'): array
    {
        $format = strtolower($format);
        if (in_array($format, ['json', 'csv'], true) === false) {
            return [
                'success'     => false,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Unsupported export format: '.$format,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $reportId,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if ($entity === null) {
                return [
                    'success'     => false,
                    'body'        => '',
                    'contentType' => 'text/plain',
                    'message'     => 'Report not found.',
                ];
            }

            $report = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $report = $entity->getObject();
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: GovernanceReportingService::exportReport failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'     => false,
                'body'        => '',
                'contentType' => 'text/plain',
                'message'     => 'Failed to load report.',
            ];
        }//end try

        if ($format === 'csv') {
            $rows = [
                'key,value',
                'boardKoppeling,'.((string) ($report['boardKoppeling'] ?? '')),
                'year,'.((string) ($report['year'] ?? '')),
                'meetingCount,'.((string) ($report['meetingCount'] ?? 0)),
                'resolutionCount,'.((string) ($report['resolutionCount'] ?? 0)),
                'memberCount,'.((string) ($report['memberCount'] ?? 0)),
                'independenceRatio,'.((string) ($report['independenceRatio'] ?? 0)),
                'attendanceRate,'.((string) ($report['attendanceRate'] ?? 0)),
                'conflictCount,'.((string) ($report['conflictCount'] ?? 0)),
            ];
            return [
                'success'     => true,
                'body'        => implode("\n", $rows),
                'contentType' => 'text/csv',
                'message'     => 'Report exported.',
            ];
        }

        return [
            'success'     => true,
            'body'        => (string) json_encode($report, (JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)),
            'contentType' => 'application/json',
            'message'     => 'Report exported.',
        ];

    }//end exportReport()

    /**
     * Run compliance checks against an aggregated payload.
     *
     * @param array<string, mixed> $data Aggregated counters
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array{passed: bool, flags: array<int, string>}
     */
    public function complianceFlagCheck(array $data): array
    {
        $flags = [];

        $meetingCount = (int) ($data['meetingCount'] ?? 0);
        if ($meetingCount < 4) {
            $flags[] = 'meeting-frequency-below-minimum';
        }

        $independence = (float) ($data['independenceRatio'] ?? 0.0);
        if ($independence < 0.5) {
            $flags[] = 'independence-ratio-below-50pct';
        }

        $attendance = (float) ($data['attendanceRate'] ?? 0.0);
        if ($attendance < 0.75) {
            $flags[] = 'attendance-rate-below-75pct';
        }

        $conflicts = (int) ($data['conflictCount'] ?? 0);
        if ($conflicts > 25) {
            $flags[] = 'unusual-conflict-of-interest-volume';
        }

        return [
            'passed' => ($flags === []),
            'flags'  => $flags,
        ];

    }//end complianceFlagCheck()

    /**
     * List historical reports for a board.
     *
     * @param string $boardId UUID of the board
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     *
     * @return array{success: bool, reports: array, count: int}
     */
    public function listReports(string $boardId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => self::SCHEMA,
                    'filters'  => ['boardKoppeling' => $boardId],
                    'limit'    => 500,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: GovernanceReportingService::listReports failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'reports' => [],
                'count'   => 0,
            ];
        }//end try

        $out = $this->normalize(rows: $rows);
        return [
            'success' => true,
            'reports' => $out,
            'count'   => count($out),
        ];

    }//end listReports()

    /**
     * Normalize a heterogeneous result set into a list of plain arrays.
     *
     * @param mixed $rows Raw findAll() result
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
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
     * @param array<int, array<string, mixed>> $rows  Rows
     * @param string                           $field Field name
     * @param string                           $start ISO-8601 start
     * @param string                           $end   ISO-8601 end
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByDate(array $rows, string $field, string $start, string $end): array
    {
        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($field, $start, $end): bool {
                    $timestamp = (string) ($row[$field] ?? '');
                    if ($timestamp === '') {
                        return false;
                    }

                    return ($timestamp >= $start && $timestamp <= $end);
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
    private function tallyVotes(array $votes): array
    {
        $tally = [
            'in-favor'                => 0,
            'against'                 => 0,
            'abstain'                 => 0,
            'absent'                  => 0,
            'recused-due-to-conflict' => 0,
        ];
        foreach ($votes as $v) {
            $vote = (string) ($v['vote'] ?? '');
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
    private function computeIndependenceRatio(array $members): float
    {
        $total = count($members);
        if ($total === 0) {
            return 0.0;
        }

        $independent = 0;
        foreach ($members as $m) {
            if (((string) ($m['independenceStatus'] ?? '')) === 'independent') {
                $independent++;
            }
        }

        return round(($independent / $total), 4);

    }//end computeIndependenceRatio()

    /**
     * Compute the attendance rate as the fraction of expected votes that
     * landed as in-favor / against / abstain (i.e. non-absent, non-recused).
     *
     * @param array<int, array<string, mixed>> $votes        Votes
     * @param int                              $meetingCount Number of meetings
     * @param int                              $memberCount  Number of members
     *
     * @return float
     */
    private function computeAttendanceRate(array $votes, int $meetingCount, int $memberCount): float
    {
        $expected = ($meetingCount * $memberCount);
        if ($expected <= 0) {
            return 0.0;
        }

        $present = 0;
        foreach ($votes as $v) {
            $vote = (string) ($v['vote'] ?? '');
            if ($vote === 'in-favor' || $vote === 'against' || $vote === 'abstain') {
                $present++;
            }
        }

        return round(min(1.0, ($present / $expected)), 4);

    }//end computeAttendanceRate()
}//end class
