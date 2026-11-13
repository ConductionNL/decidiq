<?php

/**
 * Unit tests for TranscriptionService's attach / process / align lifecycle.
 *
 * Complements TranscriptionServiceTest (consent refusal, provider absence,
 * parsing and the pure alignment matrix) with the persisted lifecycle: source
 * listing, attach validation and payload, the successful processing path with
 * the transcript file write and agenda alignment, and the retention policy.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\TranscriptionService;
use OCA\Decidiq\Service\TranscriptionSourceResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\SpeechToText\ISpeechToTextManager;
use OCP\SpeechToText\ISpeechToTextProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle tests for TranscriptionService.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionServiceLifecycleTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock source resolver.
	 *
	 * @var TranscriptionSourceResolver&MockObject
	 */
	private TranscriptionSourceResolver&MockObject $sourceResolver;

	/**
	 * Mock folder service.
	 *
	 * @var MeetingFolderService&MockObject
	 */
	private MeetingFolderService&MockObject $folderService;

	/**
	 * Mock OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock OpenRegister file service.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * Objects the fake register holds, keyed "schema/id".
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $objects = [];

	/**
	 * Every saveObject() payload, in order, as [schema, object, uuid].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>,2:string|null}>
	 */
	private array $saves = [];

	/**
	 * Set up fixtures: an in-memory register behind the object service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sourceResolver = $this->createMock(TranscriptionSourceResolver::class);
		$this->folderService = $this->createMock(MeetingFolderService::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend = [], bool $files = false, $register = null, $schema = null): object {
				$key = $schema . '/' . $id;
				if (isset($this->objects[$key]) === false) {
					throw new DoesNotExistException('absent');
				}

				return $this->entity($this->objects[$key]);
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, $uuid = null): object {
				$this->saves[] = [(string)$schema, $object, $uuid];
				if ($uuid !== null) {
					$this->objects[$schema . '/' . $uuid] = $object;
				} else {
					$object['id'] = 'new-' . count($this->saves);
				}

				return $this->entity($object);
			}
		);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return TranscriptionService
	 */
	private function service(): TranscriptionService {
		return new TranscriptionService(
			container: $this->container,
			logger: $this->logger,
			sourceResolver: $this->sourceResolver,
			folderService: $this->folderService,
			fileService: $this->fileService,
			objectService: $this->objectService,
		);

	}//end service()

	/**
	 * ObjectEntity double returning data from jsonSerialize().
	 *
	 * @param array<string,mixed> $data Object data.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		$mock = $this->createMock(ObjectEntity::class);
		$mock->method('jsonSerialize')->willReturn($data);
		return $mock;

	}//end entity()

	/**
	 * Point the source path of a transcript at a resolvable file node.
	 *
	 * @param string $dir Folder the file service is asked for.
	 * @param string $base File name looked up in it.
	 *
	 * @return \OCP\Files\File The file node.
	 */
	private function sourceFile(string $dir, string $base): \OCP\Files\File {
		$file = $this->createMock(\OCP\Files\File::class);
		$folder = $this->createMock(\OCP\Files\Folder::class);
		$folder->expects(self::once())->method('get')->with($base)->willReturn($file);
		$this->fileService->expects(self::once())->method('createFolder')->with($dir)->willReturn($folder);
		return $file;

	}//end sourceFile()

	/**
	 * The container answers the SpeechToText manager and nothing else.
	 *
	 * @param ISpeechToTextManager $manager The manager.
	 *
	 * @return void
	 */
	private function containerHolds(ISpeechToTextManager $manager): void {
		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => $id === ISpeechToTextManager::class ? $manager : throw new \RuntimeException('x')
		);

	}//end containerHolds()

	/**
	 * A transcript with recorded consent.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function transcript(array $overrides = []): array {
		return array_replace(
			[
				'id' => 't1',
				'status' => 'pending',
				'sourceFilePath' => 'Decidiq/Raad/rec.mp3',
				'consent' => ['confirmedBy' => 'alice', 'confirmedAt' => '2026-01-01T00:00:00Z'],
				'relations' => ['meeting' => 'm1'],
			],
			$overrides
		);

	}//end transcript()

	/**
	 * A hasProviders() failure is logged and reported as unavailable.
	 *
	 * @return void
	 */
	public function testProviderUnavailableWhenHasProvidersThrows(): void {
		$manager = $this->createMock(ISpeechToTextManager::class);
		$manager->method('hasProviders')->willThrowException(new \RuntimeException('provider registry broken'));
		$this->containerHolds($manager);
		$this->logger->expects(self::once())->method('debug')->with(
			'Decidiq TranscriptionService: hasProviders() check failed',
			['error' => 'provider registry broken']
		);

		self::assertFalse($this->service()->isProviderAvailable());

	}//end testProviderUnavailableWhenHasProvidersThrows()

	/**
	 * listSources() hands the fetched meeting to the source resolver.
	 *
	 * @return void
	 */
	public function testListSourcesResolvesAgainstTheMeeting(): void {
		$meeting = ['id' => 'm1', 'title' => 'Raad'];
		$this->objects['meeting/m1'] = $meeting;
		$sources = [['type' => 'uploaded-file', 'path' => 'Decidiq/Raad/rec.mp3']];
		$this->sourceResolver->expects(self::once())->method('listSources')->with($meeting)->willReturn($sources);

		self::assertSame($sources, $this->service()->listSources(meetingId: 'm1'));

	}//end testListSourcesResolvesAgainstTheMeeting()

	/**
	 * listSources() for an unknown meeting raises MissingObjectException.
	 *
	 * @return void
	 */
	public function testListSourcesForUnknownMeetingIsMissing(): void {
		$this->sourceResolver->expects(self::never())->method('listSources');

		$this->expectException(MissingObjectException::class);
		$this->expectExceptionMessage('Meeting "nope" not found.');
		$this->service()->listSources(meetingId: 'nope');

	}//end testListSourcesForUnknownMeetingIsMissing()

	/**
	 * Invalid attach input.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function invalidAttachProvider(): array {
		return [
			'unknown source type' => ['dictaphone', 'a.mp3', 'alice', 'Invalid source type.'],
			'blank path' => ['uploaded-file', '  ', 'alice', 'A source file path is required.'],
			'blank consent' => ['talk-recording', 'a.mp3', ' ', 'A consent confirmation is required.'],
		];

	}//end invalidAttachProvider()

	/**
	 * attach() refuses invalid input with 422 before touching the register.
	 *
	 * @param string $type Source type.
	 * @param string $path Source path.
	 * @param string $by Consenting user.
	 * @param string $message Expected message.
	 *
	 * @return void
	 */
	#[DataProvider('invalidAttachProvider')]
	public function testAttachRefusesInvalidInput(string $type, string $path, string $by, string $message): void {
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionCode(422);
		$this->expectExceptionMessage($message);
		$this->service()->attach(meetingId: 'm1', sourceType: $type, sourcePath: $path, confirmedBy: $by);

	}//end testAttachRefusesInvalidInput()

	/**
	 * attach() creates a pending Transcript with consent, linked to the meeting,
	 * titled after the meeting's name when it has no title.
	 *
	 * @return void
	 */
	public function testAttachCreatesPendingTranscriptWithConsent(): void {
		$this->objects['meeting/m1'] = ['id' => 'm1', 'name' => 'Raadsvergadering'];

		$result = $this->service()->attach(
			meetingId: 'm1',
			sourceType: 'talk-recording',
			sourcePath: 'Decidiq/Raad/rec.mp3',
			confirmedBy: 'alice',
			language: 'nl'
		);

		self::assertCount(1, $this->saves);
		[$schema, $saved, $uuid] = $this->saves[0];
		self::assertSame('transcript', $schema);
		self::assertNull($uuid);
		self::assertSame('Raadsvergadering', $saved['title']);
		self::assertSame('talk-recording', $saved['sourceType']);
		self::assertSame('Decidiq/Raad/rec.mp3', $saved['sourceFilePath']);
		self::assertSame('nl', $saved['language']);
		self::assertSame('pending', $saved['status']);
		self::assertSame('active', $saved['retentionState']);
		self::assertSame('alice', $saved['consent']['confirmedBy']);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $saved['consent']['confirmedAt']));
		self::assertSame(['meeting' => 'm1'], $saved['relations']);
		self::assertSame('new-1', $result['id']);

	}//end testAttachCreatesPendingTranscriptWithConsent()

	/**
	 * submit() with consent and a provider returns the transcript unchanged.
	 *
	 * @return void
	 */
	public function testSubmitWithConsentAndProviderReturnsTranscript(): void {
		$this->objects['transcript/t1'] = $this->transcript();
		$manager = $this->createMock(ISpeechToTextManager::class);
		$manager->method('hasProviders')->willReturn(true);
		$this->containerHolds($manager);

		self::assertSame($this->transcript(), $this->service()->submit(transcriptId: 't1'));
		self::assertSame([], $this->saves, 'Submitting must not change the stored transcript.');

	}//end testSubmitWithConsentAndProviderReturnsTranscript()

	/**
	 * process() without consent marks the transcript failed without transcribing.
	 *
	 * @return void
	 */
	public function testProcessWithoutConsentMarksFailed(): void {
		$this->objects['transcript/t1'] = $this->transcript(['consent' => ['confirmedBy' => '']]);
		$this->container->expects(self::never())->method('get');

		$result = $this->service()->process(transcriptId: 't1');

		self::assertSame('failed', $result['status']);
		self::assertSame('Consent missing at processing time.', $result['failureReason']);
		self::assertCount(1, $this->saves);

	}//end testProcessWithoutConsentMarksFailed()

	/**
	 * The successful path: processing → transcribe → file written → done →
	 * aligned against the meeting's conduct timeline.
	 *
	 * @return void
	 */
	public function testProcessSuccessWritesFileAndAligns(): void {
		$meeting = ['id' => 'm1', 'title' => 'Raad'];
		$this->objects['meeting/m1'] = $meeting;
		$this->objects['transcript/t1'] = $this->transcript();
		$file = $this->sourceFile('Decidiq/Raad', 'rec.mp3');

		$provider = $this->createMock(ISpeechToTextProvider::class);
		$provider->method('getName')->willReturn('Whisper');
		$manager = $this->createMock(ISpeechToTextManager::class);
		$manager->expects(self::once())->method('transcribeFile')->with($file, null, 'decidesk')->willReturn(
			(string)json_encode(
				[
					['startTime' => 5, 'endTime' => 10, 'speaker' => 'spk-a', 'text' => 'Opening'],
					['startTime' => 70, 'endTime' => 80, 'speaker' => 'spk-b', 'text' => 'Begroting'],
				]
			)
		);
		$manager->method('getProviders')->willReturn(['whisper' => $provider]);
		$this->containerHolds($manager);

		$this->objectService->expects(self::once())->method('findAll')->with(
			self::callback(static fn (array $config): bool => $config['filters']['_relations.meeting'] === 'm1')
		)->willReturn(
			[
				['id' => 'A', 'actualStart' => '2026-01-01T10:00:00+00:00', 'actualDuration' => 60],
				['id' => 'B', 'actualStart' => '2026-01-01T10:01:00+00:00', 'actualDuration' => 60],
			]
		);

		$this->folderService->expects(self::once())->method('writeMeetingFile')->with(
			$meeting,
			'Minutes',
			'transcript-t1.txt',
			"Speaker 1: Opening\nSpeaker 2: Begroting"
		)->willReturn('Decidiq/Raad/Minutes/transcript-t1.txt');

		$result = $this->service()->process(transcriptId: 't1');

		self::assertSame(['processing', 'done', 'done'], array_map(static fn (array $s): string => $s[1]['status'], $this->saves));
		self::assertSame('done', $result['status']);
		self::assertSame('', $result['failureReason']);
		self::assertSame('Whisper', $result['providerId']);
		self::assertSame('Decidiq/Raad/Minutes/transcript-t1.txt', $result['transcriptFilePath']);
		self::assertSame('A', $result['segments'][0]['agendaItem']);
		self::assertSame('B', $result['segments'][1]['agendaItem']);
		self::assertArrayHasKey('alignedAt', $result);

	}//end testProcessSuccessWritesFileAndAligns()

	/**
	 * A transcript not linked to a meeting still completes: no file, no
	 * timeline, no provider name when the provider list cannot be read.
	 *
	 * @return void
	 */
	public function testProcessUnlinkedTranscriptCompletesFlat(): void {
		$this->objects['transcript/t1'] = $this->transcript(['relations' => []]);
		$this->sourceFile('Decidiq/Raad', 'rec.mp3');

		$manager = $this->createMock(ISpeechToTextManager::class);
		$manager->method('transcribeFile')->willReturn('Alles in een keer.');
		$manager->method('getProviders')->willThrowException(new \RuntimeException('registry gone'));
		$this->containerHolds($manager);
		$this->folderService->expects(self::never())->method('writeMeetingFile');
		$this->objectService->expects(self::never())->method('findAll');

		$result = $this->service()->process(transcriptId: 't1');

		self::assertSame('done', $result['status']);
		self::assertSame('', $result['providerId']);
		self::assertSame('', $result['transcriptFilePath']);
		self::assertSame('Alles in een keer.', $result['segments'][0]['text']);
		self::assertArrayNotHasKey('agendaItem', $result['segments'][0]);

	}//end testProcessUnlinkedTranscriptCompletesFlat()

	/**
	 * A linked meeting that no longer exists writes no file but still completes;
	 * a provider without a name leaves providerId empty.
	 *
	 * @return void
	 */
	public function testProcessWithVanishedMeetingWritesNoFile(): void {
		$this->objects['transcript/t1'] = $this->transcript(['relations' => ['meeting' => 'gone']]);
		$this->sourceFile('Decidiq/Raad', 'rec.mp3');

		$manager = $this->createMock(ISpeechToTextManager::class);
		$manager->method('transcribeFile')->willReturn('Tekst');
		$manager->method('getProviders')->willReturn([new \stdClass()]);
		$this->containerHolds($manager);
		$this->folderService->expects(self::never())->method('writeMeetingFile');
		$this->objectService->expects(self::once())->method('findAll')->willReturn([]);

		$result = $this->service()->process(transcriptId: 't1');

		self::assertSame('done', $result['status']);
		self::assertSame('', $result['providerId']);
		self::assertSame('', $result['transcriptFilePath']);

	}//end testProcessWithVanishedMeetingWritesNoFile()

	/**
	 * align() treats a non-array segments field as empty and stamps alignedAt.
	 *
	 * @return void
	 */
	public function testAlignWithMalformedSegmentsStoresEmptyList(): void {
		$this->objects['transcript/t1'] = $this->transcript(['relations' => [], 'segments' => 'corrupt']);

		$result = $this->service()->align(transcriptId: 't1');

		self::assertSame([], $result['segments']);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $result['alignedAt']));
		self::assertSame('t1', $this->saves[0][2]);

	}//end testAlignWithMalformedSegmentsStoresEmptyList()

	/**
	 * setRetentionPolicy() writes the policy onto the governance body.
	 *
	 * @return void
	 */
	public function testSetRetentionPolicyWritesOntoTheBody(): void {
		$this->objects['governance-body/b1'] = ['id' => 'b1', 'name' => 'Raad'];

		$this->service()->setRetentionPolicy(bodyId: 'b1', policy: 'delete-recording', days: 30);

		self::assertSame(
			[
				[
					'governance-body',
					['id' => 'b1', 'name' => 'Raad', 'transcriptRetentionPolicy' => 'delete-recording', 'transcriptRetentionDays' => 30],
					'b1',
				],
			],
			$this->saves
		);

	}//end testSetRetentionPolicyWritesOntoTheBody()
}//end class
