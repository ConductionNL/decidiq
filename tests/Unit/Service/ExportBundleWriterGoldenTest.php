<?php

/**
 * Golden test for ExportBundleWriter.
 *
 * Runs zip and mergedPdf over decisions (titled, untitled, with unsafe and
 * repeated names, with and without ids, with facts that are scalar, empty or
 * not scalar, with every text field), attachments (files, repeated names,
 * nodes that are not files, OpenRegister not installed), PDF pages that render
 * or not, a work folder that exists, is made or is not a folder, filinq merges
 * that queue, finish, fail with or without a reason, or throw with or without
 * a status, and a temporary file that cannot be made. It records every Files
 * call (with the ZIP entries and their contents), every PDF render, every
 * merge call, every background job, every log line and what each call
 * returns or throws. The expected values live in
 * tests/fixtures/export-bundle-writer-golden.json; run with
 * RECORD_EXPORT_BUNDLE_WRITER_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ExportBundleWriter;
use OCA\Decidiq\Support\FilinqPdf;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Golden test for the export bundle writer.
 *
 * @covers \OCA\Decidiq\Service\ExportBundleWriter
 * @uses   \OCA\Decidiq\Exception\ExportBundleException
 */
class ExportBundleWriterGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/export-bundle-writer-golden.json';

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
	 * Temporary files made for the ZIP.
	 *
	 * @var list<string>
	 */
	private array $temp = [];

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Every export writes as recorded.
	 *
	 * @return void
	 */
	public function testEveryExportMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log  = [];
			$this->case = $case;
			$writer     = $this->writer();
			try {
				$returns = ['returns' => match ($case['call']) {
					'zip'    => $writer->zip($this->folder(), 'Export.zip', $case['decisions']),
					'pdf'    => $writer->mergedPdf($this->merger(), $this->folder(), 'Export.pdf', $case['decisions'], 'griffier'),
					'remove' => $writer->removePages('griffier', $case['pages']),
				}];
			} catch (Throwable $e) {
				$returns = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$actual[$name] = $returns + ['calls' => $this->log];
		}

		$actual['titles'] = array_map(static fn (array $d): array => [ExportBundleWriter::titleOf($d), ExportBundleWriter::idOf($d)], $this->shapes());

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_EXPORT_BUNDLE_WRITER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryExportMatchesTheRecording()

	/**
	 * Decision shapes for the title and id readers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function shapes(): array {
		return [
			['title' => '  Motie  ', 'id' => 'd-1'],
			['title' => '   ', '@self' => ['id' => 'd-2']],
			['uuid' => 'd-3'],
			['id' => 7, 'uuid' => 'x'],
			[],
		];
	}//end shapes()

	/**
	 * The decisions most cases export.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function decisions(): array {
		return [
			[
				'id'               => 'd-1',
				'title'            => 'Motie: "a/b" <c>',
				'resolutionNumber' => 'R-1',
				'motionType'       => 'motie',
				'proposer'         => 'Anna & Bob',
				'submittedAt'      => '2026-10-01',
				'decisionDate'     => '',
				'outcome'          => ['niet' => 'scalar'],
				'text'             => "Regel 1\nRegel <2>",
			],
			['@self' => ['id' => 'd-2'], 'title' => 'Motie: "a/b" <c>', 'externalReference' => 'E-2', 'decisionType' => 'besluit', 'fullText' => 'Volledig'],
			['uuid' => 'd-3', 'title' => '...', 'proposedText' => 'Voorstel', 'outcome' => 0],
			['title' => 'Zonder id'],
		];
	}//end decisions()

	/**
	 * The cases: the call and how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$all = $this->decisions();
		return [
			'zip'                => ['call' => 'zip', 'decisions' => $all],
			'zip-no-temp'        => ['call' => 'zip', 'decisions' => $all, 'temp' => ''],
			'zip-no-openregister' => ['call' => 'zip', 'decisions' => $all, 'openregister' => false],
			'pdf-merged'         => ['call' => 'pdf', 'decisions' => $all, 'merge' => ['status' => 'done', 'resultFileId' => 99]],
			'pdf-new-work'       => ['call' => 'pdf', 'decisions' => [$all[0]], 'work' => 'missing', 'merge' => ['status' => 'done']],
			'pdf-work-not-folder' => ['call' => 'pdf', 'decisions' => [$all[0]], 'work' => 'file'],
			'pdf-queued'         => ['call' => 'pdf', 'decisions' => $all, 'queue' => true, 'job' => ['id' => 'job-7']],
			'pdf-queued-uuid'    => ['call' => 'pdf', 'decisions' => [$all[0]], 'queue' => true, 'job' => ['uuid' => 'u-1', 'id' => 'job-7']],
			'pdf-queued-noid'    => ['call' => 'pdf', 'decisions' => [$all[0]], 'queue' => true, 'job' => []],
			'pdf-not-rendered'   => ['call' => 'pdf', 'decisions' => $all, 'render' => [1 => null]],
			'pdf-failed-error'   => ['call' => 'pdf', 'decisions' => [$all[0]], 'merge' => ['status' => 'failed', 'error' => 'kapot', 'reason' => 'niet deze']],
			'pdf-failed-reason'  => ['call' => 'pdf', 'decisions' => [$all[0]], 'merge' => ['status' => 'failed', 'reason' => 'geen rechten']],
			'pdf-failed-bare'    => ['call' => 'pdf', 'decisions' => [$all[0]], 'merge' => []],
			'pdf-throws-status'  => ['call' => 'pdf', 'decisions' => [$all[0]], 'merge' => 'throw-status'],
			'pdf-throws'         => ['call' => 'pdf', 'decisions' => [$all[0]], 'merge' => 'throw'],
			'pdf-no-openregister' => ['call' => 'pdf', 'decisions' => [$all[0]], 'openregister' => false],
			'remove'             => ['call' => 'remove', 'pages' => [11, '12']],
			'remove-none'        => ['call' => 'remove', 'pages' => []],
			'remove-fails'       => ['call' => 'remove', 'pages' => [11], 'root' => 'throw'],
		];
	}//end cases()

	/**
	 * The writer over the current case.
	 *
	 * @return ExportBundleWriter
	 */
	private function writer(): ExportBundleWriter {
		$files = new class($this) {
			/**
			 * Create the file service double.
			 *
			 * @param ExportBundleWriterGoldenTest $test The test making the files
			 */
			public function __construct(private ExportBundleWriterGoldenTest $test) {
			}

			/**
			 * The files of an object.
			 *
			 * @param string $id The object id
			 *
			 * @return array<int, mixed>
			 */
			public function getFiles(string $id): array {
				return $this->test->attachmentsOf(id: $id);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($files): object {
				$this->log[] = ['container' => $id];
				return $files;
			}
		);

		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturnCallback(
			function (string $html, string $title, string $context): ?string {
				$count       = count(array_filter($this->log, static fn (array $c): bool => isset($c['fromHtml'])));
				$this->log[] = ['fromHtml' => [$html, $title, $context]];
				if (array_key_exists($count, ($this->case['render'] ?? [])) === true) {
					return $this->case['render'][$count];
				}

				return '%PDF-' . $count;
			}
		);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (string $postFix): string {
				$this->log[] = ['getTemporaryFile' => $postFix];
				if (array_key_exists('temp', $this->case) === true) {
					return $this->case['temp'];
				}

				$path         = (string)tempnam(sys_get_temp_dir(), 'decidiq-export-golden') . $postFix;
				$this->temp[] = $path;
				return $path;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->log[] = ['jobList.add' => [$job, $argument]];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['warning' => $message, 'context' => $context];
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			function (string $app): bool {
				$this->log[] = ['isInstalled' => $app];
				return ($this->case['openregister'] ?? true);
			}
		);

		return new ExportBundleWriter(
			container: $container,
			pdf: $pdf,
			rootFolder: $this->root(),
			tempManager: $temp,
			jobList: $jobs,
			logger: $logger,
			appManager: $apps,
		);
	}//end writer()

	/**
	 * The files attached to an object: two files with the same name, one with an unsafe name, and a node that is not a file.
	 *
	 * @param string $id The object id
	 *
	 * @return array<int, mixed>
	 */
	public function attachmentsOf(string $id): array {
		$this->log[] = ['getFiles' => $id];
		$number      = (int)substr($id, 2);
		return [
			$this->file(id: ($number * 10 + 1), name: 'bijlage.pdf', content: 'A' . $id),
			$this->file(id: ($number * 10 + 2), name: 'Bijlage.pdf', content: 'B' . $id),
			$this->file(id: ($number * 10 + 3), name: ' ../geheim:*.txt ', content: 'C' . $id),
			$this->createMock(Folder::class),
			'not a node',
		];
	}//end attachmentsOf()

	/**
	 * A file double.
	 *
	 * @param int    $id      The file id
	 * @param string $name    The file name
	 * @param string $content The content
	 *
	 * @return File
	 */
	private function file(int $id, string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		$file->method('getSize')->willReturn(strlen($content) * 100);
		return $file;
	}//end file()

	/**
	 * The exports folder, with its work folder as the case says.
	 *
	 * @return Folder
	 */
	private function folder(): Folder {
		$work = $this->createMock(Folder::class);
		$work->method('getNonExistingName')->willReturnCallback(fn (string $name): string => 'work/' . $name);
		$work->method('newFile')->willReturnCallback(
			function (string $path, mixed $content = null): File {
				$this->log[] = ['work.newFile' => [$path, $content]];
				return $this->file(id: (500 + count($this->log)), name: $path, content: (string)$content);
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(
			function (string $path): bool {
				$this->log[] = ['nodeExists' => $path];
				return ($this->case['work'] ?? 'folder') !== 'missing';
			}
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) use ($work): Folder {
				$this->log[] = ['newFolder' => $path];
				return $work;
			}
		);
		$folder->method('get')->willReturnCallback(
			function (string $path) use ($work): Node {
				$this->log[] = ['get' => $path];
				if (($this->case['work'] ?? 'folder') === 'file') {
					return $this->createMock(File::class);
				}

				return $work;
			}
		);
		$folder->method('getNonExistingName')->willReturnCallback(fn (string $name): string => 'new ' . $name);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, mixed $content = null): File {
				$this->log[] = ['newFile' => [$path, $this->zipEntries(stream: $content)]];
				return $this->file(id: 42, name: $path, content: '');
			}
		);

		return $folder;
	}//end folder()

	/**
	 * The entries and contents of a ZIP handed over as a stream.
	 *
	 * @param mixed $stream The stream
	 *
	 * @return array<string, string>
	 */
	private function zipEntries(mixed $stream): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'decidiq-export-read');
		file_put_contents($path, stream_get_contents($stream));
		$zip     = new ZipArchive();
		$entries = [];
		if ($zip->open($path) === true) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry           = (string)$zip->getNameIndex($i);
				$entries[$entry] = (string)$zip->getFromIndex($i);
			}

			$zip->close();
		}

		unlink($path);
		return $entries;
	}//end zipEntries()

	/**
	 * The root folder: the requester's Files with the rendered pages.
	 *
	 * @return IRootFolder
	 */
	private function root(): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid): Folder {
				$this->log[] = ['getUserFolder' => $uid];
				if (($this->case['root'] ?? null) === 'throw') {
					throw new RuntimeException('no files');
				}

				$user = $this->createMock(Folder::class);
				$user->method('getById')->willReturnCallback(
					function (int $id): array {
						$node = $this->createMock(File::class);
						$node->method('delete')->willReturnCallback(
							function () use ($id): void {
								$this->log[] = ['delete' => $id];
							}
						);
						return [$node];
					}
				);
				return $user;
			}
		);

		return $root;
	}//end root()

	/**
	 * filinq's merge service, as the case says.
	 *
	 * @return object
	 */
	private function merger(): object {
		return new class($this->case, $this->log) {
			/**
			 * Create the merge double.
			 *
			 * @param array<string, mixed>             $case The case
			 * @param array<int, array<string, mixed>> $log  The call log
			 */
			public function __construct(private array $case, private array &$log) {
			}

			/**
			 * Whether the merge goes to the background.
			 *
			 * @param array<int, mixed> $inputs The inputs
			 * @param int               $pages  The page limit
			 *
			 * @return bool
			 */
			public function shouldQueue(array $inputs, int $pages): bool {
				$this->log[] = ['shouldQueue' => [$inputs, $pages]];
				return ($this->case['queue'] ?? false);
			}

			/**
			 * Queue the merge.
			 *
			 * @param array<int, mixed>    $inputs  The inputs
			 * @param array<string, mixed> $options The options
			 * @param array<string, mixed> $meta    The metadata
			 *
			 * @return array<string, mixed>
			 */
			public function queue(array $inputs, array $options, array $meta): array {
				$this->log[] = ['queue' => [$options, $meta]];
				return ($this->case['job'] ?? []);
			}

			/**
			 * Merge now.
			 *
			 * @param array<int, mixed>    $inputs  The inputs
			 * @param array<string, mixed> $options The options
			 * @param array<string, mixed> $meta    The metadata
			 *
			 * @return array<string, mixed>
			 */
			public function merge(array $inputs, array $options, array $meta): array {
				$this->log[] = ['merge' => [$options, $meta]];
				if (($this->case['merge'] ?? null) === 'throw') {
					throw new RuntimeException('filinq down');
				}

				if (($this->case['merge'] ?? null) === 'throw-status') {
					throw new class('You may not read bijlage.pdf') extends RuntimeException {
						/**
						 * The HTTP status.
						 *
						 * @return int
						 */
						public function getStatus(): int {
							return 403;
						}
					};
				}

				return ($this->case['merge'] ?? []);
			}
		};
	}//end merger()
}//end class
