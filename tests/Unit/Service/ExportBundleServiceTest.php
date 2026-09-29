<?php

/**
 * Tests for exporting motions and decisions with their attachments.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\BackgroundJob\ExportBundleNoticeJob;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Service\ExportBundleService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * @covers \OCA\Decidiq\Service\ExportBundleService
 * @covers \OCA\Decidiq\Exception\ExportBundleException
 * @uses   \OCA\Decidiq\Support\FilinqPdf
 * @uses   \OCA\Decidiq\Support\FleetAppId
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
class ExportBundleServiceTest extends TestCase {

	/**
	 * Files in the requester's Files, by path.
	 *
	 * @var array<string,string>
	 */
	private array $files = [];

	/**
	 * File ids, by path.
	 *
	 * @var array<string,int>
	 */
	private array $ids = [];

	/**
	 * The config every findAll received.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $reads = [];

	/**
	 * What filinq's merge received: [method, inputs, options].
	 *
	 * @var list<array{0:string,1:array,2:array}>
	 */
	private array $merges = [];

	/**
	 * Background jobs added.
	 *
	 * @var list<array{0:string,1:mixed}>
	 */
	private array $jobs = [];

	/**
	 * Next file id.
	 *
	 * @var int
	 */
	private int $nextId = 1000;

	/**
	 * A motion with its attachments.
	 *
	 * @param int $n     The motion number.
	 * @param int $files How many attachments.
	 *
	 * @return array<string,mixed>
	 */
	private static function motion(int $n, int $files=2): array {
		return ['id' => 'motion-' . $n, 'title' => 'Motion ' . $n, 'text' => 'The council asks for ' . $n . '.', 'decisionType' => 'motion', 'proposer' => 'GroenLinks', 'files' => $files];
	}//end motion()

	/**
	 * An attachment double.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function attachment(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(2048);
		$file->method('getContent')->willReturn('%PDF ' . $name);
		return $file;
	}//end attachment()

	/**
	 * A folder double over $this->files at $path.
	 *
	 * @param string $path The folder path.
	 *
	 * @return Folder
	 */
	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$prefix = ($path === '' ? '' : $path . '/');
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$prefix . $name]) === true);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($prefix): Folder {
				$this->files[$prefix . $name] = '<dir>';
				return $this->folder($prefix . $name);
			}
		);
		$folder->method('get')->willReturnCallback(fn (string $name): Folder => $this->folder($prefix . $name));
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, mixed $content=null) use ($prefix): File {
				if (is_resource($content) === true) {
					$content = stream_get_contents($content);
				}

				$this->files[$prefix . $name] = (string)$content;
				$this->ids[$prefix . $name]   = $this->nextId++;
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($this->ids[$prefix . $name]);
				return $file;
			}
		);
		$folder->method('getById')->willReturnCallback(
			function (int $id): array {
				$path = array_search($id, $this->ids, true);
				if ($path === false) {
					return [];
				}

				$node = $this->createMock(File::class);
				$node->method('delete')->willReturnCallback(function () use ($path): void {
					unset($this->files[$path], $this->ids[$path]);
				});
				return [$node];
			}
		);
		return $folder;
	}//end folder()

	/**
	 * The service over the given decisions.
	 *
	 * @param list<array<string,mixed>> $decisions The register's decisions.
	 * @param object|null               $merger    filinq's merge service, null when filinq is not installed.
	 *
	 * @return ExportBundleService
	 */
	private function service(array $decisions, ?object $merger=null): ExportBundleService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($decisions): array {
				$this->reads[] = $config;
				$rows = $decisions;
				if (isset($config['ids']) === true) {
					$rows = array_values(array_filter($rows, fn (array $d): bool => in_array($d['id'], $config['ids'], true)));
				}

				foreach (($config['filters'] ?? []) as $key => $value) {
					if ($key === 'submittedAt') {
						$rows = array_values(array_filter($rows, fn (array $d): bool => ($d['submittedAt'] ?? '') >= substr((string)$value, 1)));
					}
				}

				$rows = array_slice($rows, 0, (int)($config['limit'] ?? count($rows)));
				return array_map(
					function (array $d): ObjectEntity {
						$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
						$entity->method('jsonSerialize')->willReturn($d);
						return $entity;
					},
					$rows
				);
			}
		);

		$byDecision = [];
		foreach ($decisions as $d) {
			$byDecision[$d['id']] = [];
			for ($i = 1; $i <= (int)($d['files'] ?? 0); $i++) {
				$byDecision[$d['id']][] = $this->attachment((int)(substr($d['id'], 7) * 10 + $i), $d['title'] . ' annex ' . $i . '.pdf');
			}
		}

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(fn (string $id): array => ($byDecision[$id] ?? []));

		$pdfService = new class {
			/**
			 * Render HTML to PDF bytes.
			 *
			 * @param string $html    The HTML.
			 * @param array  $options The options.
			 *
			 * @return string
			 */
			public function generatePdfFromHtml(string $html, array $options=[]): string {
				return '%PDF text ' . md5($html);
			}//end generatePdfFromHtml()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($fileService, $pdfService, $merger): object {
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}

				if (str_ends_with($id, 'Service\PdfService') === true && str_contains($id, 'OCA\Filinq') === true) {
					return $pdfService;
				}

				if ($merger !== null && $id === 'OCA\Filinq\Service\DocumentMergeService') {
					return $merger;
				}

				throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);
		$container->method('has')->willReturnCallback(
			fn (string $id): bool => $id === 'OCA\OpenRegister\Service\FileService'
				|| (str_contains($id, 'OCA\Filinq') === true && str_ends_with($id, 'Service\PdfService') === true)
				|| ($merger !== null && $id === 'OCA\Filinq\Service\DocumentMergeService')
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (): Folder => $this->folder(''));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => (string)tempnam(sys_get_temp_dir(), 'dxp'));

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (mixed $job, mixed $argument=null): void {
			$this->jobs[] = [is_string($job) === true ? $job : $job::class, $argument];
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20 10:00:00'));

		$logger = $this->createMock(LoggerInterface::class);

		return new ExportBundleService(
			objectService: $objects,
			container: $container,
			pdf: new FilinqPdf($container, $logger),
			rootFolder: $root,
			userSession: $session,
			tempManager: $temp,
			jobList: $jobList,
			time: $time,
			logger: $logger,
		);
	}//end service()

	/**
	 * filinq's merge service double.
	 *
	 * @param bool            $queue  What shouldQueue answers.
	 * @param \Throwable|null $refuse Thrown by merge/queue when set.
	 *
	 * @return object
	 */
	private function merger(bool $queue=false, ?\Throwable $refuse=null): object {
		$test = $this;
		return new class ($test, $queue, $refuse) {
			/**
			 * Constructor.
			 *
			 * @param ExportBundleServiceTest $test   The test.
			 * @param bool                    $queue  What shouldQueue answers.
			 * @param \Throwable|null         $refuse Thrown when set.
			 */
			public function __construct(private ExportBundleServiceTest $test, private bool $queue, private ?\Throwable $refuse) {
			}//end __construct()

			/**
			 * Whether to queue.
			 *
			 * @param array $inputs    The inputs.
			 * @param int   $threshold The page threshold.
			 *
			 * @return bool
			 */
			public function shouldQueue(array $inputs, int $threshold): bool {
				return $this->queue;
			}//end shouldQueue()

			/**
			 * Merge now.
			 *
			 * @param array $inputs  The inputs.
			 * @param array $options The options.
			 * @param array $host    The host object.
			 *
			 * @return array
			 */
			public function merge(array $inputs, array $options=[], array $host=[]): array {
				$this->test->recordMerge('merge', $inputs, $options);
				if ($this->refuse !== null) {
					throw $this->refuse;
				}

				return ['uuid' => 'job-1', 'status' => 'done', 'resultFileId' => 777];
			}//end merge()

			/**
			 * Queue.
			 *
			 * @param array $inputs  The inputs.
			 * @param array $options The options.
			 * @param array $host    The host object.
			 *
			 * @return array
			 */
			public function queue(array $inputs, array $options=[], array $host=[]): array {
				$this->test->recordMerge('queue', $inputs, $options);
				if ($this->refuse !== null) {
					throw $this->refuse;
				}

				return ['uuid' => 'job-2', 'status' => 'queued'];
			}//end queue()
		};
	}//end merger()

	/**
	 * Record a call to filinq's merge.
	 *
	 * @param string $method  merge or queue.
	 * @param array  $inputs  The inputs.
	 * @param array  $options The options.
	 *
	 * @return void
	 */
	public function recordMerge(string $method, array $inputs, array $options): void {
		$this->merges[] = [$method, $inputs, $options];
	}//end recordMerge()

	/**
	 * The entries of a ZIP stored in the exports folder.
	 *
	 * @param string $name The file name.
	 *
	 * @return list<string>
	 */
	private function zipEntries(string $name): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'dxz');
		file_put_contents($path, $this->files['Decidiq exports/' . $name]);
		$zip = new ZipArchive();
		$zip->open($path);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entries[] = (string)$zip->getNameIndex($i);
		}

		$zip->close();
		unlink($path);
		sort($entries);
		return $entries;
	}//end zipEntries()

	/**
	 * Three selected motions with two attachments each become a ZIP with
	 * three folders, each holding the text and both files.
	 *
	 * @return void
	 */
	public function testThreeSelectedMotionsExportAsAZipOfFolders(): void {
		$service = $this->service([self::motion(1), self::motion(2), self::motion(3), self::motion(4)]);

		$result = $service->export(format: 'zip', list: 'Motions', ids: ['motion-3', 'motion-1', 'motion-2'], filter: [], search: '');

		self::assertSame('done', $result['status']);
		self::assertSame('Decidiq exports/Motions 2026-10-20.zip', $result['path']);
		self::assertSame(
			[
				'001 Motion 3/Motion 3 annex 1.pdf', '001 Motion 3/Motion 3 annex 2.pdf', '001 Motion 3/decision.html',
				'002 Motion 1/Motion 1 annex 1.pdf', '002 Motion 1/Motion 1 annex 2.pdf', '002 Motion 1/decision.html',
				'003 Motion 2/Motion 2 annex 1.pdf', '003 Motion 2/Motion 2 annex 2.pdf', '003 Motion 2/decision.html',
			],
			$this->zipEntries('Motions 2026-10-20.zip')
		);
	}//end testThreeSelectedMotionsExportAsAZipOfFolders()

	/**
	 * All rows matching the filter are exported, not only one page: the
	 * filter reaches the read, and the list's own query keys do not.
	 *
	 * @return void
	 */
	public function testAllRowsMatchingTheFilterAreExported(): void {
		$old = (self::motion(1) + ['submittedAt' => '2025-11-02']);
		$new = [(self::motion(2) + ['submittedAt' => '2026-02-01']), (self::motion(3) + ['submittedAt' => '2026-05-01'])];
		$service = $this->service([$old, ...$new]);

		$service->export(format: 'zip', list: 'Motions', ids: [], filter: ['decisionType' => 'motion', 'submittedAt' => '>2026-01-01', '_order' => '[]', 'action' => 'create'], search: '');

		$filters = $this->reads[0]['filters'];
		self::assertSame('motion', $filters['decisionType']);
		self::assertArrayNotHasKey('_order', $filters);
		self::assertArrayNotHasKey('action', $filters);
		self::assertSame(501, $this->reads[0]['limit']);
		$entries = $this->zipEntries('Motions 2026-10-20.zip');
		self::assertContains('001 Motion 2/decision.html', $entries);
		self::assertContains('002 Motion 3/decision.html', $entries);
		self::assertCount(6, $entries);
	}//end testAllRowsMatchingTheFilterAreExported()

	/**
	 * More than 500 matching decisions: asked to narrow the filter, nothing built.
	 *
	 * @return void
	 */
	public function testMoreThanFiveHundredDecisionsIsRefused(): void {
		$many = [];
		for ($i = 1; $i <= 501; $i++) {
			$many[] = self::motion($i, 0);
		}

		$service = $this->service($many);

		try {
			$service->export(format: 'zip', list: 'Motions', ids: [], filter: [], search: '');
			self::fail('An export of 501 decisions was built.');
		} catch (ExportBundleException $e) {
			self::assertSame(422, $e->getStatus());
			self::assertStringContainsString('Narrow the filter', $e->getMessage());
		}

		self::assertSame([], $this->files);
	}//end testMoreThanFiveHundredDecisionsIsRefused()

	/**
	 * One PDF: filinq receives each motion's text page followed by its
	 * attachments, in list order, with bookmarks on, into the exports folder.
	 *
	 * @return void
	 */
	public function testOnePdfHandsFilinqTheTextPagesAndAttachmentsInOrder(): void {
		$service = $this->service([self::motion(1, 1), self::motion(2, 2)], $this->merger());

		$result = $service->export(format: 'pdf', list: 'Motions', ids: ['motion-1', 'motion-2'], filter: [], search: '');

		self::assertSame('done', $result['status']);
		self::assertSame(777, $result['fileId']);
		[$method, $inputs, $options] = $this->merges[0];
		self::assertSame('merge', $method);
		self::assertSame(['Motion 1', 'Motion 1 annex 1.pdf', 'Motion 2', 'Motion 2 annex 1.pdf', 'Motion 2 annex 2.pdf'], array_column($inputs, 'label'));
		self::assertSame(['bookmarks' => true, 'targetFolder' => 'Decidiq exports', 'name' => 'Motions 2026-10-20.pdf'], $options);
		self::assertSame([], array_filter(array_keys($this->files), fn (string $p): bool => str_ends_with($p, '.pdf')), 'The rendered text pages were left behind.');
	}//end testOnePdfHandsFilinqTheTextPagesAndAttachmentsInOrder()

	/**
	 * filinq refuses an attachment the clerk may not read: the answer names
	 * the file, and no export and no text page is left.
	 *
	 * @return void
	 */
	public function testAnUnreadableAttachmentRefusesTheWholePdf(): void {
		$refusal = new class ('You may not read Motion 2 annex 1.pdf.') extends RuntimeException {
			/**
			 * The status.
			 *
			 * @return int
			 */
			public function getStatus(): int {
				return 403;
			}//end getStatus()
		};
		$service = $this->service([self::motion(1, 1), self::motion(2, 1)], $this->merger(false, $refusal));

		try {
			$service->export(format: 'pdf', list: 'Motions', ids: ['motion-1', 'motion-2'], filter: [], search: '');
			self::fail('The PDF was made although an attachment was refused.');
		} catch (ExportBundleException $e) {
			self::assertSame(403, $e->getStatus());
			self::assertStringContainsString('Motion 2 annex 1.pdf', $e->getMessage());
		}

		self::assertSame([], array_filter(array_keys($this->files), fn (string $p): bool => str_ends_with($p, '.pdf')));
	}//end testAnUnreadableAttachmentRefusesTheWholePdf()

	/**
	 * Without filinq one PDF is unavailable (503); a ZIP still works.
	 *
	 * @return void
	 */
	public function testWithoutFilinqThePdfIsUnavailableButTheZipWorks(): void {
		$service = $this->service([self::motion(1)]);

		try {
			$service->export(format: 'pdf', list: 'Motions', ids: ['motion-1'], filter: [], search: '');
			self::fail('A PDF was attempted without filinq.');
		} catch (ExportBundleException $e) {
			self::assertSame(503, $e->getStatus());
		}

		$result = $service->export(format: 'zip', list: 'Motions', ids: ['motion-1'], filter: [], search: '');
		self::assertSame('done', $result['status']);
	}//end testWithoutFilinqThePdfIsUnavailableButTheZipWorks()

	/**
	 * A large PDF is queued at once, and a notice job follows the merge.
	 *
	 * @return void
	 */
	public function testALargePdfIsQueuedAndANoticeFollows(): void {
		$service = $this->service([self::motion(1), self::motion(2)], $this->merger(true));

		$result = $service->export(format: 'pdf', list: 'Motions', ids: ['motion-1', 'motion-2'], filter: [], search: '');

		self::assertSame('queued', $result['status']);
		self::assertSame('queue', $this->merges[0][0]);
		self::assertSame(ExportBundleNoticeJob::class, $this->jobs[0][0]);
		self::assertSame('job-2', $this->jobs[0][1]['mergeJob']);
		self::assertSame('griffier', $this->jobs[0][1]['uid']);
		self::assertSame('Motions 2026-10-20.pdf', $this->jobs[0][1]['name']);
		self::assertCount(2, $this->jobs[0][1]['pages'], 'The text pages must stay until the queued merge is done.');
	}//end testALargePdfIsQueuedAndANoticeFollows()
}//end class
