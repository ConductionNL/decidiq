<?php

/**
 * Golden test for MeetingPackageService.
 *
 * Runs assemble and buildTableOfContents over meetings, agenda items and
 * Files behaviours (lookups and queries that throw, rows that are entities,
 * arrays, foreign or not rows at all, a meeting folder that cannot be made,
 * folders, file listings, writes and reads that throw, files that already
 * exist, PDF renditions) and records in order every object-service call,
 * every Files call and every log line, plus what each call returns. The
 * expected values live in tests/fixtures/meeting-package-golden.json; run
 * with RECORD_MEETING_PACKAGE_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\MeetingPackageService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden test for the meeting package.
 *
 * @covers \OCA\Decidiq\Service\MeetingPackageService
 * @uses   \OCA\Decidiq\Service\PaperRenditionFilter
 */
class MeetingPackageGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/meeting-package-golden.json';

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
	public function testEveryPackageMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$service = $this->service(case: $case);
			$actual[$name] = ['returns' => $service->assemble($case['id'] ?? 'mt-1', 'anna'), 'calls' => $this->log];
		}

		$service = $this->service(case: []);
		$actual['toc'] = [
			'empty' => $service->buildTableOfContents([], []),
			'full'  => $service->buildTableOfContents(['title' => 'Raad', 'scheduledDate' => '2026-10-08T19:30:00+02:00'], array_filter($this->items(), 'is_array')),
		];

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_MEETING_PACKAGE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryPackageMatchesTheRecording()

	/**
	 * The cases: how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'container-throws'  => ['objects' => 'throw'],
			'find-throws'       => ['find' => 'throw'],
			'not-found'         => ['id' => 'gone'],
			'items-throw'       => ['findAll' => 'throw'],
			'no-folder'         => ['folder' => null],
			'files-unavailable' => ['files' => 'throw'],
			'package-folder'    => ['createFolder' => 'Meeting package'],
			'item-folder'       => ['createFolder' => 'Begroting'],
			'late-item-folder'  => ['createFolder' => 'Geen id'],
			'numeric-links'     => ['id' => '7', 'rows' => [['id' => 'n1', 'title' => 'Num', 'meeting' => 7], ['id' => 'n2', 'title' => 'Other', 'meeting' => 8], ['id' => 'n3', 'title' => 'Linked', 'meeting' => ['id' => 7]]]],
			'full'              => [],
			'existing-files'    => ['newFile' => 'throw'],
			'unwritable'        => ['newFile' => 'throw', 'get' => 'throw'],
			'unreadable'        => ['content' => 'throw'],
			'no-items'          => ['rows' => []],
		];
	}//end cases()

	/**
	 * The agenda item rows the object service answers with.
	 *
	 * @return array<int, mixed>
	 */
	private function items(): array {
		return [
			['id' => 'i3', 'title' => 'Begroting: 2027 / "concept"', 'orderNumber' => 3, 'meeting' => 'mt-1', 'itemType' => 'debate', 'estimatedDuration' => 45, 'files' => [['name' => 'a/b.pdf'], 'plain.docx', ['title' => 'T'], ['path' => 'x/y.txt'], 7, []], 'paperRenditions' => [['sourceFileId' => 11, 'pdfFileId' => 12], ['sourceFileId' => 13, 'pdfFileId' => 99], 'bad']],
			['id' => 'i1', 'title' => 'Opening', 'orderNumber' => 1, 'meeting' => ['id' => 'mt-1'], 'estimatedDuration' => 0],
			['id' => 'i9', 'title' => 'Elders', 'meeting' => 'mt-2'],
			['id' => 'i8', 'title' => 'Elders gekoppeld', 'meeting' => ['id' => 'mt-2']],
			['@self' => ['id' => 'i2'], 'title' => ' ... ', 'orderNumber' => '2', 'itemType' => ''],
			['title' => 'Geen id', 'orderNumber' => 4],
			'not a row',
		];
	}//end items()

	/**
	 * The service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MeetingPackageService
	 */
	private function service(array $case): MeetingPackageService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$arguments) use ($case): ?ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'find', $arguments);
				$this->log[] = ['find' => $arguments];
				if (($case['find'] ?? '') === 'throw') {
					throw new RuntimeException('find down');
				}

				return $arguments['id'] === 'gone' ? null : $this->entity(row: ['title' => 'Raad', 'scheduledDate' => '2026-10-08T19:30:00+02:00']);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->log[] = ['findAll' => $config];
				if (($case['findAll'] ?? '') === 'throw') {
					throw new RuntimeException('query down');
				}

				$rows = ($case['rows'] ?? $this->items());
				return array_map(fn (mixed $row): mixed => (is_array($row) === true && ($row['id'] ?? '') === 'i1') ? $this->entity(row: $row) : $row, $rows);
			}
		);

		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('ensureMeetingFolders')->willReturnCallback(
			function (array $meeting) use ($case): ?string {
				$this->log[] = ['ensureMeetingFolders' => $meeting];
				return array_key_exists('folder', $case) === true ? $case['folder'] : 'Decidesk/Raad/2026-10-08';
			}
		);

		$files = $this->files(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objects, $files): object {
				$this->log[] = ['container' => $id];
				$fail = str_contains($id, 'ObjectService') === true ? ($case['objects'] ?? '') : ($case['files'] ?? '');
				if ($fail === 'throw') {
					throw new RuntimeException('not available');
				}

				return str_contains($id, 'ObjectService') === true ? $objects : $files;
			}
		);

		return new MeetingPackageService($container, $this->logger(), $folders);
	}//end service()

	/**
	 * A Files double that records into the call log.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return object
	 */
	private function files(array $case): object {
		$log = &$this->log;
		return new class($log, $case) {
			/**
			 * The shared call log.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $log;

			/**
			 * Record into the given log.
			 *
			 * @param array<int, array<string, mixed>> $log  The call log
			 * @param array<string, mixed>             $case The case
			 */
			public function __construct(array &$log, private readonly array $case) {
				$this->log = &$log;
			}

			/**
			 * Create a folder.
			 *
			 * @param string $path The path
			 *
			 * @return object
			 */
			public function createFolder(string $path): object {
				$this->log[] = ['createFolder' => $path];
				if (isset($this->case['createFolder']) === true && str_contains($path, $this->case['createFolder']) === true) {
					throw new RuntimeException('cannot create ' . $path);
				}

				return new GoldenFolder($this->log, $this->case, $path);
			}

			/**
			 * The files of an object.
			 *
			 * @param string $id The object id
			 *
			 * @return array<int, mixed>
			 */
			public function getFiles(string $id): array {
				$this->log[] = ['getFiles' => $id];
				if ($id === 'i2') {
					throw new RuntimeException('no files');
				}

				if ($id !== 'i3') {
					return [];
				}

				return [
					new GoldenNode($this->log, $this->case, ['id' => 11, 'name' => 'stuk.docx']),
					new GoldenNode($this->log, $this->case, ['id' => 12, 'name' => 'stuk.pdf']),
					new GoldenNode($this->log, $this->case, ['id' => 13, 'name' => 'bijlage.docx']),
					new GoldenPlainNode($this->log, $this->case, ['name' => 'los.txt']),
					new GoldenNameOnlyNode(),
					'not a node',
				];
			}
		};
	}//end files()

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

/**
 * A Files folder double that records into the call log.
 */
class GoldenFolder {

	/**
	 * The shared call log.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log;

	/**
	 * Record into the given log.
	 *
	 * @param array<int, array<string, mixed>> $log  The call log
	 * @param array<string, mixed>             $case The case
	 * @param string                           $path The folder path
	 */
	public function __construct(array &$log, private readonly array $case, private readonly string $path) {
		$this->log = &$log;
	}

	/**
	 * Create a file.
	 *
	 * @param string $name    The name
	 * @param string $content The content
	 *
	 * @return void
	 */
	public function newFile(string $name, string $content): void {
		$this->log[] = ['newFile' => [$this->path, $name, $content]];
		if (($this->case['newFile'] ?? '') === 'throw') {
			throw new RuntimeException('exists');
		}
	}

	/**
	 * An existing file.
	 *
	 * @param string $name The name
	 *
	 * @return object
	 */
	public function get(string $name): object {
		$this->log[] = ['get' => [$this->path, $name]];
		if (($this->case['get'] ?? '') === 'throw') {
			throw new RuntimeException('locked');
		}

		$log = &$this->log;
		return new class($log, $this->path . '/' . $name) {
			/**
			 * The shared call log.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $log;

			/**
			 * Record into the given log.
			 *
			 * @param array<int, array<string, mixed>> $log  The call log
			 * @param string                           $path The file path
			 */
			public function __construct(array &$log, private readonly string $path) {
				$this->log = &$log;
			}

			/**
			 * Overwrite the file.
			 *
			 * @param string $content The content
			 *
			 * @return void
			 */
			public function putContent(string $content): void {
				$this->log[] = ['putContent' => [$this->path, $content]];
			}
		};
	}
}//end class

/**
 * A Files node double without an id.
 */
class GoldenPlainNode {

	/**
	 * The shared call log.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $log;

	/**
	 * Record into the given log.
	 *
	 * @param array<int, array<string, mixed>> $log  The call log
	 * @param array<string, mixed>             $case The case
	 * @param array<string, mixed>             $node The node's id and name
	 */
	public function __construct(array &$log, protected readonly array $case, protected readonly array $node) {
		$this->log = &$log;
	}

	/**
	 * The name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->node['name'];
	}

	/**
	 * The content.
	 *
	 * @return string
	 */
	public function getContent(): string {
		$this->log[] = ['getContent' => $this->node['name']];
		if (($this->case['content'] ?? '') === 'throw' && str_ends_with($this->node['name'], '.pdf') === true) {
			throw new RuntimeException('unreadable');
		}

		return 'content of ' . $this->node['name'];
	}
}//end class

/**
 * A Files node double with an id.
 */
class GoldenNode extends GoldenPlainNode {

	/**
	 * The id.
	 *
	 * @return int
	 */
	public function getId(): int {
		return $this->node['id'];
	}
}//end class

/**
 * A Files node double that has a name but no content.
 */
class GoldenNameOnlyNode {

	/**
	 * The name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'map';
	}
}//end class
