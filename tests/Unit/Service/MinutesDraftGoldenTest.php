<?php

/**
 * Golden test for how MinutesGenerationService gathers a minutes draft.
 *
 * Runs generateDraft over meetings whose related objects come back in pages
 * (a full first page and a short second one, a page that throws, entities,
 * arrays and rows that are neither, an empty meeting id), with motions read
 * through the decisionType filter, agenda items out of order, and attendance
 * records with participant names found, missing or unreadable. It records
 * every object-service call by parameter name, every log line and every
 * argument handed to the renderer. The expected values live in
 * tests/fixtures/minutes-draft-golden.json; run with
 * RECORD_MINUTES_DRAFT_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Service\MinutesDraftRenderer;
use OCA\Decidiq\Service\MinutesGenerationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Golden test for gathering a minutes draft.
 *
 * @covers \OCA\Decidiq\Service\MinutesGenerationService
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
 * @uses   \OCA\Decidiq\Service\MinutesRecordReader
 * @uses   \OCA\Decidiq\Exception\MissingRelationException
 */
class MinutesDraftGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-draft-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The case being run.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * Every draft gathers as recorded.
	 *
	 * @return void
	 */
	public function testEveryDraftMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log  = [];
			$this->case = $case;
			try {
				$returns = ['returns' => $this->service()->generateDraft('min-1')];
			} catch (Throwable $e) {
				$returns = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = $returns + ['calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MINUTES_DRAFT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryDraftMatchesTheRecording()

	/**
	 * The cases: the minutes, and what each related-object page answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$items = [];
		for ($i = 0; $i < 100; $i++) {
			$items[] = ['row' => ['id' => 'ai-' . $i, 'orderNumber' => ((($i * 37) % 100) + 1)], 'entity' => true];
		}

		return [
			'no-meeting'       => ['minutes' => ['title' => 'Raad']],
			'empty-meeting-id' => ['minutes' => ['title' => 'Raad', 'meeting' => ['title' => 'Embedded']]],
			'paged'            => [
				'minutes' => ['title' => 'Raad', 'meeting' => 'm-1'],
				'pages'   => [
					'agenda-item:0'   => $items,
					'agenda-item:100' => [['row' => ['id' => 'ai-x', 'orderNumber' => 0], 'entity' => true], ['row' => ['id' => 'ai-y'], 'entity' => true], 'junk'],
					'decision+motion:0' => [['row' => ['id' => 'd-1', 'decisionType' => 'motion'], 'entity' => true]],
					'decision:0'      => [['row' => ['id' => 'd-1', 'decisionType' => 'motion'], 'entity' => true], ['row' => ['id' => 'd-2', 'orderNumber' => 4], 'entity' => true]],
					'voting-round:0'  => 'throw',
				],
			],
			'array-row'        => [
				'minutes' => ['title' => 'Raad', 'meeting' => 'm-1'],
				'pages'   => ['decision:0' => [['row' => ['id' => 'd-2'], 'entity' => false]]],
			],
			'second-page-fails' => [
				'minutes' => ['title' => 'Raad', 'meeting' => 'm-1'],
				'pages'   => [
					'agenda-item:0'   => $items,
					'agenda-item:100' => 'throw',
					'voting-round:0'  => [['row' => ['id' => 'v-1'], 'entity' => true]],
				],
			],
			'attendance'       => [
				'minutes'      => ['title' => 'Raad', 'meeting' => 'm-1'],
				'attendance'   => [
					['meeting' => 'mt-m-1', 'participant' => 'p-1', 'status' => 'present'],
					['meeting' => 'mt-m-1', 'participant' => 'p-2', 'status' => 'absent'],
					['meeting' => 'mt-m-1', 'participant' => 'p-3', 'status' => 'present'],
				],
				'participants' => [['id' => 'p-1', 'displayName' => 'Anna'], ['id' => 'p-3', 'displayName' => ''], ['displayName' => 'Zonder id']],
			],
			'attendance-names-fail' => [
				'minutes'      => ['title' => 'Raad', 'meeting' => 'm-1'],
				'attendance'   => [['meeting' => 'mt-m-1', 'participant' => 'p-1', 'status' => 'present']],
				'participants' => 'throw',
			],
			'attendance-none'  => [
				'minutes'      => ['title' => 'Raad', 'meeting' => 'm-1'],
				'participants' => [['id' => 'p-1', 'displayName' => 'Anna']],
			],
		];
	}//end cases()

	/**
	 * The service over the current case.
	 *
	 * @return MinutesGenerationService
	 */
	private function service(): MinutesGenerationService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnCallback(fn (...$a) => $this->call(method: 'setRegister', args: $a, objects: $objects));
		$objects->method('setSchema')->willReturnCallback(fn (...$a) => $this->call(method: 'setSchema', args: $a, objects: $objects));
		$objects->method('find')->willReturnCallback(fn (...$a) => $this->call(method: 'find', args: $a, objects: $objects));
		$objects->method('findAll')->willReturnCallback(fn (...$a) => $this->call(method: 'findAll', args: $a, objects: $objects));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$renderer = $this->createMock(MinutesDraftRenderer::class);
		$renderer->method('render')->willReturnCallback(
			function (...$a): string {
				$named = $this->named(class: MinutesDraftRenderer::class, method: 'render', args: $a);
				foreach (['agendaItems', 'motions', 'votingRounds', 'decisions'] as $part) {
					$named['parts'][$part] = array_map(static fn (array $row): string => (string)($row['id'] ?? '?') . '#' . (string)($row['orderNumber'] ?? '-'), $named['parts'][$part]);
				}

				$this->log[] = ['render' => $named];
				return 'draft';
			}
		);

		return new MinutesGenerationService(logger: $logger, renderer: $renderer, objectService: $objects);
	}//end service()

	/**
	 * Record an object-service call and answer it.
	 *
	 * @param string                 $method  The method called
	 * @param array<mixed>           $args    The positional arguments
	 * @param ObjectServiceInterface $objects The double, answered by the setters
	 *
	 * @return mixed The answer.
	 */
	private function call(string $method, array $args, ObjectServiceInterface $objects): mixed {
		$named       = $this->named(class: ObjectServiceInterface::class, method: $method, args: $args);
		$this->log[] = [$method => $named];
		if ($method === 'find') {
			$id = (string)($named['id'] ?? '');
			if ($id === 'min-1') {
				return $this->entity(data: $this->case['minutes']);
			}

			return $this->entity(data: ['id' => 'mt-' . $id, 'title' => 'Fetched']);
		}

		if ($method === 'findAll') {
			return $this->findAll(config: ($named['config'] ?? []));
		}

		return $objects;
	}//end call()

	/**
	 * What findAll answers for one query.
	 *
	 * @param array<string, mixed> $config The query
	 *
	 * @return array<mixed>
	 */
	private function findAll(array $config): array {
		$schema = (string)($config['filters']['schema'] ?? '');
		if ($schema === 'meeting-attendance') {
			return ($this->case['attendance'] ?? []);
		}

		if ($schema === 'participant') {
			if (($this->case['participants'] ?? []) === 'throw') {
				throw new RuntimeException('participants down');
			}

			return array_map(fn (array $row): ObjectEntity => $this->entity(data: $row), ($this->case['participants'] ?? []));
		}

		$key  = $schema . (isset($config['filters']['decisionType']) === true ? '+' . $config['filters']['decisionType'] : '') . ':' . (string)($config['offset'] ?? '');
		$page = ($this->case['pages'][$key] ?? ($this->case['pages'][$schema . ':' . (string)($config['offset'] ?? '')] ?? []));
		if ($page === 'throw') {
			throw new RuntimeException('page down');
		}

		return array_map(
			fn (mixed $row): mixed => (is_array($row) === true && $row['entity'] === true) ? $this->entity(data: $row['row']) : ($row['row'] ?? $row),
			$page
		);
	}//end findAll()

	/**
	 * An entity double carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getObject', 'jsonSerialize'])->getMock();
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Name positional mock arguments by the loaded method's parameters, leaving out defaults.
	 *
	 * @param string       $class  The class or interface
	 * @param string       $method The method
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return array<string, mixed> The named arguments.
	 */
	private function namedAsDeclared(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end namedAsDeclared()

	/**
	 * The arguments by name; a saveObject() call is expanded back into its old
	 * parameter names, so the recording describes the write, not the call shape.
	 *
	 * @param mixed $class See namedAsDeclared()
	 * @param mixed $method See namedAsDeclared()
	 * @param mixed $args See namedAsDeclared()
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $args): array {
		$named = $this->namedAsDeclared($class, $method, $args);
		if ($method === 'saveObject') {
			return SaveCallShape::legacyNamed(named: $named);
		}

		return $named;
	}//end named()
}//end class
