<?php

/**
 * Decidiq Broadcast Caption Service
 *
 * Makes the subtitles of a public broadcast and releases them. The subtitles
 * come from the meeting's transcript once it is aligned with the agenda, and
 * hold only what was said inside a public window: a segment that starts in a
 * closed session never reaches the file. Each cue is moved onto the
 * recording's clock (`segment.start - window.start + window.recordingStart`)
 * and carries no speaker label.
 *
 * The caption file stays private until the chair or secretary releases it.
 * Release needs a public meeting and an ended broadcast; it creates a
 * read-only link share, records the track on the broadcast and asks the
 * streaming service to attach it to the recording when it can. The file is
 * named `captions-<language>.vtt`, so the publication deny-list (which refuses
 * names holding `recording` or `transcript`) does not apply to it, while the
 * Transcript object and its text file stay refused.
 *
 * Writes happen in system context after the controller checked that the
 * caller is the meeting's chair or secretary (TranscriptionStaffGuard).
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Derive and release the caption track of a meeting's broadcast.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
 */
class BroadcastCaptionService {
	/**
	 * The meeting subfolder the caption files go to.
	 */
	public const SUBFOLDER = 'Broadcast';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param MeetingFolderService   $folders       Writes and finds files in the meeting folder
	 * @param IManager               $shares        Creates the public link share
	 * @param IURLGenerator          $urls          Builds the share's public address
	 * @param IUserSession           $userSession   Who releases the track
	 * @param ITimeFactory           $time          The clock
	 * @param StreamingClient        $streaming     The streaming service, asked to attach the track
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MeetingFolderService $folders,
		private readonly IManager $shares,
		private readonly IURLGenerator $urls,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
		private readonly StreamingClient $streaming,
	) {
	}//end __construct()

	/**
	 * Make the caption file of a broadcast from the meeting's aligned transcript.
	 *
	 * @param string $broadcastId The broadcast
	 * @param string $language    The track's language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @throws BroadcastRefusedException 404 unknown broadcast, 409 no finished transcript, 422 not aligned or a bad language, 500 not written
	 *
	 * @return array{language: string, filePath: string, cues: int}
	 */
	public function derive(string $broadcastId, string $language): array {
		$this->requireLanguage(language: $language);
		$broadcast  = $this->load(broadcastId: $broadcastId);
		$meetingId  = (string)($broadcast['meeting'] ?? '');
		$transcript = $this->finishedTranscript(meetingId: $meetingId);
		if (is_string($transcript['alignedAt'] ?? null) === false || $transcript['alignedAt'] === '') {
			throw new BroadcastRefusedException(message: 'Align the transcript with the agenda first', status: 422);
		}

		$segments = (array)($transcript['segments'] ?? []);
		$windows  = (array)($broadcast['publicWindows'] ?? []);
		$vtt      = $this->webVtt(segments: $segments, windows: $windows);
		$meeting  = (['id' => $meetingId] + ($this->read(schema: 'meeting', id: $meetingId) ?? []));
		$path     = $this->folders->writeMeetingFile(
			meeting: $meeting,
			subfolder: self::SUBFOLDER,
			fileName: $this->fileName(language: $language),
			content: $vtt
		);
		if ($path === null) {
			throw new BroadcastRefusedException(message: 'The caption file could not be written', status: 500);
		}

		return ['language' => $language, 'filePath' => $path, 'cues' => substr_count($vtt, ' --> ')];
	}//end derive()

	/**
	 * Release a reviewed caption track: a read-only link share, recorded on the broadcast.
	 *
	 * @param string $broadcastId The broadcast
	 * @param string $language    The track's language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @throws BroadcastRefusedException 404 unknown broadcast, 409 not ended or no file yet, 422 meeting not public
	 *
	 * @return array<string, mixed> The stored broadcast, with its id and whether the service attached the track
	 */
	public function release(string $broadcastId, string $language): array {
		$this->requireLanguage(language: $language);
		$broadcast = $this->load(broadcastId: $broadcastId);
		$meetingId = (string)($broadcast['meeting'] ?? '');
		$meeting   = ($this->read(schema: 'meeting', id: $meetingId) ?? []);
		if (($meeting['isPublic'] ?? false) !== true) {
			throw new BroadcastRefusedException(message: 'Only the subtitles of a public meeting can be released', status: 422);
		}

		if (($broadcast['lifecycle'] ?? '') !== 'ended') {
			throw new BroadcastRefusedException(message: 'Subtitles are released after the broadcast has ended', status: 409);
		}

		$node = $this->folders->meetingFile(
			meeting: (['id' => $meetingId] + $meeting),
			subfolder: self::SUBFOLDER,
			fileName: $this->fileName(language: $language)
		);
		if ($node === null) {
			throw new BroadcastRefusedException(message: 'Make the subtitles first', status: 409);
		}

		$user  = $this->userSession->getUser();
		$share = $this->shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setPermissions(Constants::PERMISSION_READ)
			->setSharedBy((string)$user?->getUID());
		$share = $this->shares->createShare($share);
		$url   = $this->urls->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $share->getToken()]);

		$tracks = array_values(
			array_filter(
				(array)($broadcast['captionTracks'] ?? []),
				static fn (mixed $track): bool => is_array($track) === true && ($track['language'] ?? null) !== $language
			)
		);
		$tracks[] = [
			'language'   => $language,
			'filePath'   => $node->getPath(),
			'shareUrl'   => $url,
			'reviewedBy' => (string)($user?->getDisplayName() ?? ''),
			'releasedAt' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
		];
		$broadcast['captionTracks'] = $tracks;
		$stored = $this->write(broadcast: $broadcast);
		$stored['attachedToRecording'] = $this->attach(language: $language, url: $url);

		return $stored;
	}//end release()

	/**
	 * Ask the streaming service to attach a released track to the recording.
	 *
	 * The track is public through its link either way; attaching it is the
	 * service's extra, not a condition of the release.
	 *
	 * @param string $language The language code
	 * @param string $url      The track's public link
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @return bool Whether the service took it
	 */
	private function attach(string $language, string $url): bool {
		try {
			$answer = $this->streaming->call(operation: 'attach-captions', body: ['language' => $language, 'url' => $url]);
		} catch (BroadcastRefusedException) {
			return false;
		}

		return (($answer['accepted'] ?? false) === true);
	}//end attach()

	/**
	 * WebVTT over the segments that start inside a public window, on the recording's clock.
	 *
	 * @param array<int|string, mixed> $segments The transcript's segments (`startTime`, `endTime`, `text`)
	 * @param array<int|string, mixed> $windows  The broadcast's public windows (`start`, `end`, `recordingStart`)
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @return string
	 */
	public function webVtt(array $segments, array $windows): string {
		$cues = [];
		foreach ($segments as $segment) {
			if (is_array($segment) === false || trim((string)($segment['text'] ?? '')) === '') {
				continue;
			}

			$start  = (float)($segment['startTime'] ?? 0);
			$window = $this->windowAt(second: $start, windows: $windows);
			if ($window === null) {
				continue;
			}

			$shift = ((float)($window['recordingStart'] ?? 0) - (float)$window['start']);
			$end   = max($start, (float)($segment['endTime'] ?? $start));
			if (isset($window['end']) === true) {
				$end = min($end, (float)$window['end']);
			}

			$cues[] = $this->clock(seconds: ($start + $shift)) . ' --> ' . $this->clock(seconds: ($end + $shift)) . "\n" . trim((string)$segment['text']);
		}

		return "WEBVTT\n\n" . implode("\n\n", $cues) . "\n";
	}//end webVtt()

	/**
	 * The public window a second falls in, or null.
	 *
	 * @param float                    $second  Seconds from the meeting's opening
	 * @param array<int|string, mixed> $windows The windows
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @return array<string, mixed>|null
	 */
	private function windowAt(float $second, array $windows): ?array {
		foreach ($windows as $window) {
			if (is_array($window) === false || isset($window['start']) === false) {
				continue;
			}

			$open = ($second >= (float)$window['start']);
			if ($open === true && (isset($window['end']) === false || $second < (float)$window['end'])) {
				return $window;
			}
		}

		return null;
	}//end windowAt()

	/**
	 * A WebVTT timestamp.
	 *
	 * @param float $seconds Seconds
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @return string
	 */
	private function clock(float $seconds): string {
		$millis = (int)round(max(0.0, $seconds) * 1000);
		return sprintf('%02d:%02d:%02d.%03d', intdiv($millis, 3600000), (intdiv($millis, 60000) % 60), (intdiv($millis, 1000) % 60), ($millis % 1000));
	}//end clock()

	/**
	 * The caption file's name.
	 *
	 * @param string $language The language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @return string
	 */
	private function fileName(string $language): string {
		return 'captions-' . $language . '.vtt';
	}//end fileName()

	/**
	 * Refuse a language that is not a plain code, so it never shapes a path.
	 *
	 * @param string $language The language code
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @throws BroadcastRefusedException When it is not a code like `nl` or `nl-be`
	 *
	 * @return void
	 */
	private function requireLanguage(string $language): void {
		if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $language) !== 1) {
			throw new BroadcastRefusedException(message: 'The language must be a language code such as nl', status: 422);
		}
	}//end requireLanguage()

	/**
	 * The meeting's finished transcript, or a 409.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 *
	 * @throws BroadcastRefusedException When there is none
	 *
	 * @return array<string, mixed>
	 */
	private function finishedTranscript(string $meetingId): array {
		$rows = [];
		if ($meetingId !== '') {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'transcript', 'meeting' => $meetingId, 'status' => 'done'], 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		}

		if ($rows === []) {
			throw new BroadcastRefusedException(message: 'There is no finished transcript of this meeting', status: 409);
		}

		return $rows[0]->jsonSerialize();
	}//end finishedTranscript()

	/**
	 * A broadcast, or a 404.
	 *
	 * @param string $broadcastId The broadcast
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @throws BroadcastRefusedException When it does not exist
	 *
	 * @return array<string, mixed>
	 */
	private function load(string $broadcastId): array {
		$broadcast = $this->read(schema: 'meeting-broadcast', id: $broadcastId);
		if ($broadcast === null) {
			throw new BroadcastRefusedException(message: 'Broadcast not found', status: 404);
		}

		return ['id' => $broadcastId] + $broadcast;
	}//end load()

	/**
	 * Read one object in system context.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		unset($data['id'], $data['@self']);
		return $data;
	}//end read()

	/**
	 * Write the broadcast in system context.
	 *
	 * @param array<string, mixed> $broadcast The broadcast, with its id
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 *
	 * @return array<string, mixed> The stored broadcast, with its id
	 */
	private function write(array $broadcast): array {
		$uuid = (string)$broadcast['id'];
		unset($broadcast['id'], $broadcast['@self']);
		$saved = $this->objectService->saveObject(
			object: $broadcast,
			register: 'decidiq',
			schema: 'meeting-broadcast',
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
