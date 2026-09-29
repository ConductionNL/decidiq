<?php

/**
 * Unit tests for the full data export (platform-full-data-export).
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

use OCA\Decidiq\BackgroundJob\FullExportJob;
use OCA\Decidiq\Controller\FullExportController;
use OCA\Decidiq\Service\FullExportService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * The administrator exports every record per schema and the meeting files in
 * one ZIP with a manifest, and is told where to download it.
 *
 * @covers \OCA\Decidiq\Service\FullExportService
 * @covers \OCA\Decidiq\BackgroundJob\FullExportJob
 * @covers \OCA\Decidiq\Controller\FullExportController
 * @uses   \OCA\Decidiq\Service\SettingsService
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
class FullExportTest extends TestCase {

	/**
	 * Files in the app data exports folder, name => content.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * Temporary files to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $temps = [];

	/**
	 * The findAll() configs the object service received, with the rbac flag.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * Remove the temporary archives.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temps as $temp) {
			if (is_file($temp) === true) {
				unlink($temp);
			}
		}
	}//end tearDown()

	/**
	 * An entity double serializing to $data.
	 *
	 * @param array<string,mixed> $data The object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A stored export file double.
	 *
	 * @param string $name The file name
	 *
	 * @return ISimpleFile&MockObject
	 */
	private function simpleFile(string $name): ISimpleFile&MockObject {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen(($this->stored[$name] ?? '')));
		$file->method('getMTime')->willReturn(1790000000);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->stored[$name]);
		$file->method('delete')->willReturnCallback(
			function () use ($name): void {
				unset($this->stored[$name]);
			}
		);
		return $file;
	}//end simpleFile()

	/**
	 * The export service over an instance with 501 meetings (so paging is
	 * needed), one decision, a schema whose read fails, and a paper on the
	 * first meeting.
	 *
	 * @return FullExportService
	 */
	private function service(): FullExportService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->queries[] = $config + ['_rbac' => $_rbac];
				$schema = ($config['filters']['schema'] ?? null);
				$offset = (int)($config['offset'] ?? 0);
				$limit  = (int)($config['limit'] ?? 0);
				if (($config['filters']['register'] ?? null) !== 'decidiq') {
					return [];
				}

				if ($schema === 'meeting') {
					$all = [];
					for ($i = 1; $i <= 501; $i++) {
						$all[] = ['id' => 'meeting-' . $i, 'title' => 'Council ' . $i];
					}

					return array_map(fn (array $m) => $this->entity($m), array_slice($all, $offset, $limit));
				}

				if ($schema === 'decision' && $offset === 0) {
					return [$this->entity(['id' => 'decision-1', 'title' => 'Housing plan adopted'])];
				}

				if ($schema === 'vote') {
					throw new RuntimeException('schema vote is not in the register');
				}

				return [];
			}
		);

		$paper = $this->createMock(File::class);
		$paper->method('getName')->willReturn('agenda.pdf');
		$paper->method('getContent')->willReturn('%PDF agenda');
		$fileService = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFiles'])
			->getMock();
		$fileService->method('getFiles')->willReturnCallback(
			static fn (ObjectEntity|string $object): array => ($object === 'meeting-1') ? [$paper] : []
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fileService);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map(fn (string $n) => $this->simpleFile($n), array_keys($this->stored))
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content): ISimpleFile {
				$this->stored[$name] = (string)$content;
				if (is_resource($content) === true) {
					$this->stored[$name] = (string)stream_get_contents($content);
				}

				return $this->simpleFile($name);
			}
		);
		$folder->method('getFile')->willReturnCallback(
			function (string $name): ISimpleFile {
				if (isset($this->stored[$name]) === false) {
					throw new NotFoundException();
				}

				return $this->simpleFile($name);
			}
		);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'decidiq-export-test');
				$this->temps[] = $path;
				return $path;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-14 20:15:00'));

		return new FullExportService($objects, $container, $appData, $temp, $time, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Open a stored export as a ZIP.
	 *
	 * @param string $name The export name
	 *
	 * @return ZipArchive
	 */
	private function zip(string $name): ZipArchive {
		$path = (string)tempnam(sys_get_temp_dir(), 'decidiq-export-read');
		$this->temps[] = $path;
		file_put_contents($path, $this->stored[$name]);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path));
		return $zip;
	}//end zip()

	/**
	 * The archive holds every record per schema, across pages, and the files
	 * of the meetings, read in system context.
	 *
	 * @return void
	 */
	public function testTheArchiveHoldsEveryRecordAndTheMeetingFiles(): void {
		$name = $this->service()->build();

		self::assertSame('decidiq-export-20261014-201500.zip', $name);
		$zip      = $this->zip($name);
		$meetings = json_decode((string)$zip->getFromName('meeting.json'), true);
		self::assertCount(501, $meetings);
		self::assertSame('Council 501', $meetings[500]['title']);
		self::assertSame('Housing plan adopted', json_decode((string)$zip->getFromName('decision.json'), true)[0]['title']);
		self::assertSame('%PDF agenda', $zip->getFromName('files/meeting/meeting-1/agenda.pdf'));
		self::assertNotSame([], $this->queries);
		foreach ($this->queries as $query) {
			self::assertFalse($query['_rbac']);
		}
	}//end testTheArchiveHoldsEveryRecordAndTheMeetingFiles()

	/**
	 * The manifest names each schema with its count and relations, and lists
	 * a schema that could not be read instead of leaving it out in silence.
	 *
	 * @return void
	 */
	public function testTheManifestDescribesSchemasRelationsAndGaps(): void {
		$manifest = json_decode((string)$this->zip($this->service()->build())->getFromName('manifest.json'), true);

		$bySlug = array_column($manifest['schemas'], null, 'slug');
		self::assertSame(501, $bySlug['meeting']['count']);
		self::assertSame('meeting.json', $bySlug['meeting']['file']);
		self::assertSame('governance-body', $bySlug['meeting']['relations']['governanceBody']);
		self::assertSame(1, $manifest['files']);
		self::assertSame([['schema' => 'vote', 'reason' => 'schema vote is not in the register']], $manifest['skipped']);
	}//end testTheManifestDescribesSchemasRelationsAndGaps()

	/**
	 * Only the newest export is kept, and only an export name can be opened.
	 *
	 * @return void
	 */
	public function testOnlyTheNewestExportIsKeptAndOnlyExportsOpen(): void {
		$this->stored['decidiq-export-20260101-090000.zip'] = 'old';
		$service = $this->service();
		$name    = $service->build();

		self::assertSame([$name], array_keys($this->stored));
		self::assertSame($name, $service->latest()['name']);
		$this->expectException(NotFoundException::class);
		$service->open('../../config/config.php');
	}//end testOnlyTheNewestExportIsKeptAndOnlyExportsOpen()

	/**
	 * The job builds the export and tells the administrator where it is; a
	 * failure is told too.
	 *
	 * @return void
	 */
	public function testTheJobNotifiesTheAdministrator(): void {
		$sent    = [];
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use (&$sent): INotification {
				$n = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime'] as $m) {
					$n->method($m)->willReturnSelf();
				}

				$n->method('setObject')->willReturnCallback(
					function (string $type, string $id) use ($n, &$sent) {
						$sent[] = ['object' => $id];
						return $n;
					}
				);
				$n->method('setSubject')->willReturnCallback(
					function (string $subject) use ($n, &$sent) {
						$sent[(count($sent) - 1)]['subject'] = $subject;
						return $n;
					}
				);
				return $n;
			}
		);
		$manager->expects(self::exactly(2))->method('notify');

		$time = $this->createMock(ITimeFactory::class);
		$job  = new FullExportJob($time, $this->service(), $manager, $this->createMock(LoggerInterface::class));
		$job->setArgument(['uid' => 'admin']);
		$job->start($this->createMock(IJobList::class));

		$broken = $this->createMock(FullExportService::class);
		$broken->method('build')->willThrowException(new RuntimeException('disk full'));
		$failing = new FullExportJob($time, $broken, $manager, $this->createMock(LoggerInterface::class));
		$failing->setArgument(['uid' => 'admin']);
		$failing->start($this->createMock(IJobList::class));

		self::assertSame(['object' => 'decidiq-export-20261014-201500.zip', 'subject' => 'full_export_ready'], $sent[0]);
		self::assertSame('full_export_failed', $sent[1]['subject']);
	}//end testTheJobNotifiesTheAdministrator()

	/**
	 * Pressing Export all data queues the job for the administrator; a name
	 * that is not an export answers 404.
	 *
	 * @return void
	 */
	public function testTheControllerQueuesAndServesOnlyExports(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('add')->with(FullExportJob::class, ['uid' => 'admin']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = new FullExportController($this->createMock(IRequest::class), $this->service(), $jobs, $session);

		self::assertSame(Http::STATUS_ACCEPTED, $controller->start()->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->download('../../data/owncloud.db')->getStatus());
	}//end testTheControllerQueuesAndServesOnlyExports()
}//end class
