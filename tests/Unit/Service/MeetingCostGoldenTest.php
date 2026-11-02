<?php

/**
 * Golden test for MeetingCostService.
 *
 * Runs computeCost over clamped and rounded inputs, and calculateForMeeting
 * over meetings that are passed in or looked up (found, missing), every shape
 * of the governance body link (relation objects with and without an id,
 * keyed relation strings, the flat field, a numeric id), body rates (missing,
 * not numeric, zero, negative, numeric text, a lookup that fails), opened and
 * closed stamps (missing, empty, unparsable, closed before opened, still open
 * in the future), and participant lookups (failing, entities with and without
 * a uuid, arrays, rows of another body, attendance taken or not, attendance
 * records that override the participant's own status). It records every
 * object-service call and log line, and what each call returns. The expected
 * values live in tests/fixtures/meeting-cost-golden.json; run with
 * RECORD_MEETING_COST_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingCostService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden test for the meeting cost.
 *
 * @covers \OCA\Decidiq\Service\MeetingCostService
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
 */
class MeetingCostGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/meeting-cost-golden.json';

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
	public function testEveryCostMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->costCases() as $name => $args) {
			$actual['compute:' . $name] = $this->service(case: [])->computeCost(...$args);
		}

		foreach ($this->meetingCases() as $name => $case) {
			$this->log = [];
			$service   = $this->service(case: $case);
			try {
				$returns = $service->calculateForMeeting($case['id'] ?? 'm1', $case['passed'] ?? null);
			} catch (\Throwable $e) {
				$returns = ['throws' => get_class($e)];
			}

			$actual['meeting:' . $name] = ['returns' => $returns, 'calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MEETING_COST_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION), json_encode($value, JSON_PRESERVE_ZERO_FRACTION), $name);
		}
	}//end testEveryCostMatchesTheRecording()

	/**
	 * The computeCost inputs: seconds, attendees, rate.
	 *
	 * @return array<string, array{0: int, 1: int, 2: float}>
	 */
	private function costCases(): array {
		return [
			'plain'          => [3600, 5, 100.0],
			'rounding'       => [1000, 3, 33.333],
			'negative-time'  => [-60, 5, 10.0],
			'negative-count' => [3600, -2, 10.0],
			'negative-rate'  => [3600, 2, -10.0],
			'zero'           => [0, 0, 0.0],
		];
	}//end costCases()

	/**
	 * A meeting with the given changes over a closed 90-minute default.
	 *
	 * @param array<string, mixed> $changes The changes
	 *
	 * @return array<string, mixed>
	 */
	private function meeting(array $changes=[]): array {
		return array_merge(
			[
				'@self'    => ['relations' => ['governanceBody' => 'b1']],
				'openedAt' => '2026-10-01T19:00:00+02:00',
				'closedAt' => '2026-10-01T20:30:00+02:00',
			],
			$changes
		);
	}//end meeting()

	/**
	 * The meeting cases: how the doubles behave and what is passed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function meetingCases(): array {
		$entity  = ['entity' => ['uuid' => 'p1', 'row' => ['governanceBody' => 'b1', 'attendanceStatus' => 'present']]];
		$plain   = ['id' => 'p2', 'governanceBody' => 'b1', 'attendanceStatus' => 'absent'];
		$other   = ['id' => 'p3', 'governanceBody' => 'b2', 'attendanceStatus' => 'present'];
		$noBody  = ['id' => 'p4', 'attendanceStatus' => 'present'];
		$noUuid  = ['entity' => ['uuid' => '', 'row' => ['governanceBody' => 'b1']]];
		$withId  = ['entity' => ['uuid' => 'p9', 'row' => ['id' => 'own', 'governanceBody' => 'b1', 'attendanceStatus' => 'present']]];
		$bare    = ['entity' => ['row' => ['id' => 'p5', 'governanceBody' => 'b1']]];
		$roster  = [['id' => 'r1', 'governanceBody' => 'b1'], ['id' => 'r2', 'governanceBody' => 'b1'], ['id' => 'r3']];

		return [
			'lookup-missing'        => ['find' => ['meeting' => null]],
			'lookup-found'          => ['find' => ['meeting' => $this->meeting()], 'participants' => [$entity, $plain, $other, $noBody, 'text', 7]],
			'passed-no-rate'        => ['passed' => $this->meeting(), 'body' => []],
			'rate-not-numeric'      => ['passed' => $this->meeting(), 'body' => ['hourlyRate' => '12 euro']],
			'rate-zero'             => ['passed' => $this->meeting(), 'body' => ['hourlyRate' => 0]],
			'rate-negative'         => ['passed' => $this->meeting(), 'body' => ['hourlyRate' => -5]],
			'rate-text'             => ['passed' => $this->meeting(), 'body' => ['hourlyRate' => '12.5'], 'participants' => $roster],
			'body-missing'          => ['passed' => $this->meeting(), 'body' => null],
			'body-throws'           => ['passed' => $this->meeting(), 'body' => 'throw'],
			'no-body-link'          => ['passed' => $this->meeting(['@self' => []])],
			'relation-object'       => ['passed' => $this->meeting(['@self' => ['relations' => [['schema' => 'person', 'id' => 'x1'], ['schema' => 'agenda', 'id' => 'a1'], ['schema' => 'governance-body', 'id' => 'b1']]]]), 'participants' => $roster],
			'relation-object-no-id' => ['passed' => $this->meeting(['@self' => ['relations' => [['schema' => 'governance-body'], 'governanceBody' => 'b1']], 'governanceBody' => 'b1'])],
			'relation-other-key'    => ['passed' => $this->meeting(['@self' => ['relations' => ['chair' => 'u1']]]), 'participants' => $roster],
			'relation-numeric-id'   => ['passed' => $this->meeting(['@self' => ['relations' => [['schema' => 'governance-body', 'id' => 42]]]])],
			'relations-top-level'   => ['passed' => $this->meeting(['@self' => [], 'relations' => ['governanceBody' => 'b1']]), 'participants' => $roster],
			'relations-not-array'   => ['passed' => $this->meeting(['@self' => ['relations' => 'b1'], 'governanceBody' => 'b1']), 'participants' => $roster],
			'flat-field'            => ['passed' => $this->meeting(['@self' => [], 'governanceBody' => 'b1']), 'participants' => $roster],
			'flat-field-not-string' => ['passed' => $this->meeting(['@self' => [], 'governanceBody' => ['id' => 'b1']])],
			'never-opened'          => ['passed' => $this->meeting(['openedAt' => null]), 'participants' => $roster],
			'opened-empty'          => ['passed' => $this->meeting(['openedAt' => '', 'closedAt' => '2999-01-01T00:00:00+00:00']), 'participants' => $roster],
			'opened-unparsable'     => ['passed' => $this->meeting(['openedAt' => 'gisteren']), 'participants' => $roster],
			'closed-unparsable'     => ['passed' => $this->meeting(['closedAt' => 'straks']), 'participants' => $roster],
			'closed-before-opened'  => ['passed' => $this->meeting(['closedAt' => '2026-10-01T18:00:00+02:00']), 'participants' => $roster],
			'still-open-future'     => ['passed' => $this->meeting(['openedAt' => '2999-01-01T00:00:00+00:00', 'closedAt' => null]), 'participants' => $roster],
			'still-open-empty'      => ['passed' => $this->meeting(['openedAt' => '2999-01-01T00:00:00+00:00', 'closedAt' => '']), 'participants' => $roster],
			'participants-throw'    => ['passed' => $this->meeting(), 'participants' => 'throw'],
			'roster-no-attendance'  => ['passed' => $this->meeting(), 'participants' => $roster],
			'entities'              => [
				'passed'       => $this->meeting(),
				'participants' => [$entity, $noUuid, $withId, $bare],
				'attendance'   => [
					['meeting' => 'm1', 'participant' => 'p1', 'status' => 'present'],
					['meeting' => 'm1', 'participant' => 'p9', 'status' => 'present'],
					['meeting' => 'm1', 'participant' => 'own', 'status' => 'absent'],
					['meeting' => 'm1', 'participant' => 'p5', 'status' => 'present'],
				],
			],
			'entities-no-uuid'      => [
				'passed'       => $this->meeting(),
				'participants' => [$noUuid, $bare],
				'attendance'   => [['meeting' => 'm1', 'participant' => '', 'status' => 'present']],
			],
			'roster-with-junk'      => ['passed' => $this->meeting(), 'participants' => [...$roster, 'text', 7, null]],
			'attendance-records'    => [
				'passed'       => $this->meeting(),
				'participants' => [$entity, $plain, ['id' => 'p6', 'governanceBody' => 'b1', 'attendanceStatus' => 'present']],
				'attendance'   => [
					['meeting' => 'm1', 'participant' => 'p2', 'status' => 'present'],
					['meeting' => ['id' => 'm1'], 'participant' => ['id' => 'p1'], 'status' => 'absent'],
					['meeting' => 'm2', 'participant' => 'p6', 'status' => 'present'],
				],
			],
			'attendance-empty'      => [
				'passed'       => $this->meeting(),
				'participants' => [$plain, ['id' => 'p7', 'governanceBody' => 'b1', 'attendanceStatus' => '']],
				'attendance'   => [['meeting' => 'm1', 'participant' => 'p7', 'status' => '']],
			],
		];
	}//end meetingCases()

	/**
	 * The service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MeetingCostService
	 */
	private function service(array $case): MeetingCostService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$all) use ($case): ?ObjectEntity {
				$names       = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(ObjectServiceInterface::class, 'find'))->getParameters());
				$args        = array_combine(array_slice($names, 0, count($all)), $all);
				$schema      = ($args['schema'] ?? null);
				$this->log[] = ['find' => [$args['id'], ($args['register'] ?? null), $schema]];
				$row         = ['hourlyRate' => 60];
				if (array_key_exists((string)$schema, ($case['find'] ?? [])) === true) {
					$row = $case['find'][$schema];
				}

				if ($schema === 'governance-body' && array_key_exists('body', $case) === true) {
					$row = $case['body'];
				}

				if ($row === 'throw') {
					throw new RuntimeException('find down');
				}

				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);
		$objects->method('setRegister')->willReturnCallback(
			function (string|int $register) use ($objects): ObjectServiceInterface {
				$this->log[] = ['setRegister' => $register];
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objects): ObjectServiceInterface {
				$this->log[] = ['setSchema' => $schema];
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]) use ($case): array {
				$this->log[] = ['findAll' => $config];
				if (($config['filters']['schema'] ?? '') === 'meeting-attendance') {
					return ($case['attendance'] ?? []);
				}

				if (($case['participants'] ?? null) === 'throw') {
					throw new RuntimeException('participants down');
				}

				return array_map(fn (mixed $row): mixed => $this->participant(row: $row), ($case['participants'] ?? []));
			}
		);

		return new MeetingCostService(logger: $this->logger(), objectService: $objects);
	}//end service()

	/**
	 * A participant as findAll answers it: an array, or an entity-like object.
	 *
	 * @param mixed $row The case row
	 *
	 * @return mixed
	 */
	private function participant(mixed $row): mixed {
		if (is_array($row) === false || isset($row['entity']) === false) {
			return $row;
		}

		if (array_key_exists('uuid', $row['entity']) === false) {
			return new class($row['entity']['row']) {
				/**
				 * Create the entity double.
				 *
				 * @param array<string, mixed> $row The data
				 */
				public function __construct(private array $row) {
				}

				/**
				 * The data.
				 *
				 * @return array<string, mixed>
				 */
				public function getObject(): array {
					return $this->row;
				}
			};
		}//end if

		return new class($row['entity']['row'], $row['entity']['uuid']) {
			/**
			 * Create the entity double.
			 *
			 * @param array<string, mixed> $row  The data
			 * @param string               $uuid The uuid
			 */
			public function __construct(private array $row, private string $uuid) {
			}

			/**
			 * The data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end participant()

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			/**
			 * Create the logger double.
			 *
			 * @param array<int, array<string, mixed>> $log The call log
			 */
			public function __construct(private array &$log) {
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
			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
	}//end logger()
}//end class
