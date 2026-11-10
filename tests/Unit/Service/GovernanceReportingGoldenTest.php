<?php

/**
 * Golden test for GovernanceReportingService.
 *
 * Runs generateAnnualReport, exportReport, complianceFlagCheck and
 * listReports over boards, years, governance rowsets and object-service
 * behaviours (queries, lookups and saves that throw, missing reports, rows
 * as entities or arrays, dates on and around the year's bounds) and records
 * in order every object-service call and every log line, plus what each call
 * returns. The generation time is masked. The expected values live in
 * tests/fixtures/governance-reporting-golden.json; run with
 * RECORD_GOVERNANCE_REPORTING_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Service\GovernanceReportingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden test for the annual governance report.
 *
 * @covers \OCA\Decidiq\Service\GovernanceReportingService
 * @uses   \OCA\Decidiq\Service\CsvCell
 */
class GovernanceReportingGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/governance-reporting-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every case answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryReportCallMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$service = new GovernanceReportingService($this->logger(), $this->objects(case: $case));
			$actual[$name] = ['returns' => ($case['call'])($service), 'calls' => $this->log];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/"generatedAt": "[^"]*"/', '"generatedAt": "<now>"', $json);
		if (getenv('RECORD_GOVERNANCE_REPORTING_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryReportCallMatchesTheRecording()

	/**
	 * The cases: a call and how the object service behaves.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$annual = static fn (string $board, int $year) =>
			static fn (GovernanceReportingService $s): array => $s->generateAnnualReport($board, $year);
		$export = static fn (string $id, string $format) =>
			static fn (GovernanceReportingService $s): array => $s->exportReport($id, $format);
		$flags = static fn (array $data) => static fn (GovernanceReportingService $s): array => $s->complianceFlagCheck($data);
		$list = static fn (string $board) => static fn (GovernanceReportingService $s): array => $s->listReports($board);
		return [
			'annual-no-board'    => ['call' => $annual('', 2026)],
			'annual-year-low'    => ['call' => $annual('b1', 1899)],
			'annual-year-high'   => ['call' => $annual('b1', 10000)],
			'annual-year-edges'  => ['call' => $annual('b1', 1900)],
			'annual-load-throws' => ['call' => $annual('b1', 2026), 'findAll' => 'throw'],
			'annual-2026'        => ['call' => $annual('b1', 2026)],
			'annual-2025'        => ['call' => $annual('b1', 2025)],
			'annual-save-throws' => ['call' => $annual('b1', 2026), 'save' => 'throw'],
			'annual-empty'       => ['call' => $annual('b2', 2026), 'rows' => 'empty'],
			'export-bad-format'  => ['call' => $export('r1', 'xml')],
			'export-upper-json'  => ['call' => $export('r1', 'JSON')],
			'export-csv'         => ['call' => $export('r1', 'csv')],
			'export-csv-sparse'  => ['call' => $export('r2', 'csv')],
			'export-missing'     => ['call' => $export('nope', 'json')],
			'export-csv-no-year' => ['call' => $export('r3', 'csv')],
			'export-csv-hostile' => ['call' => $export('r4', 'csv')],
			'export-find-throws' => ['call' => $export('r1', 'csv'), 'find' => 'throw'],
			'flags-empty'        => ['call' => $flags([])],
			'flags-edges'        => ['call' => $flags(['meetingCount' => 4, 'independenceRatio' => 0.5, 'attendanceRate' => 0.75, 'conflictCount' => 25])],
			'flags-fractions'    => ['call' => $flags(['meetingCount' => '3.9', 'independenceRatio' => 1, 'attendanceRate' => '1', 'conflictCount' => '25.5'])],
			'flags-below'        => ['call' => $flags(['meetingCount' => '3', 'independenceRatio' => '0.49', 'attendanceRate' => 0.7499, 'conflictCount' => 26])],
			'list'               => ['call' => $list('b1')],
			'list-throws'        => ['call' => $list('b1'), 'findAll' => 'throw'],
		];
	}//end cases()

	/**
	 * The rows per schema.
	 *
	 * @param string $schema The schema
	 *
	 * @return array<int, mixed>
	 */
	private function rows(string $schema): array {
		$rows = [
			'meeting' => [
				['id' => 'm1', 'meetingDate' => '2026-01-01T00:00:00Z'],
				['id' => 'm2', 'meetingDate' => '2026-12-31T23:59:59Z'],
				['id' => 'm3', 'meetingDate' => '2027-01-01T00:00:00Z'],
				['id' => 'm4', 'meetingDate' => '2025-06-01T10:00:00Z'],
				['id' => 'm5'],
				['id' => 6, 'meetingDate' => '2026-05-05T10:00:00Z'],
				'entity:m7:2026-03-03T10:00:00Z',
				'not a row',
			],
			'decision' => [
				['id' => 'd1', 'meetingIntegration' => 'm1'],
				['id' => 'd2', 'meetingIntegration' => 'm4'],
				['id' => 'd3', 'meetingIntegration' => '6'],
				['id' => 'd4'],
				['id' => 'd5', 'meetingIntegration' => 'm7'],
			],
			'vote' => [
				['resolutionKoppeling' => 'd1', 'vote' => 'in-favor'],
				['resolutionKoppeling' => 'd1', 'vote' => 'against'],
				['resolutionKoppeling' => 'd1', 'vote' => 'abstain'],
				['resolutionKoppeling' => 'd2', 'vote' => 'in-favor'],
				['resolutionKoppeling' => 'd3', 'vote' => 'absent'],
				['resolutionKoppeling' => 'd5', 'vote' => 'recused-due-to-conflict'],
				['resolutionKoppeling' => 'd5', 'vote' => 'maybe'],
				['vote' => 'in-favor'],
			],
			'membership' => [
				['id' => 'p1', 'independenceStatus' => 'independent'],
				['id' => 'p2', 'independenceStatus' => 'executive'],
				['id' => 'p3'],
			],
			'conflict-of-interest' => [
				['id' => 'c1', 'declarationTimestamp' => '2026-02-02T00:00:00Z'],
				['id' => 'c2', 'declarationTimestamp' => ''],
				['id' => 'c3', 'declarationTimestamp' => '2025-02-02T00:00:00Z'],
			],
			'governance-report' => [['id' => 'r1', 'year' => 2026], 'entity:r2:'],
		];
		return array_map(
			function (mixed $row): mixed {
				if (is_string($row) === true && str_starts_with($row, 'entity:') === true) {
					[, $id, $date] = explode(':', $row, 3);
					return $this->entity(row: ['id' => $id, 'meetingDate' => $date]);
				}

				return $row;
			},
			($rows[$schema] ?? [])
		);
	}//end rows()

	/**
	 * The object service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(array $case): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->log[] = ['findAll' => $config];
				if (($case['findAll'] ?? '') === 'throw') {
					throw new RuntimeException('query down');
				}

				return ($case['rows'] ?? '') === 'empty' ? [] : $this->rows(schema: (string)$config['schema']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($case): ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'saveObject', $arguments);
				$this->log[] = ['saveObject' => $arguments];
				if (($case['save'] ?? '') === 'throw') {
					throw new RuntimeException('save down');
				}

				return $this->entity(row: ['id' => 'saved'] + $arguments['object']);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$arguments) use ($case): ?ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'find', $arguments);
				$this->log[] = ['find' => $arguments];
				if (($case['find'] ?? '') === 'throw') {
					throw new RuntimeException('find down');
				}

				$reports = [
					'r1' => ['boardIntegration' => 'b1', 'year' => 2026, 'meetingCount' => 5, 'resolutionCount' => 3, 'memberCount' => 7, 'independenceRatio' => 0.5714, 'attendanceRate' => 0.8, 'conflictCount' => 2],
					'r2' => ['year' => 2025],
					'r3' => ['boardIntegration' => 'b9'],
					'r4' => ['boardIntegration' => '=HYPERLINK("https://evil.example");b,1', 'year' => "2026\r", 'meetingCount' => 'x;=1+1;', 'resolutionCount' => " =2+2", 'memberCount' => "\n@SUM(A1)", 'independenceRatio' => 0.5, 'attendanceRate' => '-1', 'conflictCount' => "a\nb"],
				];
				return isset($reports[$arguments['id']]) === true ? $this->entity(row: $reports[$arguments['id']]) : null;
			}
		);
		return $objects;
	}//end objects()

	/**
	 * Map positional mock arguments onto parameter names, leaving out the
	 * ones still at their default.
	 *
	 * @param string       $class     The mocked class
	 * @param string       $method    The method
	 * @param array<mixed> $arguments The arguments received
	 *
	 * @return array<string, mixed>
	 */
	private function namedAsDeclared(string $class, string $method, array $arguments): array {
		$named = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $parameter) {
			if (array_key_exists($i, $arguments) === false) {
				break;
			}

			if ($parameter->isDefaultValueAvailable() === true && $parameter->getDefaultValue() === $arguments[$i]) {
				continue;
			}

			$named[$parameter->getName()] = $arguments[$i];
		}

		return $named;
	}//end namedAsDeclared()

	/**
	 * The arguments by name; a saveObject() call is expanded back into its old
	 * parameter names, so the recording describes the write, not the call shape.
	 *
	 * @param mixed $class See namedAsDeclared()
	 * @param mixed $method See namedAsDeclared()
	 * @param mixed $arguments See namedAsDeclared()
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $arguments): array {
		$named = $this->namedAsDeclared($class, $method, $arguments);
		if ($method === 'saveObject') {
			return SaveCallShape::legacyNamed(named: $named);
		}

		return $named;
	}//end named()

	/**
	 * A logger that records into the call log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			/**
			 * The shared call log.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $log;

			/**
			 * Record into the given log.
			 *
			 * @param array<int, array<string, mixed>> $log The call log
			 */
			public function __construct(array &$log) {
				$this->log = &$log;
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array<mixed>      $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
	}//end logger()

	/**
	 * An entity double; jsonSerialize() marks itself so a read through it shows.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn(['viaJson' => true] + $row);
		return $entity;
	}//end entity()
}//end class
