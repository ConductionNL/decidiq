<?php

/**
 * Decidiq Transcription Source Resolver
 *
 * Lists candidate transcription sources for a meeting: audio files in the
 * meeting's NC Files folder and — best-effort — Talk call recordings of the
 * meeting's conversation.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the candidate transcription sources for a meeting.
 *
 * Two source families are offered:
 *  - audio files already present in the meeting's NC Files folder (always
 *    available — the meeting folder service guarantees the folder tree);
 *  - Talk call recordings of the meeting's bound conversation, discovered
 *    best-effort via the Talk integration. Talk absence is NOT an error: the
 *    Files sources are still returned and the Talk family is simply empty.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionSourceResolver {

	/**
	 * File extensions treated as audio/recording sources.
	 *
	 * @var string[]
	 */
	public const AUDIO_EXTENSIONS = [
		'mp3',
		'wav',
		'm4a',
		'ogg',
		'oga',
		'opus',
		'flac',
		'mp4',
		'mka',
		'webm',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OR services).
	 * @param LoggerInterface $logger The logger.
	 * @param MeetingFolderService $folderService Meeting folder resolver.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly MeetingFolderService $folderService,
	) {
	}//end __construct()

	/**
	 * List the candidate transcription sources for a meeting.
	 *
	 * Returns a list of `{ type, path, name }` entries. `type` is one of
	 * `uploaded-file` (a file in the meeting folder) or `talk-recording` (a
	 * recording discovered via the Talk conversation). Always returns the
	 * Files sources; the Talk family is appended only when discoverable.
	 *
	 * @param array<string,mixed> $meeting Meeting object payload.
	 *
	 * @return array<int,array<string,string>> Candidate sources.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function listSources(array $meeting): array {
		$sources = $this->listMeetingFolderAudio(meeting: $meeting);

		foreach ($this->listTalkRecordings(meeting: $meeting) as $recording) {
			$sources[] = $recording;
		}

		return $sources;
	}//end listSources()

	/**
	 * List audio files in the meeting's NC Files folder.
	 *
	 * @param array<string,mixed> $meeting Meeting object payload.
	 *
	 * @return array<int,array<string,string>> Uploaded-file sources.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function listMeetingFolderAudio(array $meeting): array {
		$meetingPath = $this->folderService->ensureMeetingFolders(meeting: $meeting);
		if ($meetingPath === null) {
			return [];
		}

		try {
			$fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
			$folderNode = $fileService->createFolder($meetingPath);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq TranscriptionSourceResolver: cannot open meeting folder',
				['path' => $meetingPath, 'error' => $e->getMessage()]
			);
			return [];
		}

		$sources = [];
		try {
			foreach ($folderNode->getDirectoryListing() as $node) {
				$name = (string)$node->getName();
				if ($this->isAudioFile(fileName: $name) === false) {
					continue;
				}

				$sources[] = [
					'type' => 'uploaded-file',
					'path' => $meetingPath . '/' . $name,
					'name' => $name,
				];
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq TranscriptionSourceResolver: directory listing failed',
				['path' => $meetingPath, 'error' => $e->getMessage()]
			);
		}

		return $sources;
	}//end listMeetingFolderAudio()

	/**
	 * Best-effort discovery of Talk call recordings for the meeting.
	 *
	 * The Talk integration binds a conversation token to the meeting object
	 * (talkConversationToken / relations). When Talk is absent or the
	 * conversation has no recording, this returns an empty list rather than
	 * throwing — provider/integration absence is a first-class empty state.
	 *
	 * Recordings produced by Talk land as files in the recording owner's
	 * Files; when the meeting folder already mirrors them they surface via the
	 * folder listing above. Here we surface any recording reference recorded on
	 * the meeting object itself so the UI can offer it explicitly.
	 *
	 * @param array<string,mixed> $meeting Meeting object payload.
	 *
	 * @return array<int,array<string,string>> Talk-recording sources.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function listTalkRecordings(array $meeting): array {
		// The Talk integration stores recording file references on the meeting
		// (set by the Talk leaf when a call recording finishes). We read those
		// references rather than calling the Talk HTTP API directly, keeping
		// this resolver dependency-free and degrading to an empty list when no
		// Talk recording reference is present.
		$recordings = ($meeting['talkRecordings'] ?? null);
		if (is_array($recordings) === false || $recordings === []) {
			return [];
		}

		$sources = [];
		foreach ($recordings as $recording) {
			$path = '';
			$name = '';
			if (is_array($recording) === true) {
				$path = (string)($recording['path'] ?? '');
				$name = (string)($recording['name'] ?? basename($path));
			}

			if (is_array($recording) === false) {
				$path = (string)$recording;
				$name = basename($path);
			}

			if ($path === '') {
				continue;
			}

			$sources[] = [
				'type' => 'talk-recording',
				'path' => $path,
				'name' => $name,
			];
		}//end foreach

		return $sources;
	}//end listTalkRecordings()

	/**
	 * Whether a file name has an audio/recording extension.
	 *
	 * @param string $fileName File name including extension.
	 *
	 * @return bool True for recognised audio/recording files.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isAudioFile(string $fileName): bool {
		$ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
		return in_array($ext, self::AUDIO_EXTENSIONS, true);
	}//end isAudioFile()
}//end class
