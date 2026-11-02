<?php

/**
 * Golden test for EngagementService.
 *
 * Runs captureEngagement, findEngagementForMeeting and calculateScore over
 * existing and new records, every event type (speech with a duration, with
 * start and end times, with times that do not parse, question, topic and an
 * unknown type), query results as entities, arrays or neither, queries that
 * throw and save results of every shape, and records in order every
 * object-service call and every log line, plus what each call returns or
 * throws. The expected values live in tests/fixtures/engagement-golden.json;
 * run with RECORD_ENGAGEMENT_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/agenda-live-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\EngagementService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Golden test for the engagement records.
 *
 * @covers \OCA\Decidiq\Service\EngagementService
 */
class EngagementGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/engagement-golden.json';

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
	public function testEveryEngagementCallMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$service = new EngagementService($this->logger(), $this->objects(case: $case));
			try {
				$result = ['returns' => ($case['call'])($service)];
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = $result + ['calls' => $this->log];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_ENGAGEMENT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryEngagementCallMatchesTheRecording()

	/**
	 * The cases: a call and how the object service behaves.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$capture = static fn (string $type, array $data) =>
			static fn (EngagementService $s): array => $s->captureEngagement('m-1', 'p-anna', $type, $data);
		$meeting = static fn (EngagementService $s): array => $s->findEngagementForMeeting('m-1');
		$score = static fn (array $record) => static fn (EngagementService $s): int => $s->calculateScore($record);
		$existing = ['id' => 'e-1', 'meeting' => 'm-1', 'participant' => 'p-anna', 'speeches' => [['duration' => 30]], 'questionsRaised' => [], 'topicsSuggested' => [[]], 'speakingDuration' => '30', 'engagementScore' => 7];
		return [
			'speech-new'          => ['call' => $capture('speech', ['duration' => 90])],
			'speech-times'        => ['call' => $capture('speech', ['startTime' => '2026-10-08T19:30:00+02:00', 'endTime' => '2026-10-08T19:34:10+02:00'])],
			'speech-reversed'     => ['call' => $capture('speech', ['startTime' => '2026-10-08T19:30:00Z', 'endTime' => '2026-10-08T19:00:00Z'])],
			'speech-bad-times'    => ['call' => $capture('speech', ['startTime' => 'soon', 'endTime' => 'later'])],
			'speech-one-time'     => ['call' => $capture('speech', ['startTime' => '2026-10-08T19:30:00Z'])],
			'speech-zero'         => ['call' => $capture('speech', ['duration' => '0', 'startTime' => '2026-10-08T19:30:00Z', 'endTime' => '2026-10-08T19:31:00Z'])],
			'speech-negative'     => ['call' => $capture('speech', ['duration' => -5, 'startTime' => '2026-10-08T19:30:00Z', 'endTime' => '2026-10-08T19:31:00Z'])],
			'speech-existing'     => ['call' => $capture('speech', ['duration' => 600]), 'rows' => [$existing]],
			'question-entity'     => ['call' => $capture('question', ['text' => 'Waarom?']), 'rows' => ['entity']],
			'topic-skips'         => ['call' => $capture('topic', ['topic' => 'Groen']), 'rows' => ['not a row', $existing]],
			'unknown-type'        => ['call' => $capture('applause', [])],
			'lookup-throws'       => ['call' => $capture('question', []), 'findAll' => 'throw'],
			'meeting-rows'        => ['call' => $meeting, 'rows' => ['entity', $existing, 'not a row', 7, ['id' => 'e-2']]],
			'meeting-empty'       => ['call' => $meeting, 'rows' => []],
			'meeting-throws'      => ['call' => $meeting, 'findAll' => 'throw'],
			'score-empty'         => ['call' => $score([])],
			'score-capped'        => ['call' => $score(['speakingDuration' => 6000, 'speeches' => [1], 'questionsRaised' => [1, 2], 'topicsSuggested' => [1]])],
			'score-mixed'         => ['call' => $score(['speakingDuration' => '150', 'speeches' => 'x', 'questionsRaised' => [1], 'topicsSuggested' => null])],
			'score-negative'      => ['call' => $score(['speakingDuration' => -6000])],
		];
	}//end cases()

	/**
	 * The object service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(array $case): ObjectServiceInterface {
		$entity = $this->entity(row: ['id' => 'e-9', 'meeting' => 'm-1', 'participant' => 'p-anna', 'speeches' => [], 'questionsRaised' => [['text' => 'Eerder']]]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		foreach (['setRegister', 'setSchema'] as $method) {
			$objects->method($method)->willReturnCallback(
				function (mixed ...$arguments) use ($method, $objects): ObjectServiceInterface {
					$this->log[] = [$method => $arguments[0]];
					return $objects;
				}
			);
		}

		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($case, $entity): array {
				$this->log[] = ['findAll' => $config];
				if (($case['findAll'] ?? '') === 'throw') {
					throw new RuntimeException('query down');
				}

				return array_map(static fn (mixed $row): mixed => $row === 'entity' ? $entity : $row, ($case['rows'] ?? []));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments): ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'saveObject', $arguments);
				$this->log[] = ['saveObject' => $arguments];
				return $this->entity(row: ['id' => ($arguments['uuid'] ?? 'e-new')] + $arguments['object']);
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
	private function named(string $class, string $method, array $arguments): array {
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
