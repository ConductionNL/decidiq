<?php

/**
 * Subtitles for a public broadcast: derived from the aligned transcript over the
 * public windows only (REQ-LSTR-006), and released by the clerk as a read-only
 * link share once the broadcast of a public meeting has ended (REQ-LSTR-007).
 *
 * Runs the real BroadcastCaptionService, the real MeetingFolderService, the real
 * StreamingClient and the real PublicationEligibilityService over an in-memory
 * register; every broadcast save is validated against the 120 fragment.
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use DomainException;
use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\Decidiq\Service\BroadcastCaptionService;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\StreamingClient;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\Decidiq\Tests\Unit\Support\StreamingCallServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @uses \OCA\Decidiq\Exception\BroadcastRefusedException
 * @uses \OCA\Decidiq\Service\MeetingFolderService
 * @uses \OCA\Decidiq\Service\PublicationEligibilityService
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Service\StreamingClient
 */
final class BroadcastCaptionServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var CaseSystemWorld
	 */
	private CaseSystemWorld $world;

	/**
	 * Files written, by path.
	 *
	 * @var array<string, string>
	 */
	private array $files = [];

	/**
	 * Link shares created.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $shares = [];

	/**
	 * The meeting.
	 *
	 * @var string
	 */
	private string $meeting;

	/**
	 * The broadcast.
	 *
	 * @var string
	 */
	private string $broadcast;

	/**
	 * The transcript.
	 *
	 * @var string
	 */
	private string $transcript;

	/**
	 * A public meeting whose broadcast paused for a closed session from 5400 to 6300 seconds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world   = new CaseSystemWorld();
		$this->meeting = $this->world->put(
			schema: 'meeting',
			data: ['title' => 'Raadsvergadering 19 maart', 'scheduledDate' => '2026-03-19T19:30:00+01:00', 'openedAt' => '2026-03-19T19:30:00+01:00', 'isPublic' => true]
		);
		$this->broadcast = $this->world->put(
			schema: 'meeting-broadcast',
			data: [
				'meeting'       => $this->meeting,
				'title'         => 'Raadsvergadering 19 maart',
				'lifecycle'     => 'ended',
				'publicWindows' => [
					['start' => 0, 'end' => 5400, 'recordingStart' => 0],
					['start' => 6300, 'end' => 10800, 'recordingStart' => 5400],
				],
			]
		);
		$this->transcript = $this->world->put(
			schema: 'transcript',
			data: [
				'meeting'   => $this->meeting,
				'status'    => 'done',
				'language'  => 'nl',
				'alignedAt' => '2026-03-20T09:00:00+01:00',
				'segments'  => [
					['startTime' => 100.0, 'endTime' => 104.5, 'speakerLabel' => 'Speaker 1', 'text' => 'De vergadering is geopend.'],
					['startTime' => 5800.0, 'endTime' => 5806.0, 'speakerLabel' => 'Speaker 2', 'text' => 'Dit is besloten.'],
					['startTime' => 6400.0, 'endTime' => 6406.0, 'speakerLabel' => 'Speaker 1', 'text' => 'We gaan weer verder.'],
				],
			]
		);
		$source = $this->world->put(schema: 'source', data: ['slug' => 'streaming'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'streaming', 'source' => $source], register: 'integriq');
		$this->world->answers['attach-captions'] = ['accepted' => true];

	}//end setUp()

	/**
	 * No broadcast may be saved that the 120 fragment refuses.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::assertSame([], $this->world->invalid, 'A broadcast was saved that its own schema refuses.');

	}//end tearDown()

	/**
	 * A closed session never reaches the subtitles, and cues move onto the recording's clock.
	 *
	 * @return void
	 */
	public function testCuesCoverOnlyThePublicWindows(): void {
		$made = $this->service()->derive(broadcastId: $this->broadcast, language: 'nl');

		self::assertSame('nl', $made['language']);
		self::assertSame(2, $made['cues']);
		self::assertStringEndsWith('/Broadcast/captions-nl.vtt', $made['filePath']);
		$vtt = $this->files[$made['filePath']];
		self::assertStringStartsWith("WEBVTT\n", $vtt);
		self::assertStringContainsString("00:01:40.000 --> 00:01:44.500\nDe vergadering is geopend.", $vtt);
		self::assertStringContainsString("01:31:40.000 --> 01:31:46.000\nWe gaan weer verder.", $vtt);
		self::assertStringNotContainsString('besloten', $vtt);
		self::assertStringNotContainsString('Speaker', $vtt);

	}//end testCuesCoverOnlyThePublicWindows()

	/**
	 * A cue that runs past the end of its window is cut at the window's end.
	 *
	 * @return void
	 */
	public function testACueStopsAtTheEndOfItsWindow(): void {
		$vtt = $this->service()->webVtt(
			segments: [['startTime' => 5398.0, 'endTime' => 5410.0, 'text' => 'Laatste woord.']],
			windows: [['start' => 0, 'end' => 5400, 'recordingStart' => 0]]
		);

		self::assertStringContainsString("01:29:58.000 --> 01:30:00.000\nLaatste woord.", $vtt);

	}//end testACueStopsAtTheEndOfItsWindow()

	/**
	 * A transcript that is not aligned with the agenda is refused.
	 *
	 * @return void
	 */
	public function testAnUnalignedTranscriptIsRefused(): void {
		unset($this->world->objects[$this->transcript]['data']['alignedAt']);

		try {
			$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
			self::fail('An unaligned transcript was turned into subtitles.');
		} catch (BroadcastRefusedException $e) {
			self::assertSame('Align the transcript with the agenda first', $e->getMessage());
			self::assertSame(422, $e->getStatus());
		}

		self::assertSame([], $this->files);

	}//end testAnUnalignedTranscriptIsRefused()

	/**
	 * A meeting without a finished transcript has nothing to derive from.
	 *
	 * @return void
	 */
	public function testNoFinishedTranscriptIsRefused(): void {
		$this->world->objects[$this->transcript]['data']['status'] = 'processing';

		$this->expectException(BroadcastRefusedException::class);
		$this->expectExceptionMessage('There is no finished transcript of this meeting');
		$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');

	}//end testNoFinishedTranscriptIsRefused()

	/**
	 * The caption file's name is not on the publication deny-list, the transcript stays on it.
	 *
	 * @return void
	 */
	public function testTheCaptionFileIsNotDeniedButTheTranscriptStaysDenied(): void {
		$made        = $this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$eligibility = new PublicationEligibilityService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objects()
		);

		self::assertFalse($eligibility->isFileDenied(fileName: $made['filePath']));

		$this->service()->release(broadcastId: $this->broadcast, language: 'nl');
		$this->expectException(DomainException::class);
		$eligibility->assertPublishable(schemaSlug: 'transcript');

	}//end testTheCaptionFileIsNotDeniedButTheTranscriptStaysDenied()

	/**
	 * The clerk releases the reviewed subtitles of an ended public broadcast.
	 *
	 * @return void
	 */
	public function testReleaseSharesTheTrackAndRecordsWhoReleasedIt(): void {
		$made     = $this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$released = $this->service()->release(broadcastId: $this->broadcast, language: 'nl');

		self::assertCount(1, $this->shares);
		self::assertSame(IShare::TYPE_LINK, $this->shares[0]['type']);
		self::assertSame(Constants::PERMISSION_READ, $this->shares[0]['permissions']);
		self::assertSame($made['filePath'], $this->shares[0]['path']);

		$tracks = $this->world->objects[$this->broadcast]['data']['captionTracks'];
		self::assertSame($released['captionTracks'], $tracks);
		self::assertCount(1, $tracks);
		self::assertSame('nl', $tracks[0]['language']);
		self::assertSame($made['filePath'], $tracks[0]['filePath']);
		self::assertSame('https://cloud.example.org/s/tok-1', $tracks[0]['shareUrl']);
		self::assertSame('Griffier De Vries', $tracks[0]['reviewedBy']);
		self::assertSame('2026-03-20T10:00:00+00:00', $tracks[0]['releasedAt']);
		self::assertSame([['language' => 'nl', 'url' => 'https://cloud.example.org/s/tok-1']], $this->world->callsOf(operation: 'attach-captions'));

	}//end testReleaseSharesTheTrackAndRecordsWhoReleasedIt()

	/**
	 * Releasing the same language again replaces its track instead of adding a second one.
	 *
	 * @return void
	 */
	public function testReleasingAgainReplacesTheTrack(): void {
		$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$this->service()->release(broadcastId: $this->broadcast, language: 'nl');
		$this->service()->release(broadcastId: $this->broadcast, language: 'nl');

		self::assertCount(1, $this->world->objects[$this->broadcast]['data']['captionTracks']);

	}//end testReleasingAgainReplacesTheTrack()

	/**
	 * A streaming service that cannot attach the file does not stop the release.
	 *
	 * @return void
	 */
	public function testAServiceThatCannotAttachDoesNotStopTheRelease(): void {
		$this->world->answers['attach-captions'] = static function (): array {
			throw new RuntimeException('attach refused');
		};
		$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$released = $this->service()->release(broadcastId: $this->broadcast, language: 'nl');

		self::assertCount(1, $released['captionTracks']);

	}//end testAServiceThatCannotAttachDoesNotStopTheRelease()

	/**
	 * Subtitles of a meeting that is not public are never released.
	 *
	 * @return void
	 */
	public function testReleaseOfAMeetingThatIsNotPublicIsRefused(): void {
		$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$this->world->objects[$this->meeting]['data']['isPublic'] = false;

		try {
			$this->service()->release(broadcastId: $this->broadcast, language: 'nl');
			self::fail('Subtitles of a closed meeting were released.');
		} catch (BroadcastRefusedException $e) {
			self::assertSame(422, $e->getStatus());
		}

		self::assertSame([], $this->shares);
		self::assertArrayNotHasKey('captionTracks', $this->world->objects[$this->broadcast]['data']);

	}//end testReleaseOfAMeetingThatIsNotPublicIsRefused()

	/**
	 * Subtitles are released only after the broadcast has ended.
	 *
	 * @return void
	 */
	public function testReleaseBeforeTheBroadcastEndedIsRefused(): void {
		$this->service()->derive(broadcastId: $this->broadcast, language: 'nl');
		$this->world->objects[$this->broadcast]['data']['lifecycle'] = 'live';

		try {
			$this->service()->release(broadcastId: $this->broadcast, language: 'nl');
			self::fail('Subtitles were released while the broadcast was live.');
		} catch (BroadcastRefusedException $e) {
			self::assertSame(409, $e->getStatus());
		}

		self::assertSame([], $this->shares);

	}//end testReleaseBeforeTheBroadcastEndedIsRefused()

	/**
	 * Nothing is released before the subtitles were made.
	 *
	 * @return void
	 */
	public function testReleaseWithoutACaptionFileIsRefused(): void {
		$this->expectException(BroadcastRefusedException::class);
		$this->expectExceptionMessage('Make the subtitles first');
		$this->service()->release(broadcastId: $this->broadcast, language: 'nl');

	}//end testReleaseWithoutACaptionFileIsRefused()

	/**
	 * An unknown broadcast is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownBroadcastIsNotFound(): void {
		try {
			$this->service()->derive(broadcastId: 'nope', language: 'nl');
			self::fail('An unknown broadcast was accepted.');
		} catch (BroadcastRefusedException $e) {
			self::assertSame(404, $e->getStatus());
		}

	}//end testAnUnknownBroadcastIsNotFound()

	/**
	 * A language that is not a plain language code is refused, so it cannot reach a file name.
	 *
	 * @return void
	 */
	public function testALanguageThatIsNotACodeIsRefused(): void {
		$this->expectException(BroadcastRefusedException::class);
		$this->service()->derive(broadcastId: $this->broadcast, language: '../nl');

	}//end testALanguageThatIsNotACodeIsRefused()

	/**
	 * The in-memory object service.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				return $this->entity(id: $this->world->save(data: $object, register: (string)$register, schema: (string)$schema, id: $uuid));
			}
		);
		return $objects;

	}//end objects()

	/**
	 * An entity over a stored object.
	 *
	 * @param string $id The uuid
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturnCallback(fn (): array => ['id' => $id] + $this->world->objects[$id]['data']);
		$entity->method('getUuid')->willReturn($id);
		return $entity;

	}//end entity()

	/**
	 * A folder over the in-memory files.
	 *
	 * @param string $path The folder path
	 *
	 * @return Folder
	 */
	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($path): File {
				$full = $path . '/' . $name;
				if (isset($this->files[$full]) === false) {
					throw new NotFoundException($full);
				}

				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn($full);
				$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$full]);
				$file->method('putContent')->willReturnCallback(
					function (string $content) use ($full): void {
						$this->files[$full] = $content;
					}
				);
				return $file;
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, string $content) use ($path): File {
				$this->files[$path . '/' . $name] = $content;
				return $this->createMock(File::class);
			}
		);
		return $folder;

	}//end folder()

	/**
	 * The container: OpenRegister's file service and integriq's call service.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$fileService = new class ($this) {
			/**
			 * @param BroadcastCaptionServiceTest $test The test, which owns the files
			 */
			public function __construct(private readonly BroadcastCaptionServiceTest $test) {
			}

			/**
			 * @param string $folderPath The folder
			 *
			 * @return Folder
			 */
			public function createFolder(string $folderPath): Folder {
				return $this->test->folderFor(path: $folderPath);
			}
		};
		$services  = [
			'OCA\OpenRegister\Service\FileService' => $fileService,
			'OCA\Integriq\Service\CallService'     => new StreamingCallServiceFake(world: $this->world),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);
		return $container;

	}//end container()

	/**
	 * The folder at a path (called by the file service fake).
	 *
	 * @param string $path The folder path
	 *
	 * @return Folder
	 */
	public function folderFor(string $path): Folder {
		return $this->folder(path: $path);

	}//end folderFor()

	/**
	 * The share manager, recording each link share.
	 *
	 * @return IManager
	 */
	private function shareManager(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturnCallback(
			function (): IShare {
				$state = ['type' => null, 'permissions' => null, 'path' => null, 'by' => null];
				$share = $this->createMock(IShare::class);
				$share->method('setNode')->willReturnCallback(
					function (File $node) use (&$state, $share): IShare {
						$state['path'] = $node->getPath();
						return $share;
					}
				);
				$share->method('setShareType')->willReturnCallback(
					function (int $type) use (&$state, $share): IShare {
						$state['type'] = $type;
						return $share;
					}
				);
				$share->method('setPermissions')->willReturnCallback(
					function (int $permissions) use (&$state, $share): IShare {
						$state['permissions'] = $permissions;
						return $share;
					}
				);
				$share->method('setSharedBy')->willReturnCallback(
					function (string $uid) use (&$state, $share): IShare {
						$state['by'] = $uid;
						return $share;
					}
				);
				$share->method('getToken')->willReturnCallback(fn (): string => 'tok-' . count($this->shares));
				$share->method('getShareType')->willReturnCallback(fn () => $state['type']);
				$share->method('getPermissions')->willReturnCallback(fn () => $state['permissions']);
				$share->method('getNode')->willReturnCallback(fn () => $state['path']);
				$share->method('getSharedBy')->willReturnCallback(fn () => $state['by']);
				return $share;
			}
		);
		$manager->method('createShare')->willReturnCallback(
			function (IShare $share): IShare {
				$this->shares[] = ['type' => $share->getShareType(), 'permissions' => $share->getPermissions(), 'path' => $share->getNode(), 'by' => $share->getSharedBy()];
				return $share;
			}
		);
		return $manager;

	}//end shareManager()

	/**
	 * The service under test.
	 *
	 * @return BroadcastCaptionService
	 */
	private function service(): BroadcastCaptionService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime('2026-03-20T10:00:00+00:00'));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$user->method('getDisplayName')->willReturn('Griffier De Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters=[]): string => 'https://cloud.example.org/s/' . (string)($parameters['token'] ?? '')
		);

		$objects   = $this->objects();
		$container = $this->container();
		return new BroadcastCaptionService(
			objectService: $objects,
			folders: new MeetingFolderService(container: $container, logger: $this->createMock(LoggerInterface::class)),
			shares: $this->shareManager(),
			urls: $urls,
			userSession: $session,
			time: $time,
			streaming: new StreamingClient(objectService: $objects, container: $container, answers: new SigningAnswer()),
		);

	}//end service()
}//end class
