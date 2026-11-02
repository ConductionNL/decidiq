<?php

/**
 * Golden test for the translation queue of MultilingualReconciliationService.
 *
 * Runs queue, status and processQueue over requests, queue rows, minutes and
 * adapter answers (unsupported or duplicate locales, an unavailable object
 * service, saves and lookups that throw, malformed entries, missing minutes,
 * failed and successful translations) and records in order every
 * object-service call, every translation request and every log line, plus
 * what each call returns. Timestamps are masked. The expected values live in
 * tests/fixtures/translation-queue-golden.json; run with
 * RECORD_TRANSLATION_QUEUE_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ITranslationAdapter;
use OCA\Decidiq\Service\MultilingualReconciliationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden test for the translation queue.
 *
 * @covers \OCA\Decidiq\Service\MultilingualReconciliationService
 * @uses   \OCA\Decidiq\Service\LogTranslationAdapter
 * @uses   \OCA\Decidiq\Service\TranslationQueueEntryProcessor
 * @uses   \OCA\Decidiq\Support\FleetAppId
 */
class TranslationQueueGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/translation-queue-golden.json';

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
	public function testEveryQueueCallMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$service = $this->service(case: $case);
			$actual[$name] = ['returns' => ($case['call'])($service), 'calls' => $this->log];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '<now>', $json);
		if (getenv('RECORD_TRANSLATION_QUEUE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryQueueCallMatchesTheRecording()

	/**
	 * The cases: a call and how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$queue = static fn (string $id, string $source, array $targets) =>
			static fn (MultilingualReconciliationService $s): array => $s->queue($id, $source, $targets);
		$status = static fn (int $limit) => static fn (MultilingualReconciliationService $s): array => $s->status($limit);
		$process = static fn (int $max) => static fn (MultilingualReconciliationService $s): array => $s->processQueue($max);
		$rows = [
			['id' => 'q1', 'minutesKoppeling' => 'm1', 'sourceLocale' => 'nl', 'targetLocale' => 'en', 'status' => 'queued', 'attempts' => 0],
			['id' => 'q2', 'minutesKoppeling' => 'm-gone', 'sourceLocale' => 'nl', 'targetLocale' => 'de', 'status' => 'queued'],
			['id' => '', 'minutesKoppeling' => 'm1', 'sourceLocale' => 'nl', 'targetLocale' => 'fr', 'status' => 'queued'],
			['id' => 'q4', 'minutesKoppeling' => 'm1', 'sourceLocale' => '', 'targetLocale' => 'fr', 'status' => 'queued', 'attempts' => '2'],
			'entity:q5',
			['id' => 'q6', 'minutesKoppeling' => 'm-throw', 'sourceLocale' => 'en', 'targetLocale' => 'nl', 'status' => 'failed', 'attempts' => 3],
			['id' => 'q7', 'minutesKoppeling' => 'm2', 'sourceLocale' => 'nl', 'targetLocale' => 'fr', 'status' => 'completed'],
			'not a row',
			['id' => 'q8', 'status' => 'odd'],
		];
		return [
			'queue-no-id'          => ['call' => $queue('', 'nl', ['en'])],
			'queue-bad-source'     => ['call' => $queue('m1', 'xx', ['en'])],
			'queue-upper-source'   => ['call' => $queue('m1', 'NL', ['EN', 'nl', 'de', 'en', 'zz', 7])],
			'queue-no-targets'     => ['call' => $queue('m1', 'nl', ['nl', 'zz'])],
			'queue-no-container'   => ['call' => $queue('m1', 'nl', ['en']), 'container' => 'throw'],
			'queue-save-throws'    => ['call' => $queue('m1', 'nl', ['en', 'fr']), 'save' => 'throw'],
			'queue-save-ok'        => ['call' => $queue('m1', 'fr', ['nl', 'de'])],
			'status-zero-limit'    => ['call' => $status(0), 'rows' => $rows],
			'status-limit'         => ['call' => $status(3), 'rows' => $rows],
			'status-throws'        => ['call' => $status(5), 'rows' => 'throw'],
			'process-no-container' => ['call' => $process(5), 'container' => 'throw'],
			'process-load-throws'  => ['call' => $process(-1), 'rows' => 'throw'],
			'process-empty'        => ['call' => $process(4), 'rows' => []],
			'process-zero-max'     => ['call' => $process(0), 'rows' => []],
			'process-all'          => ['call' => $process(10), 'rows' => $rows],
			'process-translate-no' => ['call' => $process(10), 'rows' => $rows, 'translate' => 'fail'],
			'process-saves-throw'  => ['call' => $process(10), 'rows' => $rows, 'save' => 'throw'],
			'process-no-adapter'   => ['call' => $process(1), 'rows' => [$rows[0]], 'adapter' => 'none'],
			'process-di-adapter'   => ['call' => $process(1), 'rows' => [$rows[0]], 'adapter' => 'container'],
		];
	}//end cases()

	/**
	 * The service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MultilingualReconciliationService
	 */
	private function service(array $case): MultilingualReconciliationService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($case): ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'saveObject', $arguments);
				$this->log[] = ['saveObject' => $arguments];
				if (($case['save'] ?? '') === 'throw') {
					throw new RuntimeException('save down');
				}

				return $this->entity(row: ['id' => 'saved-' . count($this->log)] + $arguments['object']);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->log[] = ['findAll' => $config];
				if (($case['rows'] ?? []) === 'throw') {
					throw new RuntimeException('query down');
				}

				return array_map(
					fn (mixed $row): mixed => (is_string($row) === true && str_starts_with($row, 'entity:') === true)
						? $this->entity(row: ['id' => substr($row, 7), 'minutesKoppeling' => 'm2', 'sourceLocale' => 'nl', 'targetLocale' => 'en', 'status' => 'queued'])
						: $row,
					($case['rows'] ?? [])
				);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$arguments): ?ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'find', $arguments);
				$this->log[] = ['find' => $arguments];
				if ($arguments['id'] === 'm-throw') {
					throw new RuntimeException('find down');
				}

				$minutes = [
					'm1' => ['id' => 'm1', 'meetingIntegration' => 'mt-1', 'language' => 'nl', 'content' => 'Notulen'],
					'm2' => ['id' => 'm2', 'content' => 'Kort'],
				];
				return isset($minutes[$arguments['id']]) === true ? $this->entity(row: $minutes[$arguments['id']]) : null;
			}
		);

		$adapter = $this->createMock(ITranslationAdapter::class);
		$adapter->method('translate')->willReturnCallback(
			function (string $text, string $source, string $target) use ($case): array {
				$this->log[] = ['translate' => [$text, $source, $target]];
				if (($case['translate'] ?? '') === 'fail') {
					return ['success' => false, 'provider' => 'p', 'message' => 'no budget', 'text' => ''];
				}

				return ['success' => true, 'provider' => 'p', 'message' => 'ok', 'text' => '[' . $target . '] ' . $text];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objects, $adapter): object {
				$this->log[] = ['container' => $id];
				if (($case['container'] ?? '') === 'throw' || ($id === ITranslationAdapter::class && ($case['adapter'] ?? '') === 'none')) {
					throw new RuntimeException('not bound');
				}

				return $id === ITranslationAdapter::class ? $adapter : $objects;
			}
		);

		$service = new MultilingualReconciliationService($container, $this->logger());
		if (in_array(($case['adapter'] ?? ''), ['none', 'container'], true) === false) {
			$service->setAdapter($adapter);
		}

		return $service;
	}//end service()

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
