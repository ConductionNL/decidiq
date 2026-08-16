<?php

/**
 * Unit tests for TranscriptionService.
 *
 * Covers consent refusal, provider-absent unavailable state, status lifecycle
 * (including the failure branch), neutral-label segment parsing, and the pure
 * agenda-alignment matrix (in-window / out-of-window / re-run / no-timeline).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingFolderService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\Decidesk\Service\TranscriptionService;
use OCA\Decidesk\Service\TranscriptionSourceResolver;
use OCP\SpeechToText\ISpeechToTextManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for TranscriptionService.
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 */
class TranscriptionServiceTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mock source resolver.
	 *
	 * @var TranscriptionSourceResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private TranscriptionSourceResolver $sourceResolver;

	/**
	 * Mock folder service.
	 *
	 * @var MeetingFolderService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private MeetingFolderService $folderService;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sourceResolver = $this->createMock(TranscriptionSourceResolver::class);
		$this->folderService = $this->createMock(MeetingFolderService::class);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return TranscriptionService
	 */
	private function service(?object $objectService = null, ?object $fileService = null): TranscriptionService {
		// TranscriptRepository takes its FileService and ObjectService directly
		// now (ADR-084), so they arrive here rather than being pulled out of
		// $this->container. Tests that assert on repository behaviour pass
		// their own double; the rest get inert mocks.
		return new TranscriptionService(
			$this->container,
			$this->logger,
			$this->sourceResolver,
			$this->folderService,
			$fileService ?? $this->createMock(FileService::class),
			$objectService ?? $this->createMock(ObjectServiceInterface::class)
		);

	}//end service()

	/**
	 * ObjectEntity double returning data from jsonSerialize().
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * is typed `?ObjectEntity` in production, so a stdClass mock is a value the
	 * service can never hand the code under test (#399).
	 *
	 * @param array<string,mixed> $data Object data.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		$mock = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mock->method('jsonSerialize')->willReturn($data);
		return $mock;
	}//end entity()

	/**
	 * Test that submit() refuses without a recorded consent confirmation.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testSubmitRefusedWithoutConsent(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity(['id' => 't1', 'status' => 'pending']));

		$this->container->method('get')->willReturnCallback(
			fn (string $id) => $id === 'OCA\OpenRegister\Service\ObjectService' ? $objectService : throw new \RuntimeException('x')
		);

		$this->expectException(\DomainException::class);
		$this->expectExceptionCode(422);
		$this->service(objectService: $objectService)->submit(transcriptId: 't1');

	}//end testSubmitRefusedWithoutConsent()

	/**
	 * Test that submit() reports unavailable (503) when no STT provider exists.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testSubmitReportsUnavailableWithoutProvider(): void {
		$transcript = [
			'id' => 't1',
			'status' => 'pending',
			'consent' => ['confirmedBy' => 'alice', 'confirmedAt' => '2026-01-01T00:00:00Z'],
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity($transcript));

		$sttManager = $this->createMock(ISpeechToTextManager::class);
		$sttManager->method('hasProviders')->willReturn(false);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $sttManager) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objectService,
					ISpeechToTextManager::class => $sttManager,
					default => throw new \RuntimeException('x'),
				};
			}
		);

		$this->expectException(\DomainException::class);
		$this->expectExceptionCode(503);
		$this->service(objectService: $objectService)->submit(transcriptId: 't1');

	}//end testSubmitReportsUnavailableWithoutProvider()

	/**
	 * Test isProviderAvailable() false when the STT manager is absent from DI.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testProviderUnavailableWhenManagerAbsent(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no manager'));
		self::assertFalse($this->service()->isProviderAvailable());

	}//end testProviderUnavailableWhenManagerAbsent()

	/**
	 * Test isProviderAvailable() true when a provider is registered.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testProviderAvailableWhenRegistered(): void {
		$sttManager = $this->createMock(ISpeechToTextManager::class);
		$sttManager->method('hasProviders')->willReturn(true);
		$this->container->method('get')->with(ISpeechToTextManager::class)->willReturn($sttManager);
		self::assertTrue($this->service()->isProviderAvailable());

	}//end testProviderAvailableWhenRegistered()

	/**
	 * Test that segments parsed from a structured result carry neutral labels.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testParseSegmentsUsesNeutralLabels(): void {
		$raw = json_encode(
			[
				['startTime' => 0, 'endTime' => 5, 'speaker' => 'john-doe-uid', 'text' => 'Hello'],
				['startTime' => 5, 'endTime' => 9, 'speaker' => 'jane-uid', 'text' => 'Hi'],
				['startTime' => 9, 'endTime' => 12, 'speaker' => 'john-doe-uid', 'text' => 'Again'],
			]
		);

		$segments = $this->service()->parseSegments(raw: $raw);

		self::assertCount(3, $segments);
		self::assertSame('Speaker 1', $segments[0]['speakerLabel']);
		self::assertSame('Speaker 2', $segments[1]['speakerLabel']);
		// Same provider speaker key maps back to the same neutral label.
		self::assertSame('Speaker 1', $segments[2]['speakerLabel']);
		// No participant identity is retained anywhere in the segment.
		self::assertStringNotContainsString('uid', json_encode($segments[0]));

	}//end testParseSegmentsUsesNeutralLabels()

	/**
	 * Test plain-text provider output parses into a single neutral segment.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testParseSegmentsPlainTextFallback(): void {
		$segments = $this->service()->parseSegments(raw: 'The whole meeting transcript.');
		self::assertCount(1, $segments);
		self::assertSame('Speaker 1', $segments[0]['speakerLabel']);
		self::assertSame('The whole meeting transcript.', $segments[0]['text']);

	}//end testParseSegmentsPlainTextFallback()

	/**
	 * Test the alignment matrix: in-window vs out-of-window segments.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testAlignSegmentsInAndOutOfWindow(): void {
		$segments = [
			['startTime' => 10, 'endTime' => 20, 'text' => 'in item A'],
			['startTime' => 130, 'endTime' => 140, 'text' => 'in item B'],
			['startTime' => 999, 'endTime' => 1000, 'text' => 'out of window'],
		];
		$timeline = [
			['agendaItem' => 'A', 'start' => 0, 'end' => 120],
			['agendaItem' => 'B', 'start' => 120, 'end' => 240],
		];

		$aligned = $this->service()->alignSegments(segments: $segments, timeline: $timeline);

		self::assertSame('A', $aligned[0]['agendaItem']);
		self::assertSame('B', $aligned[1]['agendaItem']);
		self::assertArrayNotHasKey('agendaItem', $aligned[2]);

	}//end testAlignSegmentsInAndOutOfWindow()

	/**
	 * Test re-alignment after a corrected timeline reassigns without re-transcribing.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testAlignSegmentsReRunReflectsCorrectedTimeline(): void {
		$segments = [['startTime' => 10, 'endTime' => 20, 'agendaItem' => 'A', 'text' => 'x']];

		// Corrected timeline: the window that used to be A now belongs to B.
		$timeline = [['agendaItem' => 'B', 'start' => 0, 'end' => 120]];

		$aligned = $this->service()->alignSegments(segments: $segments, timeline: $timeline);
		self::assertSame('B', $aligned[0]['agendaItem']);

	}//end testAlignSegmentsReRunReflectsCorrectedTimeline()

	/**
	 * Test the no-timeline fallback leaves all segments unassigned (flat).
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testAlignSegmentsNoTimelineFlatFallback(): void {
		$segments = [
			['startTime' => 10, 'endTime' => 20, 'agendaItem' => 'A', 'text' => 'x'],
			['startTime' => 30, 'endTime' => 40, 'text' => 'y'],
		];

		$aligned = $this->service()->alignSegments(segments: $segments, timeline: []);
		self::assertArrayNotHasKey('agendaItem', $aligned[0]);
		self::assertArrayNotHasKey('agendaItem', $aligned[1]);

	}//end testAlignSegmentsNoTimelineFlatFallback()

	/**
	 * Test the failure branch: a provider error stores status=failed + reason.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testProcessFailureBranchStoresFailedStatus(): void {
		$transcript = [
			'id' => 't1',
			'status' => 'pending',
			'sourceFilePath' => 'Decidesk/x/rec.mp3',
			'consent' => ['confirmedBy' => 'alice', 'confirmedAt' => '2026-01-01T00:00:00Z'],
			'relations' => ['meeting' => 'm1'],
		];

		$saved = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity($transcript));
		$objectService->method('saveObject')->willReturnCallback(
			// saveObject() is typed `: ObjectEntity` in production and can never
			// return the payload array it was handed (#399).
			function (array $object) use (&$saved): object {
				$saved = $object;
				return $this->entity($object);
			}
		);

		// STT manager whose transcribeFile throws (provider error).
		$sttManager = $this->createMock(ISpeechToTextManager::class);
		$sttManager->method('transcribeFile')->willThrowException(new \RuntimeException('engine down'));

		// FileService whose folder resolution would work, but transcribeFile fails first.
		$fileNode = $this->createMock(\OCP\Files\File::class);
		$fileService = $this->getMockBuilder(\stdClass::class)->addMethods(['createFolder'])->getMock();
		$fileService->method('createFolder')->willReturn(
			(function () use ($fileNode) {
				$folder = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
				$folder->method('get')->willReturn($fileNode);
				return $folder;
			})()
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $sttManager, $fileService) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objectService,
					'OCA\OpenRegister\Service\FileService' => $fileService,
					ISpeechToTextManager::class => $sttManager,
					default => throw new \RuntimeException('x'),
				};
			}
		);

		$result = $this->service(objectService: $objectService)->process(transcriptId: 't1');

		self::assertSame('failed', $saved['status']);
		self::assertStringContainsString('engine down', (string)$saved['failureReason']);

	}//end testProcessFailureBranchStoresFailedStatus()
}//end class
