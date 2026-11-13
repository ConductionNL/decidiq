<?php

/**
 * Characterisation tests for ConsultationAutoCloseJob::run: the log lines,
 * paging, and what a failed dependency, fetch or close does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\ConsultationAutoCloseJob;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins the run of the auto-close job step by step.
 */
class ConsultationAutoCloseRunTest extends TestCase {
	/**
	 * Everything the job logged, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The offsets findAll was asked for.
	 *
	 * @var array<int, int>
	 */
	private array $offsets = [];

	/**
	 * The ids the lifecycle service was asked to close.
	 *
	 * @var array<int, string>
	 */
	private array $closed = [];

	/**
	 * Build and run the job.
	 *
	 * @param callable|null $pages  Returns the page for an offset, or throws.
	 * @param bool          $broken Whether the container fails to resolve the lifecycle service.
	 *
	 * @return void
	 */
	private function runJob(?callable $pages, bool $broken = false): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($pages) {
				$this->offsets[] = $config['offset'];
				return $pages($config['offset']);
			}
		);

		$lifecycle = $this->createMock(ParticipationLifecycleService::class);
		$lifecycle->method('transitionConsultation')->willReturnCallback(
			function (string $consultationId, string $newStatus) {
				if ($consultationId === 'c-fails') {
					throw new RuntimeException('locked');
				}

				$this->closed[] = $consultationId . ':' . $newStatus;
				return [];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $lifecycle, $broken) {
				if ($id === ParticipationLifecycleService::class) {
					if ($broken === true) {
						throw new RuntimeException('no lifecycle');
					}

					return $lifecycle;
				}

				return $objectService;
			}
		);

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * @param ConsultationAutoCloseRunTest $test The test collecting the lines.
			 */
			public function __construct(private ConsultationAutoCloseRunTest $test) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->record(level: (string)$level, message: (string)$message, context: $context);
			}
		};

		$job = new ConsultationAutoCloseJob(time: $this->createMock(ITimeFactory::class), container: $container, logger: $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Collect one log line.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 *
	 * @return void
	 */
	public function record(string $level, string $message, array $context): void {
		$this->logged[] = [$level, $message, $context];
	}//end record()

	/**
	 * An entity serialising to the given consultation.
	 *
	 * @param array<string, mixed> $data The consultation.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Unavailable dependencies log a warning and stop before any fetch.
	 *
	 * @return void
	 */
	public function testUnavailableDependenciesSkipTheRun(): void {
		$this->runJob(pages: fn (int $offset) => [], broken: true);

		self::assertSame(
			[
				['info', 'Decidiq: ConsultationAutoCloseJob started', []],
				['warning', 'Decidiq ConsultationAutoCloseJob: dependencies unavailable, skipping.', ['exception' => 'no lifecycle']],
			],
			$this->logged
		);
		self::assertSame([], $this->offsets);
	}//end testUnavailableDependenciesSkipTheRun()

	/**
	 * A failed fetch is logged and the run still reports its tally.
	 *
	 * @return void
	 */
	public function testAFailedFetchEndsTheRunWithItsTally(): void {
		$this->runJob(pages: function (int $offset) {
			throw new RuntimeException('db down');
		});

		self::assertSame(
			[
				['info', 'Decidiq: ConsultationAutoCloseJob started', []],
				['error', 'Decidiq ConsultationAutoCloseJob: failed to fetch consultations', ['offset' => 0, 'exception' => 'db down']],
				['info', 'Decidiq ConsultationAutoCloseJob: closed 0 consultations (0 errors)', []],
			],
			$this->logged
		);
	}//end testAFailedFetchEndsTheRunWithItsTally()

	/**
	 * A full page fetches the next one; expired ones close, failures count,
	 * and undated, future and id-less ones are skipped.
	 *
	 * @return void
	 */
	public function testPagesAreWalkedAndEveryOutcomeIsTallied(): void {
		$full = [];
		for ($i = 0; $i < 99; $i++) {
			$full[] = $this->entity(['id' => 'u' . $i]);
		}

		$full[] = $this->entity(['uuid' => 'c-uuid', 'submissionDeadline' => '2001-01-01T00:00:00+00:00']);
		$second = [
			$this->entity(['id' => 'c-fails', 'submissionDeadline' => '2001-01-01T00:00:00+00:00']),
			$this->entity(['id' => 'c-future', 'submissionDeadline' => '2999-01-01T00:00:00+00:00']),
			$this->entity(['submissionDeadline' => '2001-01-01T00:00:00+00:00']),
			$this->entity(['id' => 'c-bad', 'submissionDeadline' => 'not a date']),
		];

		$this->runJob(pages: fn (int $offset) => ($offset === 0 ? $full : $second));

		self::assertSame([0, 100], $this->offsets);
		self::assertSame(['c-uuid:closed'], $this->closed);
		self::assertSame(
			[
				['info', 'Decidiq: ConsultationAutoCloseJob started', []],
				['error', 'Decidiq ConsultationAutoCloseJob: failed to close consultation', ['uuid' => 'c-fails', 'exception' => 'locked']],
				['info', 'Decidiq ConsultationAutoCloseJob: closed 1 consultations (1 errors)', []],
			],
			$this->logged
		);
	}//end testPagesAreWalkedAndEveryOutcomeIsTallied()
}//end class
