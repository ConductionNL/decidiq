<?php

/**
 * Golden test for generating a minutes draft from a transcript.
 *
 * Runs MinutesDraftService::generate() over a transcript with a meeting and
 * an agenda timeline, one without a meeting, one whose segments are not a
 * list, a missing transcript, no AI provider, no preferred provider id, a
 * prompt run that fails and related reads that fail. It records every prompt
 * handed to TaskProcessing, every object read and write by parameter name and
 * the draft returned (timestamps masked). The expected values live in
 * tests/fixtures/minutes-draft-generate-golden.json; run with
 * RECORD_MINUTES_GENERATE_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MinutesDraftComposer;
use OCA\Decidiq\Service\MinutesDraftService;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden test for MinutesDraftService::generate().
 *
 * @covers \OCA\Decidiq\Service\MinutesDraftService
 * @covers \OCA\Decidiq\Service\MinutesDraftComposer
 * @uses   \OCA\Decidiq\Service\MinutesDraftRun
 * @uses   \OCA\Decidiq\Service\MinutesOutcomes
 * @uses   \OCA\Decidiq\Service\DraftProvenance
 * @uses   \OCA\Decidiq\Exception\MissingObjectException
 */
class MinutesDraftGenerateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-draft-generate-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every generate() run matches the recording.
	 *
	 * @return void
	 */
	public function testEveryGenerateRunMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			try {
				$draft = $this->service(case: $case)->generate(transcriptId: $case['transcriptId']);
				$times = array_unique(array_merge([$draft['provenance']['generatedAt']], array_map(static fn (array $section): string => $section['provenance']['generatedAt'], $draft['sections'])));
				$returns = ['draft' => $draft, 'oneGenerationTime' => count($times) === 1];
			} catch (\Throwable $e) {
				$returns = ['throws' => [get_class($e), $e->getMessage(), $e->getCode()]];
			}

			$actual[$name] = $this->masked(value: ['returns' => $returns, 'calls' => $this->log]);
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MINUTES_GENERATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryGenerateRunMatchesTheRecording()

	/**
	 * The cases: the transcript id, the stored objects and the provider behaviour.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$transcript = [
			'id' => 't-1',
			'meeting' => 'm-1',
			'segments' => [
				['speakerLabel' => 'Voorzitter', 'text' => 'Welkom.', 'agendaItem' => 'ai-1'],
				['speakerLabel' => 'Lid', 'text' => 'Over de begroting.', 'agendaItem' => 'ai-2'],
				['text' => 'Zonder label.', 'agendaItem' => 'ai-2'],
			],
		];
		$related = [
			'agenda-item' => [['id' => 'ai-1', 'title' => 'Opening'], ['id' => 'ai-2', 'title' => 'Begroting']],
			'voting-round' => [['id' => 'v-1', 'title' => 'Stemming begroting', 'result' => 'aangenomen', 'agendaItem' => 'ai-2']],
			'decision' => [['id' => 'd-1', 'title' => 'Begroting 2027', 'agendaItem' => 'ai-2'], ['id' => 'd-2', 'title' => 'Losse motie']],
		];
		$base = [
			'transcriptId' => 't-1',
			'objects' => ['t-1' => $transcript],
			'related' => $related,
			'providers' => true,
			'providerId' => 'llm-local',
			'summaries' => ['Opening gedaan.', 'De begroting 2027 is aangenomen, en de losse motie.'],
		];

		return [
			'meeting-with-timeline' => $base,
			'no-meeting' => ['objects' => ['t-1' => ['id' => 't-1', 'segments' => [['speakerLabel' => 'A', 'text' => 'Alles.']]]]] + $base,
			'meeting-as-relation' => ['objects' => ['t-1' => ['relations' => ['meeting' => 'm-1']] + array_diff_key($transcript, ['meeting' => true])]] + $base,
			'segments-not-a-list' => ['objects' => ['t-1' => ['segments' => 'kapot'] + $transcript]] + $base,
			'no-timeline' => ['objects' => ['t-1' => ['segments' => [['speakerLabel' => 'A', 'text' => 'Een.']]] + $transcript]] + $base,
			'transcript-missing' => ['transcriptId' => 't-9'] + $base,
			'no-provider' => ['providers' => false] + $base,
			'no-provider-id' => ['providerId' => null] + $base,
			'prompt-fails' => ['summaries' => ['Opening gedaan.', new RuntimeException('provider down')]] + $base,
			'related-reads-fail' => ['related' => null] + $base,
		];
	}//end cases()

	/**
	 * The service over a recording container.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MinutesDraftService
	 */
	private function service(array $case): MinutesDraftService {
		$manager = $this->createMock(IManager::class);
		$manager->method('hasProviders')->willReturn($case['providers']);
		$manager->method('getPreferredProvider')->willReturnCallback(
			static function () use ($case): ?object {
				if ($case['providerId'] === null) {
					return null;
				}

				return new class($case['providerId']) {
					/**
					 * @param string $id The provider id
					 */
					public function __construct(private readonly string $id) {
					}

					/**
					 * @return string
					 */
					public function getId(): string {
						return $this->id;
					}
				};
			}
		);
		$prompts = 0;
		$manager->method('runTask')->willReturnCallback(
			function (Task $task) use ($case, &$prompts): Task {
				$this->log[] = ['prompt' => [$task->getTaskTypeId(), $task->getInput(), $task->getAppId(), $task->getUserId()]];
				$summary = ($case['summaries'][$prompts] ?? 'Samenvatting ' . ($prompts + 1));
				$prompts++;
				if ($summary instanceof \Throwable) {
					throw $summary;
				}

				$task->setOutput(['output' => $summary]);
				return $task;
			}
		);

		$store = $this->store(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				IManager::class => $manager,
				MinutesDraftComposer::class => new MinutesDraftComposer(),
				'OCA\OpenRegister\Service\ObjectService' => $store,
				default => throw new RuntimeException('unexpected service ' . $id),
			}
		);

		return new MinutesDraftService(container: $container, logger: $this->logger());
	}//end service()

	/**
	 * A recording object store over the case's objects.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return object
	 */
	private function store(array $case): object {
		$log = function (array $line): void {
			$this->log[] = $line;
		};

		return new class($case, $log) {
			/**
			 * @param array<string, mixed> $case The case
			 * @param \Closure $log The call recorder
			 */
			public function __construct(private readonly array $case, private readonly \Closure $log) {
			}

			/**
			 * @param int|string $id The object id
			 * @param mixed $register The register
			 * @param mixed $schema The schema
			 *
			 * @return object|null
			 */
			public function find(int|string $id, mixed $register = null, mixed $schema = null): ?object {
				($this->log)(['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]]);
				$data = ($this->case['objects'][$id] ?? null);
				if ($data === null) {
					return null;
				}

				return new class($data) {
					/**
					 * @param array<string, mixed> $data The data
					 */
					public function __construct(private readonly array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}

			/**
			 * @param array<string, mixed> $config The query
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				($this->log)(['findAll' => $config]);
				if ($this->case['related'] === null) {
					throw new RuntimeException('read failed');
				}

				return ($this->case['related'][$config['schema'] ?? ''] ?? []);
			}

			/**
			 * @param array<string, mixed> $object The object
			 * @param mixed $register The register
			 * @param mixed $schema The schema
			 * @param string|null $uuid The uuid
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): array {
				($this->log)(['save' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]]);
				return $object;
			}
		};
	}//end store()

	/**
	 * A logger that records each line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$sink = function (array $line): void {
			$this->log[] = ['log' => $line];
		};

		return new class($sink) extends AbstractLogger {
			/**
			 * @param \Closure $sink The line recorder
			 */
			public function __construct(private readonly \Closure $sink) {
			}

			/**
			 * @param mixed $level The level
			 * @param string|Stringable $message The message
			 * @param array<string, mixed> $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				($this->sink)([$level, (string)$message, $context]);
			}
		};
	}//end logger()

	/**
	 * The value with every ISO timestamp masked.
	 *
	 * @param array<string, mixed> $value The value
	 *
	 * @return array<string, mixed>
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item): void {
				if (is_string($item) === true) {
					$item = (string)preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)?/', '<time>', $item);
				}
			}
		);

		return $value;
	}//end masked()
}//end class
