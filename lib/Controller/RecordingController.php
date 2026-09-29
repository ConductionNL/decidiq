<?php

/**
 * Decidiq Recording Controller
 *
 * Plays the meeting recording a transcript was made from, seekable, so a
 * member can jump to the moment an agenda item started.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\RecordingRange;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\Decidiq\Service\TranscriptRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Streams a meeting recording with byte ranges.
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */
class RecordingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param TranscriptionStaffGuard $guard Who may listen
	 * @param TranscriptRepository $repository Transcripts and their source files
	 * @param RecordingRange $range Byte ranges for the player
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function __construct(
		IRequest $request,
		private readonly TranscriptionStaffGuard $guard,
		private readonly TranscriptRepository $repository,
		private readonly RecordingRange $range,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Play the recording a transcript was made from.
	 *
	 * GET /api/transcripts/{transcriptId}/recording
	 *
	 * Any participant of the meeting may listen. Answers a Range request with
	 * 206 and those bytes, so the player can jump to the moment an agenda item
	 * started.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return Response
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function play(string $transcriptId): Response {
		$denied = $this->guard->forTranscriptListener(transcriptId: $transcriptId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			$transcript = $this->repository->fetchTranscript(transcriptId: $transcriptId);
			$file = $this->repository->resolveSourceNode(path: (string)($transcript['sourceFilePath'] ?? ''));
			$size = (int)$file->getSize();
			$range = $this->range->parse(header: $this->request->getHeader('Range'), size: $size);
			if ($range === false) {
				return new Response(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, ['Content-Range' => 'bytes */'.$size]);
			}

			[$first, $last] = ($range ?? [0, ($size - 1)]);
			$stream = $file->fopen('r');
			if ($first > 0) {
				fseek($stream, $first);
			}

			$length = ($last - $first + 1);
			$body = fopen('php://temp', 'w+');
			stream_copy_to_stream($stream, $body, $length);
			rewind($body);
		} catch (MissingObjectException | \RuntimeException | \OCP\Files\NotFoundException $e) {
			return new JSONResponse(['message' => 'The recording could not be found.'], Http::STATUS_NOT_FOUND);
		}//end try

		$headers = [
			'Content-Type' => (string)$file->getMimeType(),
			'Content-Length' => (string)$length,
			'Accept-Ranges' => 'bytes',
		];
		$status = Http::STATUS_OK;
		if ($range !== null) {
			$status = Http::STATUS_PARTIAL_CONTENT;
			$headers['Content-Range'] = 'bytes '.$first.'-'.$last.'/'.$size;
		}

		return new StreamResponse($body, $status, $headers);
	}//end play()
}//end class
