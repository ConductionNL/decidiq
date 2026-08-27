<?php

/**
 * Unit tests for GovernanceReportingService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\GovernanceReportingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for GovernanceReportingService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */
class GovernanceReportingServiceTest extends TestCase {

	/**
	 * Build a service over an in-memory rowset keyed by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> &$rowsBySchema Map schema => rows
	 * @param array<int, array<string, mixed>> &$saved Captured saves
	 *
	 * @return GovernanceReportingService
	 */
	private function makeService(array &$rowsBySchema, array &$saved): GovernanceReportingService {
		$rowsRef = &$rowsBySchema;
		$savedRef = &$saved;
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$rowsRef): array {
				$schema = (string)($config['schema'] ?? '');
				$filters = ($config['filters'] ?? []);
				$rows = ($rowsRef[$schema] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $k => $v) {
								if (($row[$k] ?? null) !== $v) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use (&$rowsRef) {
				$schemaKey = (string)$schema;
				foreach (($rowsRef[$schemaKey] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedRef, &$rowsRef): ObjectEntity {
				$savedRef[] = $object + ['_schema' => (string)$schema];
				$row = array_merge(['id' => $schema . '-' . count($savedRef)], $object);

				$rowsRef[(string)$schema] ??= [];
				$rowsRef[(string)$schema][] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new GovernanceReportingService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objectService,
		);

	}//end makeService()

	/**
	 * generateAnnualReport rejects invalid input.
	 *
	 * @return void
	 */
	public function testGenerateAnnualReportRejectsInvalidInput(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generateAnnualReport('', 2026);
		$this->assertFalse($result['success']);

		$result2 = $svc->generateAnnualReport('b-1', 0);
		$this->assertFalse($result2['success']);

	}//end testGenerateAnnualReportRejectsInvalidInput()

	/**
	 * generateAnnualReport aggregates meetings, resolutions, votes, members.
	 *
	 * @return void
	 */
	public function testGenerateAnnualReportAggregates(): void {
		// Schema slugs follow the ADR-005 supertype model: board-meeting →
		// meeting, resolution → decision, board-vote → vote, board-member →
		// membership. The link/data field names are unchanged.
		$rows = [
			'meeting' => [
				['id' => 'm-1', 'boardIntegration' => 'b-1', 'meetingDate' => '2026-03-15T10:00:00Z'],
				['id' => 'm-2', 'boardIntegration' => 'b-1', 'meetingDate' => '2026-06-15T10:00:00Z'],
				['id' => 'm-3', 'boardIntegration' => 'b-1', 'meetingDate' => '2026-09-15T10:00:00Z'],
				['id' => 'm-4', 'boardIntegration' => 'b-1', 'meetingDate' => '2026-12-15T10:00:00Z'],
				['id' => 'm-5', 'boardIntegration' => 'b-1', 'meetingDate' => '2025-12-15T10:00:00Z'],
			],
			'decision' => [
				['id' => 'r-1', 'meetingIntegration' => 'm-1', 'voteThreshold' => 'simple-majority'],
				['id' => 'r-2', 'meetingIntegration' => 'm-2', 'voteThreshold' => 'simple-majority'],
				['id' => 'r-3', 'meetingIntegration' => 'm-5', 'voteThreshold' => 'simple-majority'],
			],
			'vote' => [
				['id' => 'v-1', 'resolutionKoppeling' => 'r-1', 'vote' => 'in-favor'],
				['id' => 'v-2', 'resolutionKoppeling' => 'r-1', 'vote' => 'against'],
				['id' => 'v-3', 'resolutionKoppeling' => 'r-2', 'vote' => 'in-favor'],
				['id' => 'v-4', 'resolutionKoppeling' => 'r-3', 'vote' => 'in-favor'],
			],
			'membership' => [
				['id' => 'bm-1', 'boardIntegration' => 'b-1', 'independenceStatus' => 'independent'],
				['id' => 'bm-2', 'boardIntegration' => 'b-1', 'independenceStatus' => 'independent'],
				['id' => 'bm-3', 'boardIntegration' => 'b-1', 'independenceStatus' => 'non-independent'],
				['id' => 'bm-4', 'boardIntegration' => 'b-1', 'independenceStatus' => 'non-independent'],
			],
			'conflict-of-interest' => [
				['id' => 'c-1', 'declarationTimestamp' => '2026-04-01T10:00:00Z'],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generateAnnualReport('b-1', 2026);

		$this->assertTrue($result['success']);
		$report = $result['report'];
		$this->assertSame(4, $report['meetingCount']);
		$this->assertSame(2, $report['resolutionCount']);
		$this->assertSame(2, $report['voteTally']['in-favor']);
		$this->assertSame(1, $report['voteTally']['against']);
		$this->assertSame(4, $report['memberCount']);
		$this->assertSame(0.5, $report['independenceRatio']);
		$this->assertSame(1, $report['conflictCount']);

		// Saved to register.
		$reportSaves = array_filter($saved, static fn (array $s): bool => ($s['_schema'] ?? '') === GovernanceReportingService::SCHEMA);
		$this->assertCount(1, $reportSaves);

	}//end testGenerateAnnualReportAggregates()

	/**
	 * complianceFlagCheck flags low meeting frequency / independence / attendance.
	 *
	 * @return void
	 */
	public function testComplianceFlagsFireOnLowMetrics(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$flags = $svc->complianceFlagCheck(
			[
				'meetingCount' => 2,
				'independenceRatio' => 0.3,
				'attendanceRate' => 0.6,
				'conflictCount' => 30,
			]
		);

		$this->assertFalse($flags['passed']);
		$this->assertContains('meeting-frequency-below-minimum', $flags['flags']);
		$this->assertContains('independence-ratio-below-50pct', $flags['flags']);
		$this->assertContains('attendance-rate-below-75pct', $flags['flags']);
		$this->assertContains('unusual-conflict-of-interest-volume', $flags['flags']);

	}//end testComplianceFlagsFireOnLowMetrics()

	/**
	 * complianceFlagCheck passes when all metrics are healthy.
	 *
	 * @return void
	 */
	public function testComplianceFlagsPassOnHealthyMetrics(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$flags = $svc->complianceFlagCheck(
			[
				'meetingCount' => 6,
				'independenceRatio' => 0.6,
				'attendanceRate' => 0.9,
				'conflictCount' => 4,
			]
		);

		$this->assertTrue($flags['passed']);
		$this->assertSame([], $flags['flags']);

	}//end testComplianceFlagsPassOnHealthyMetrics()

	/**
	 * exportReport rejects an unknown format.
	 *
	 * @return void
	 */
	public function testExportReportRejectsUnknownFormat(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->exportReport('rep-1', 'pdf');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Unsupported', $result['message']);

	}//end testExportReportRejectsUnknownFormat()

	/**
	 * exportReport returns CSV for csv format.
	 *
	 * @return void
	 */
	public function testExportReportReturnsCsv(): void {
		$rows = [
			GovernanceReportingService::SCHEMA => [
				[
					'id' => 'rep-1',
					'boardIntegration' => 'b-1',
					'year' => 2026,
					'meetingCount' => 4,
					'resolutionCount' => 8,
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->exportReport('rep-1', 'csv');

		$this->assertTrue($result['success']);
		$this->assertSame('text/csv', $result['contentType']);
		$this->assertStringContainsString('meetingCount,4', $result['body']);

	}//end testExportReportReturnsCsv()

	/**
	 * listReports returns reports filtered by board.
	 *
	 * @return void
	 */
	public function testListReportsFiltersByBoard(): void {
		$rows = [
			GovernanceReportingService::SCHEMA => [
				['id' => 'rep-1', 'boardIntegration' => 'b-1', 'year' => 2026],
				['id' => 'rep-2', 'boardIntegration' => 'b-2', 'year' => 2026],
				['id' => 'rep-3', 'boardIntegration' => 'b-1', 'year' => 2025],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->listReports('b-1');
		$this->assertSame(2, $result['count']);

	}//end testListReportsFiltersByBoard()

}//end class
