<?php

/**
 * Wire-contract tests for the meeting-transcription action endpoints.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
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

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\TranscriptionController;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MinutesDraftService;
use OCA\Decidiq\Service\RecordingRange;
use OCA\Decidiq\Service\TranscriptionQueue;
use OCA\Decidiq\Service\TranscriptionService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the four transcription action routes:
 *
 *   GET  /api/meetings/{meetingId}/transcription/sources
 *   POST /api/meetings/{meetingId}/transcription/attach
 *   POST /api/transcripts/{transcriptId}/transcribe
 *   POST /api/transcripts/{transcriptId}/re-align
 *
 * Two properties carry the weight here and neither is visible from a status
 * code alone.
 *
 * First, every action is `#[NoAdminRequired]` and guarded per-object by
 * TranscriptionStaffGuard. Each test that exercises a denial asserts the guard's
 * response is returned AND that the service was never called — a guard whose
 * verdict is computed and then ignored produces a 403 body on a request that
 * already did its work.
 *
 * Second, `attach` records a consent decision about recording people's voices.
 * The consent precondition is asserted for the falsy shapes an HTML form
 * actually sends, because a check written as a bare truthiness test accepts the
 * string "false" and records consent nobody gave.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock TranscriptionService.
	 *
	 * @var TranscriptionService&MockObject
	 */
	private TranscriptionService&MockObject $transcriptionService;

	/**
	 * Mock MinutesDraftService.
	 *
	 * @var MinutesDraftService&MockObject
	 */
	private MinutesDraftService&MockObject $minutesDraftService;

	/**
	 * Mock TranscriptionStaffGuard.
	 *
	 * @var TranscriptionStaffGuard&MockObject
	 */
	private TranscriptionStaffGuard&MockObject $staffGuard;

	/**
	 * Mock TranscriptionQueue.
	 *
	 * @var TranscriptionQueue&MockObject
	 */
	private TranscriptionQueue&MockObject $queue;

	/**
	 * The controller under test.
	 *
	 * @var TranscriptionController
	 */
	private TranscriptionController $controller;

	/**
	 * Set up mocks and the controller. The guard defaults to ALLOW (null) so a
	 * test that cares about denial has to say so explicitly.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->transcriptionService = $this->createMock(TranscriptionService::class);
		$this->minutesDraftService = $this->createMock(MinutesDraftService::class);
		$this->staffGuard = $this->createMock(TranscriptionStaffGuard::class);
		$this->queue = $this->createMock(TranscriptionQueue::class);

		$this->staffGuard->method('forMeeting')->willReturn(null);
		$this->staffGuard->method('forTranscript')->willReturn(null);
		$this->staffGuard->method('currentUserId')->willReturn('griffier');

		$this->controller = new TranscriptionController(
			$this->request,
			$this->transcriptionService,
			$this->minutesDraftService,
			$this->staffGuard,
			$this->queue,
			new RecordingRange(),
		);

	}//end setUp()

	/**
	 * Rebuild the controller with a guard that DENIES every meeting/transcript.
	 *
	 * @param int $status The HTTP status the guard answers with.
	 *
	 * @return void
	 */
	private function denyGuard(int $status = Http::STATUS_FORBIDDEN): void {
		$this->staffGuard = $this->createMock(TranscriptionStaffGuard::class);
		$denial = new JSONResponse(['message' => 'Chair or secretary role required.'], $status);
		$this->staffGuard->method('forMeeting')->willReturn($denial);
		$this->staffGuard->method('forTranscript')->willReturn($denial);
		$this->staffGuard->method('forTranscriptListener')->willReturn($denial);
		$this->staffGuard->method('currentUserId')->willReturn('raadslid');

		$this->controller = new TranscriptionController(
			$this->request,
			$this->transcriptionService,
			$this->minutesDraftService,
			$this->staffGuard,
			$this->queue,
			new RecordingRange(),
		);

	}//end denyGuard()

	/**
	 * sources() returns the candidate list plus both provider-availability
	 * flags. The UI hides the transcribe and draft buttons off these flags, so
	 * dropping either one silently offers an action that cannot run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testSourcesReturnsCandidatesAndProviderFlags(): void {
		$sources = [['type' => 'talk-recording', 'path' => '/Talk/rec-1.mp3']];
		$this->transcriptionService->method('listSources')->with(meetingId: 'meeting-1')->willReturn($sources);
		$this->transcriptionService->method('isProviderAvailable')->willReturn(true);
		$this->minutesDraftService->method('isProviderAvailable')->willReturn(false);

		$response = $this->controller->sources(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($sources, $response->getData()['sources']);
		self::assertTrue($response->getData()['providerAvailable']);
		self::assertFalse($response->getData()['aiAvailable']);

	}//end testSourcesReturnsCandidatesAndProviderFlags()

	/**
	 * A non-staff caller gets the guard's 403 and the service is never asked
	 * for the meeting's sources.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testSourcesDeniedForNonStaff(): void {
		$this->denyGuard();
		$this->transcriptionService->expects($this->never())->method('listSources');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->sources(meetingId: 'meeting-1')->getStatus()
		);

	}//end testSourcesDeniedForNonStaff()

	/**
	 * An unknown meeting on sources() is 404 rather than an unhandled 500.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testSourcesUnknownMeetingIs404(): void {
		$this->transcriptionService->method('listSources')
			->willThrowException(new MissingObjectException('Meeting not found.'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->sources(meetingId: 'ghost')->getStatus()
		);

	}//end testSourcesUnknownMeetingIs404()

	/**
	 * attach() creates the Transcript and answers 201 with its body, forwarding
	 * the consent confirmer resolved server-side from the session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testAttachWithConsentReturns201(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['consent', null, true],
				['sourceType', '', 'talk-recording'],
				['sourcePath', '', '/Talk/rec-1.mp3'],
				['language', '', 'nl'],
			]
		);

		$this->transcriptionService->expects($this->once())
			->method('attach')
			->with(
				meetingId: 'meeting-1',
				sourceType: 'talk-recording',
				sourcePath: '/Talk/rec-1.mp3',
				confirmedBy: 'griffier',
				language: 'nl'
			)
			->willReturn(['id' => 'transcript-1', 'status' => 'attached']);

		$response = $this->controller->attach(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('transcript-1', $response->getData()['id']);

	}//end testAttachWithConsentReturns201()

	/**
	 * Without an affirmative consent value attach() is 422 and no Transcript is
	 * created. The rejected values include the strings a form serialises a
	 * false checkbox to — a truthiness check would accept "false" and "0".
	 *
	 * @param mixed $consent The consent value as it arrives on the wire.
	 *
	 * @return void
	 *
	 * @dataProvider nonConsentingValues
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testAttachWithoutConsentIs422(mixed $consent): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($consent): mixed {
				if ($key === 'consent') {
					return $consent;
				}

				return $default;
			}
		);

		$this->transcriptionService->expects($this->never())->method('attach');

		$response = $this->controller->attach(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('Consent', $response->getData()['message']);

	}//end testAttachWithoutConsentIs422()

	/**
	 * Wire shapes that must NOT count as consent.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonConsentingValues(): array {
		return [
			'absent' => [null],
			'boolean false' => [false],
			'string false' => ['false'],
			'string zero' => ['0'],
			'integer zero' => [0],
			'empty string' => [''],
		];

	}//end nonConsentingValues()

	/**
	 * transcribe() enqueues the job and answers `{ status: queued }`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testTranscribeEnqueuesAndReturnsQueued(): void {
		$this->transcriptionService->expects($this->once())
			->method('submit')
			->with(transcriptId: 'transcript-1')
			->willReturn(['status' => 'submitted']);
		$this->queue->expects($this->once())->method('enqueue')->with(transcriptId: 'transcript-1');

		$response = $this->controller->transcribe(transcriptId: 'transcript-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('queued', $response->getData()['status']);

	}//end testTranscribeEnqueuesAndReturnsQueued()

	/**
	 * A missing consent precondition surfaces as 422 and NOTHING is enqueued —
	 * a queued job would transcribe the recording anyway.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testTranscribeWithoutConsentIs422AndDoesNotEnqueue(): void {
		$this->transcriptionService->method('submit')
			->willThrowException(new \DomainException('Consent has not been recorded.', 422));
		$this->queue->expects($this->never())->method('enqueue');

		self::assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->transcribe(transcriptId: 'transcript-1')->getStatus()
		);

	}//end testTranscribeWithoutConsentIs422AndDoesNotEnqueue()

	/**
	 * No SpeechToText provider is 503 — distinguishable from the 422 refusal so
	 * the client can offer "retry later" rather than "fix your request".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testTranscribeWithoutProviderIs503(): void {
		$this->transcriptionService->method('submit')
			->willThrowException(new \DomainException('No SpeechToText provider is available.', 503));
		$this->queue->expects($this->never())->method('enqueue');

		self::assertSame(
			Http::STATUS_SERVICE_UNAVAILABLE,
			$this->controller->transcribe(transcriptId: 'transcript-1')->getStatus()
		);

	}//end testTranscribeWithoutProviderIs503()

	/**
	 * A non-staff caller cannot start a transcription: guard status returned,
	 * nothing submitted, nothing enqueued.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testTranscribeDeniedForNonStaff(): void {
		$this->denyGuard();
		$this->transcriptionService->expects($this->never())->method('submit');
		$this->queue->expects($this->never())->method('enqueue');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->transcribe(transcriptId: 'transcript-1')->getStatus()
		);

	}//end testTranscribeDeniedForNonStaff()

	/**
	 * realign() returns the re-aligned transcript body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testRealignReturnsAlignedTranscript(): void {
		$aligned = ['id' => 'transcript-1', 'segments' => [['agendaItem' => 'item-1', 'text' => 'Opening.']]];
		$this->transcriptionService->expects($this->once())
			->method('align')
			->with(transcriptId: 'transcript-1')
			->willReturn($aligned);

		$response = $this->controller->realign(transcriptId: 'transcript-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($aligned, $response->getData());

	}//end testRealignReturnsAlignedTranscript()

	/**
	 * An unknown transcript on realign() is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testRealignUnknownTranscriptIs404(): void {
		$this->transcriptionService->method('align')
			->willThrowException(new MissingObjectException('Transcript not found.'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->realign(transcriptId: 'ghost')->getStatus()
		);

	}//end testRealignUnknownTranscriptIs404()

	/**
	 * A non-staff caller cannot re-align someone else's transcript.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function testRealignDeniedForNonStaff(): void {
		$this->denyGuard();
		$this->transcriptionService->expects($this->never())->method('align');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->realign(transcriptId: 'transcript-1')->getStatus()
		);

	}//end testRealignDeniedForNonStaff()

	/**
	 * The headers a response was given (getHeaders() needs a running server).
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headersOf(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		return (array)$property->getValue($response);
	}//end headersOf()

	/**
	 * A recording file double of the given bytes.
	 *
	 * @param string $bytes The content.
	 *
	 * @return \OCP\Files\File
	 */
	private function recording(string $bytes): \OCP\Files\File {
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getSize')->willReturn(strlen($bytes));
		$file->method('getMimeType')->willReturn('audio/mpeg');
		$file->method('fopen')->willReturnCallback(
			static function () use ($bytes) {
				$stream = fopen('php://memory', 'w+');
				fwrite($stream, $bytes);
				rewind($stream);
				return $stream;
			}
		);
		return $file;
	}//end recording()

	/**
	 * Scenario "A member replays a debate": the player seeks, so the
	 * recording answers a byte range with 206 and the bytes from there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function testTheRecordingPlaysFromTheRequestedByte(): void {
		$this->request->method('getHeader')->with('Range')->willReturn('bytes=4-');
		$this->transcriptionService->method('recordingFile')->with(transcriptId: 't-1')->willReturn($this->recording('0123456789'));

		$response = $this->controller->recording(transcriptId: 't-1');

		self::assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		self::assertSame('bytes 4-9/10', $this->headersOf($response)['Content-Range']);
		self::assertSame('6', $this->headersOf($response)['Content-Length']);
		self::assertSame('bytes', $this->headersOf($response)['Accept-Ranges']);
		self::assertSame('audio/mpeg', $this->headersOf($response)['Content-Type']);
		$body = (new \ReflectionProperty(\OCP\AppFramework\Http\StreamResponse::class, 'filePath'))->getValue($response);
		self::assertSame('456789', stream_get_contents($body));
	}//end testTheRecordingPlaysFromTheRequestedByte()

	/**
	 * Without a range the whole recording is served, saying ranges work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function testTheWholeRecordingWithoutARange(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->transcriptionService->method('recordingFile')->willReturn($this->recording('0123456789'));

		$response = $this->controller->recording(transcriptId: 't-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('10', $this->headersOf($response)['Content-Length']);
		self::assertSame('bytes', $this->headersOf($response)['Accept-Ranges']);
	}//end testTheWholeRecordingWithoutARange()

	/**
	 * Someone outside the meeting gets the guard's 403 and no file is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-recording-jump-to-item/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function testTheRecordingIsRefusedToAnOutsider(): void {
		$this->denyGuard();
		$this->transcriptionService->expects($this->never())->method('recordingFile');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->recording(transcriptId: 't-1')->getStatus());
	}//end testTheRecordingIsRefusedToAnOutsider()

	/**
	 * The recording route reaches the method.
	 *
	 * @return void
	 */
	public function testTheRecordingRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');

		self::assertSame('/api/transcripts/{transcriptId}/recording', ($byName['transcription#recording'] ?? null));
	}//end testTheRecordingRouteReachesTheController()
}//end class
