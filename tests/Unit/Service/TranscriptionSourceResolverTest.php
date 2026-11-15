<?php

/**
 * Audio in the meeting folder and Talk recordings on the meeting are offered as transcription sources.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\TranscriptionSourceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\TranscriptionSourceResolver
 */
class TranscriptionSourceResolverTest extends TestCase {

	/**
	 * What the resolver logged: "message context".
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A folder node listing the given names, or throwing on listing.
	 *
	 * @param list<string>|null $names The names, or null to fail the listing.
	 *
	 * @return object The folder.
	 */
	private function folder(?array $names): object {
		return new class($names) {
			/**
			 * The folder.
			 *
			 * @param list<string>|null $names The names.
			 */
			public function __construct(private readonly ?array $names) {
			}//end __construct()

			/**
			 * The nodes in the folder.
			 *
			 * @return list<object> The nodes.
			 */
			public function getDirectoryListing(): array {
				if ($this->names === null) {
					throw new RuntimeException('storage offline');
				}

				return array_map(
					static fn (string $name): object => new class($name) {
						/**
						 * A node.
						 *
						 * @param string $name Its name.
						 */
						public function __construct(private readonly string $name) {
						}//end __construct()

						/**
						 * The node's name.
						 *
						 * @return string The name.
						 */
						public function getName(): string {
							return $this->name;
						}//end getName()
					},
					$this->names
				);
			}//end getDirectoryListing()
		};
	}//end folder()

	/**
	 * The resolver over a meeting folder path and a file service.
	 *
	 * @param string|null $path        The meeting folder path, or null when none.
	 * @param object|null $fileService The file service, or null when the container has none.
	 *
	 * @return TranscriptionSourceResolver The resolver.
	 */
	private function resolver(?string $path, ?object $fileService): TranscriptionSourceResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($fileService): object {
				if ($id !== 'OCA\OpenRegister\Service\FileService' || $fileService === null) {
					throw new RuntimeException('no ' . $id);
				}

				return $fileService;
			}
		);
		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('ensureMeetingFolders')->willReturn($path);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context=[]): void {
			$this->logged[] = $message . ' ' . json_encode($context);
		});

		return new TranscriptionSourceResolver($container, $logger, $folders);
	}//end resolver()

	/**
	 * A file service that opens one folder.
	 *
	 * @param object $folder The folder it opens.
	 *
	 * @return object The file service.
	 */
	private function fileService(object $folder): object {
		return new class($folder) {
			/**
			 * The paths opened.
			 *
			 * @var list<string>
			 */
			public array $opened = [];

			/**
			 * The file service.
			 *
			 * @param object $folder The folder.
			 */
			public function __construct(private readonly object $folder) {
			}//end __construct()

			/**
			 * Open (or create) a folder.
			 *
			 * @param string $path The path.
			 *
			 * @return object The folder.
			 */
			public function createFolder(string $path): object {
				$this->opened[] = $path;
				return $this->folder;
			}//end createFolder()
		};
	}//end fileService()

	/**
	 * Audio files in the folder come first, then the meeting's Talk recordings.
	 *
	 * @return void
	 */
	public function testFolderAudioThenTalkRecordingsAreListed(): void {
		$files = $this->fileService($this->folder(['agenda.pdf', 'Session.MP3', 'notes', 'call.webm']));

		$sources = $this->resolver('Decidiq/m-1', $files)->listSources(
			[
				'id' => 'm-1',
				'talkRecordings' => [
					'Talk/Recordings/2026-09-01.ogg',
					['path' => 'Talk/r2.opus', 'name' => 'Second part'],
					['path' => 'Talk/r3.wav'],
					['name' => 'no path'],
					'',
				],
			]
		);

		self::assertSame(['Decidiq/m-1'], $files->opened);
		self::assertSame(
			[
				['type' => 'uploaded-file', 'path' => 'Decidiq/m-1/Session.MP3', 'name' => 'Session.MP3'],
				['type' => 'uploaded-file', 'path' => 'Decidiq/m-1/call.webm', 'name' => 'call.webm'],
				['type' => 'talk-recording', 'path' => 'Talk/Recordings/2026-09-01.ogg', 'name' => '2026-09-01.ogg'],
				['type' => 'talk-recording', 'path' => 'Talk/r2.opus', 'name' => 'Second part'],
				['type' => 'talk-recording', 'path' => 'Talk/r3.wav', 'name' => 'r3.wav'],
			],
			$sources
		);
		self::assertSame([], $this->logged);
	}//end testFolderAudioThenTalkRecordingsAreListed()

	/**
	 * Without a meeting folder only Talk recordings are offered.
	 *
	 * @return void
	 */
	public function testWithoutAMeetingFolderOnlyRecordingsAreOffered(): void {
		$sources = $this->resolver(null, null)->listSources(['talkRecordings' => ['Talk/a.m4a']]);

		self::assertSame([['type' => 'talk-recording', 'path' => 'Talk/a.m4a', 'name' => 'a.m4a']], $sources);
		self::assertSame([], $this->resolver(null, null)->listSources(['talkRecordings' => 'not a list']));
	}//end testWithoutAMeetingFolderOnlyRecordingsAreOffered()

	/**
	 * A folder that cannot be opened is logged and offers nothing.
	 *
	 * @return void
	 */
	public function testAFolderThatCannotBeOpenedIsLogged(): void {
		$sources = $this->resolver('Decidiq/m-2', null)->listSources([]);

		self::assertSame([], $sources);
		self::assertSame(
			['Decidiq TranscriptionSourceResolver: cannot open meeting folder {"path":"Decidiq\/m-2","error":"no OCA\\\\OpenRegister\\\\Service\\\\FileService"}'],
			$this->logged
		);
	}//end testAFolderThatCannotBeOpenedIsLogged()

	/**
	 * A failing listing is logged; the recordings are still offered.
	 *
	 * @return void
	 */
	public function testAFailingListingIsLoggedAndRecordingsStillCount(): void {
		$sources = $this->resolver('Decidiq/m-3', $this->fileService($this->folder(null)))
			->listSources(['talkRecordings' => ['Talk/x.flac']]);

		self::assertSame([['type' => 'talk-recording', 'path' => 'Talk/x.flac', 'name' => 'x.flac']], $sources);
		self::assertSame(
			['Decidiq TranscriptionSourceResolver: directory listing failed {"path":"Decidiq\/m-3","error":"storage offline"}'],
			$this->logged
		);
	}//end testAFailingListingIsLoggedAndRecordingsStillCount()

	/**
	 * Audio is recognised by extension, case-insensitively.
	 *
	 * @return void
	 */
	public function testAudioIsRecognisedByExtension(): void {
		$resolver = $this->resolver(null, null);

		self::assertTrue($resolver->isAudioFile('a.FLAC'));
		self::assertTrue($resolver->isAudioFile('dir.v2/a.mka'));
		self::assertFalse($resolver->isAudioFile('mp3'));
		self::assertFalse($resolver->isAudioFile('a.mp3.txt'));
		self::assertFalse($resolver->isAudioFile(''));
	}//end testAudioIsRecognisedByExtension()
}//end class
