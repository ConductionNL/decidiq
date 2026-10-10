<?php

/**
 * The meeting recording plays in the browser and seeks: byte ranges, and who
 * may listen (live-recording-jump-to-item, matrix row liv-08).
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
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\RecordingController;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\RecordingRange;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\Decidiq\Service\TranscriptRepository;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Byte ranges for seeking, and the listener guard.
 */
class RecordingPlaybackTest extends TestCase {

	/**
	 * A browser that seeks asks for the rest of the file from a byte.
	 *
	 * @return void
	 */
	public function testAnOpenRangeRunsToTheEnd(): void {
		$this->assertSame([1000, 9999], (new RecordingRange())->parse(header: 'bytes=1000-', size: 10000));
	}//end testAnOpenRangeRunsToTheEnd()

	/**
	 * A closed range is served as asked, and one past the end is clipped.
	 *
	 * @return void
	 */
	public function testAClosedRangeIsServedAsAsked(): void {
		$range = new RecordingRange();
		$this->assertSame([0, 1], $range->parse(header: 'bytes=0-1', size: 10000));
		$this->assertSame([9000, 9999], $range->parse(header: 'bytes=9000-20000', size: 10000));
		$this->assertSame([9900, 9999], $range->parse(header: 'bytes=-100', size: 10000));
	}//end testAClosedRangeIsServedAsAsked()

	/**
	 * No header or one this does not understand serves the whole file; a
	 * start past the end cannot be served.
	 *
	 * @return void
	 */
	public function testNoRangeIsTheWholeFileAndAnImpossibleOneIsRefused(): void {
		$range = new RecordingRange();
		$this->assertNull($range->parse(header: null, size: 10000));
		$this->assertNull($range->parse(header: 'bytes=0-10,20-30', size: 10000));
		$this->assertNull($range->parse(header: 'items=1-2', size: 10000));
		$this->assertFalse($range->parse(header: 'bytes=20000-', size: 10000));
	}//end testNoRangeIsTheWholeFileAndAnImpossibleOneIsRefused()

	/**
	 * A guard over a transcript of meeting m-14 for the given caller.
	 *
	 * @param bool $participant Whether the caller takes part in m-14.
	 *
	 * @return TranscriptionStaffGuard
	 */
	private function guard(bool $participant): TranscriptionStaffGuard {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1', 'meeting' => 'm-14']);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('isParticipant')->with('m-14', 'pieter')->willReturn($participant);
		$resolver->method('hasRole')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new TranscriptionStaffGuard(objectService: $objectService, participantResolver: $resolver, userSession: $session, groupManager: $groups);
	}//end guard()

	/**
	 * Scenario "A member replays a debate": member Pieter, not chair or
	 * secretary, may play the recording of his meeting.
	 *
	 * @return void
	 */
	public function testAMemberOfTheMeetingMayListen(): void {
		$this->assertNull($this->guard(participant: true)->forTranscriptListener(transcriptId: 't-1'));
	}//end testAMemberOfTheMeetingMayListen()

	/**
	 * Someone who does not take part in the meeting may not.
	 *
	 * @return void
	 */
	public function testAnOutsiderMayNotListen(): void {
		$denied = $this->guard(participant: false)->forTranscriptListener(transcriptId: 't-1');
		$this->assertNotNull($denied);
		$this->assertSame(Http::STATUS_FORBIDDEN, $denied->getStatus());
	}//end testAnOutsiderMayNotListen()

	/**
	 * The recording controller for a caller the guard admits or not, with a
	 * Range header and a ten-byte recording.
	 *
	 * @param string|null $rangeHeader The Range header.
	 * @param bool        $admitted    Whether the guard admits the caller.
	 *
	 * @return RecordingController
	 */
	private function player(?string $rangeHeader, bool $admitted=true): RecordingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Range')->willReturn((string)$rangeHeader);
		$guard = $this->createMock(TranscriptionStaffGuard::class);
		$guard->method('forTranscriptListener')->willReturn($admitted === true ? null : new JSONResponse([], Http::STATUS_FORBIDDEN));
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(10);
		$file->method('getMimeType')->willReturn('audio/mpeg');
		$file->method('fopen')->willReturnCallback(
			static function () {
				$stream = fopen('php://memory', 'w+');
				fwrite($stream, '0123456789');
				rewind($stream);
				return $stream;
			}
		);
		$repository = $this->createMock(TranscriptRepository::class);
		$repository->method('fetchTranscript')->willReturn(['id' => 't-1', 'sourceFilePath' => 'Decidiq/Raad/opname.mp3']);
		$repository->method('resolveSourceNode')->with('Decidiq/Raad/opname.mp3')->willReturn($file);
		if ($admitted === false) {
			$repository->expects($this->never())->method('resolveSourceNode');
		}

		return new RecordingController(request: $request, guard: $guard, repository: $repository, range: new RecordingRange());
	}//end player()

	/**
	 * The headers a response was given (getHeaders() needs a running server).
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headersOf(Response $response): array {
		return (array)(new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * Scenario "A member replays a debate": the player seeks, so the
	 * recording answers a byte range with 206 and the bytes from there.
	 *
	 * @return void
	 */
	public function testTheRecordingPlaysFromTheRequestedByte(): void {
		$response = $this->player(rangeHeader: 'bytes=4-')->play(transcriptId: 't-1');

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$headers = $this->headersOf($response);
		$this->assertSame('bytes 4-9/10', $headers['Content-Range']);
		$this->assertSame('6', $headers['Content-Length']);
		$this->assertSame('bytes', $headers['Accept-Ranges']);
		$this->assertSame('audio/mpeg', $headers['Content-Type']);
		$body = (new \ReflectionProperty(StreamResponse::class, 'filePath'))->getValue($response);
		$this->assertSame('456789', stream_get_contents($body));
	}//end testTheRecordingPlaysFromTheRequestedByte()

	/**
	 * Without a range the whole recording is served, saying ranges work; a
	 * range past the end is 416.
	 *
	 * @return void
	 */
	public function testTheWholeRecordingWithoutARange(): void {
		$response = $this->player(rangeHeader: null)->play(transcriptId: 't-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('10', $this->headersOf($response)['Content-Length']);
		$this->assertSame('bytes', $this->headersOf($response)['Accept-Ranges']);

		$this->assertSame(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, $this->player(rangeHeader: 'bytes=50-')->play(transcriptId: 't-1')->getStatus());
	}//end testTheWholeRecordingWithoutARange()

	/**
	 * Someone outside the meeting gets the guard's 403 and no file is read.
	 *
	 * @return void
	 */
	public function testTheRecordingIsRefusedToAnOutsider(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->player(rangeHeader: null, admitted: false)->play(transcriptId: 't-1')->getStatus());
	}//end testTheRecordingIsRefusedToAnOutsider()

	/**
	 * The recording route reaches the method.
	 *
	 * @return void
	 */
	public function testTheRecordingRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');

		$this->assertSame('/api/transcripts/{transcriptId}/recording', ($byName['recording#play'] ?? null));
	}//end testTheRecordingRouteReachesTheController()
}//end class
