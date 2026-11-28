<?php

/**
 * Failure and read paths of MeetingFolderService.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingFolderService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Files being unavailable never fails a meeting: every path answers null and
 * logs, and the read helpers hand back only real files.
 *
 * @covers \OCA\Decidiq\Service\MeetingFolderService
 * @uses   \OCA\Decidiq\Service\MeetingFileTree
 */
class MeetingFolderServiceFailureTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A service over a FileService fake whose createFolder answers $folder,
	 * failing for any path that contains $failOn.
	 *
	 * @param Folder|null $folder        What createFolder returns
	 * @param string|null $failOn        A path fragment that makes createFolder throw
	 * @param bool        $objectsBroken Whether resolving ObjectService throws
	 * @param array<int, string>|null $created Receives the created paths
	 *
	 * @return MeetingFolderService
	 */
	private function service(?Folder $folder=null, ?string $failOn=null, bool $objectsBroken=false, ?array &$created=null): MeetingFolderService {
		$created     = [];
		$fileService = new class($folder, $failOn, $created) {
			/**
			 * @param Folder|null       $folder  The folder to answer
			 * @param string|null       $failOn  The failing path fragment
			 * @param array<int,string> $created The call log
			 */
			public function __construct(private ?Folder $folder, private ?string $failOn, private array &$created) {
			}

			/**
			 * @param string $path The folder path
			 *
			 * @return Folder|null
			 */
			public function createFolder(string $path): ?Folder {
				if ($this->failOn !== null && str_contains($path, $this->failOn) === true) {
					throw new RuntimeException('files offline');
				}
				$this->created[] = $path;
				return $this->folder;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($fileService, $objectsBroken) {
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}
				if ($objectsBroken === true) {
					throw new RuntimeException('no object service');
				}
				self::fail('Unexpected service '.$id);
			}
		);

		return new MeetingFolderService(container: $container, logger: $this->logger);
	}//end service()

	/**
	 * A failing Files app is logged and the tree answers null.
	 *
	 * @return void
	 */
	public function testAFailingFolderTreeIsLoggedAndAnswersNull(): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: meeting folder creation failed',
			['meetingId' => 'm-1', 'error' => 'files offline']
		);

		self::assertNull($this->service(failOn: 'Decidesk')->ensureMeetingFolders(meeting: ['id' => 'm-1', 'title' => 'Board']));
	}//end testAFailingFolderTreeIsLoggedAndAnswersNull()

	/**
	 * An unreachable body lookup and an unparsable date drop those segments
	 * instead of failing the tree.
	 *
	 * @return void
	 */
	public function testAnUnreachableBodyAndABadDateAreLeftOut(): void {
		$created = [];
		$path    = $this->service(objectsBroken: true, created: $created)->ensureMeetingFolders(
			meeting: ['id' => 'm-1', 'title' => 'Board', 'governanceBody' => 'b-1', 'scheduledDate' => 'not a date']
		);

		self::assertSame('Decidesk/Board', $path);
		self::assertSame(['Decidesk', 'Decidesk/Board'], array_slice($created, 0, 2));
	}//end testAnUnreachableBodyAndABadDateAreLeftOut()

	/**
	 * A failing write is logged with the requested names and answers null;
	 * an empty meeting writes nothing.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndAnswersNull(): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: meeting file write failed',
			['subfolder' => 'Notulen', 'fileName' => 'minutes.md', 'error' => 'files offline']
		);
		$service = $this->service(failOn: 'Board/Notulen');

		self::assertNull($service->writeMeetingFile(meeting: ['id' => 'm-1', 'title' => 'Board'], subfolder: 'Notulen', fileName: 'minutes.md', content: 'x'));
		self::assertNull($service->writeMeetingFile(meeting: [], subfolder: 'Notulen', fileName: 'minutes.md', content: 'x'));
	}//end testAFailingWriteIsLoggedAndAnswersNull()

	/**
	 * meetingFile() answers the file node under the sanitised names, and null
	 * for a folder node, a missing file, or an empty meeting.
	 *
	 * @return void
	 */
	public function testMeetingFileAnswersOnlyAFile(): void {
		$file   = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			fn (string $name) => match ($name) {
				'minutes-v1.pdf' => $file,
				'nested' => $this->createMock(Folder::class),
				default => throw new NotFoundException($name),
			}
		);
		$created = [];
		$service = $this->service(folder: $folder, created: $created);
		$meeting = ['id' => 'm-1', 'title' => 'Board'];

		self::assertSame($file, $service->meetingFile(meeting: $meeting, subfolder: 'Notulen', fileName: 'minutes:v1.pdf'));
		self::assertSame('Decidesk/Board/Notulen', end($created));
		self::assertNull($service->meetingFile(meeting: $meeting, subfolder: 'Notulen', fileName: 'nested'));
		self::assertNull($service->meetingFile(meeting: $meeting, subfolder: 'Notulen', fileName: 'absent.pdf'));
		self::assertNull($service->meetingFile(meeting: [], subfolder: 'Notulen', fileName: 'minutes.pdf'));
	}//end testMeetingFileAnswersOnlyAFile()

	/**
	 * readMeetingFile() reads the file at a path, refuses a bare name, and logs
	 * a failed read.
	 *
	 * @return void
	 */
	public function testReadMeetingFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('# Minutes');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			fn (string $name) => ($name === 'minutes.md' ? $file : throw new NotFoundException($name))
		);
		$created = [];
		$service = $this->service(folder: $folder, created: $created);
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: meeting file read failed',
			['path' => 'Decidesk/Board/Notulen/gone.md', 'error' => 'gone.md']
		);

		self::assertSame('# Minutes', $service->readMeetingFile(path: 'Decidesk/Board/Notulen/minutes.md'));
		self::assertSame(['Decidesk/Board/Notulen'], $created);
		self::assertNull($service->readMeetingFile(path: 'minutes.md'));
		self::assertNull($service->readMeetingFile(path: 'Decidesk/Board/Notulen/gone.md'));
	}//end testReadMeetingFile()
}//end class
