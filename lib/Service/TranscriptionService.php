<?php

/**
 * Decidesk Transcription Service
 *
 * Thin orchestration over the Nextcloud SpeechToText provider abstraction:
 * source attachment with consent, provider discovery, status lifecycle,
 * neutral-label segment parsing, transcript file persistence, and a pure
 * re-runnable agenda-alignment derivation. No app-local STT pipeline.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use DomainException;
use InvalidArgumentException;
use OCA\Decidesk\Exception\MissingObjectException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless orchestration service for meeting transcription.
 *
 * Transcription itself is delegated to the Nextcloud SpeechToText provider
 * abstraction (OCP\SpeechToText\ISpeechToTextManager). Provider ABSENCE is a
 * first-class "unavailable" state, never an error: attach/consent still work
 * and {@see self::isProviderAvailable()} reports false so the UI can hide the
 * transcribe action with an explanation.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionService {

	/**
	 * Object + file access for the transcription pipeline.
	 *
	 * @var TranscriptRepository
	 */
	private readonly TranscriptRepository $repository;

	/**
	 * Pure segment parsing / timeline alignment derivations.
	 *
	 * @var TranscriptAlignmentService
	 */
	private readonly TranscriptAlignmentService $aligner;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OR + NC providers).
	 * @param LoggerInterface $logger The logger.
	 * @param TranscriptionSourceResolver $sourceResolver Candidate-source resolver.
	 * @param MeetingFolderService $folderService Meeting folder + file writer.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly TranscriptionSourceResolver $sourceResolver,
		private readonly MeetingFolderService $folderService,
	) {
		$this->repository = new TranscriptRepository(container: $container);
		$this->aligner = new TranscriptAlignmentService(repository: $this->repository);

	}//end __construct()

	/**
	 * Whether a SpeechToText provider is available on this instance.
	 *
	 * Provider absence is a first-class unavailable state — callers use this to
	 * keep the attach/consent flow working while hiding/disabling transcribe.
	 *
	 * @return bool True when at least one SpeechToText provider is registered.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isProviderAvailable(): bool {
		try {
			$manager = $this->container->get(\OCP\SpeechToText\ISpeechToTextManager::class);
		} catch (\Throwable $e) {
			// The SpeechToText manager itself is unavailable (very old NC / DI
			// gap): treat as no provider rather than failing the panel.
			$this->logger->debug(
				'Decidesk TranscriptionService: SpeechToText manager unavailable',
				['error' => $e->getMessage()]
			);
			return false;
		}

		try {
			return $manager->hasProviders();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidesk TranscriptionService: hasProviders() check failed',
				['error' => $e->getMessage()]
			);
			return false;
		}

	}//end isProviderAvailable()

	/**
	 * List the candidate transcription sources for a meeting.
	 *
	 * @param string $meetingId Meeting UUID.
	 *
	 * @return array<int,array<string,string>> Candidate sources.
	 *
	 * @throws MissingObjectException When the meeting cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function listSources(string $meetingId): array {
		$meeting = $this->repository->fetchMeeting(meetingId: $meetingId);
		return $this->sourceResolver->listSources(meeting: $meeting);
	}//end listSources()

	/**
	 * Attach a transcription source to a meeting and record consent.
	 *
	 * Creates (or updates) a Transcript object in status `pending` with the
	 * source file reference and the consent record. Consent is a server-side
	 * precondition for transcription (enforced in {@see self::submit()}); here
	 * it is captured at attach time.
	 *
	 * @param string $meetingId Meeting UUID.
	 * @param string $sourceType Source type (`talk-recording`|`uploaded-file`).
	 * @param string $sourcePath Path of the source file in the meeting folder.
	 * @param string $confirmedBy Nextcloud UID confirming consent (server-attributed).
	 * @param string $language Optional BCP-47 language hint.
	 *
	 * @return array<string,mixed> The created Transcript object.
	 *
	 * @throws MissingObjectException When the meeting cannot be found.
	 * @throws InvalidArgumentException When source type/path is invalid.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function attach(
		string $meetingId,
		string $sourceType,
		string $sourcePath,
		string $confirmedBy,
		string $language = '',
	): array {
		if (in_array($sourceType, ['talk-recording', 'uploaded-file'], true) === false) {
			throw new InvalidArgumentException('Invalid source type.', 422);
		}

		if (trim($sourcePath) === '') {
			throw new InvalidArgumentException('A source file path is required.', 422);
		}

		if (trim($confirmedBy) === '') {
			throw new InvalidArgumentException('A consent confirmation is required.', 422);
		}

		$meeting = $this->repository->fetchMeeting(meetingId: $meetingId);

		$transcript = [
			'title' => (string)($meeting['title'] ?? ($meeting['name'] ?? 'Transcript')),
			'sourceType' => $sourceType,
			'sourceFilePath' => $sourcePath,
			'language' => $language,
			'status' => 'pending',
			'retentionState' => 'active',
			'consent' => [
				'confirmedBy' => $confirmedBy,
				'confirmedAt' => date(DATE_ATOM),
			],
			'relations' => ['meeting' => $meetingId],
		];

		return $this->repository->saveTranscript(transcript: $transcript);
	}//end attach()

	/**
	 * Submit a Transcript for transcription (consent precondition enforced).
	 *
	 * Refuses without a recorded consent confirmation — a server-side check,
	 * not a UI-only gate. When no SpeechToText provider is available the
	 * Transcript is left untouched and a domain exception signals unavailable.
	 *
	 * The actual transcription runs asynchronously in {@see TranscriptionJob};
	 * this method validates preconditions and is the seam the controller calls.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The Transcript object (status unchanged at submit).
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 * @throws DomainException When consent is missing (code 422) or no provider (code 503).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function submit(string $transcriptId): array {
		$transcript = $this->repository->fetchTranscript(transcriptId: $transcriptId);

		$consent = ($transcript['consent'] ?? null);
		if (is_array($consent) === false || trim((string)($consent['confirmedBy'] ?? '')) === '') {
			throw new DomainException('Consent is required before a transcription can be requested.', 422);
		}

		if ($this->isProviderAvailable() === false) {
			throw new DomainException('No SpeechToText provider is available on this instance.', 503);
		}

		// Preconditions satisfied; the queued TranscriptionJob will move the
		// status pending → processing → done|failed.
		return $transcript;
	}//end submit()

	/**
	 * Execute the transcription for a Transcript (called from the background job).
	 *
	 * Moves status pending → processing, runs the SpeechToText provider over the
	 * source file, parses neutral-label segments, writes the transcript text
	 * file into the meeting folder, runs agenda alignment, and moves status to
	 * `done`. On any provider error the status is set to `failed` with the
	 * reason stored (a first-class state, re-requestable).
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The updated Transcript object.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function process(string $transcriptId): array {
		$transcript = $this->repository->fetchTranscript(transcriptId: $transcriptId);

		$consent = ($transcript['consent'] ?? null);
		if (is_array($consent) === false || trim((string)($consent['confirmedBy'] ?? '')) === '') {
			return $this->markFailed(transcript: $transcript, reason: 'Consent missing at processing time.');
		}

		$transcript['status'] = 'processing';
		$transcript = $this->repository->saveTranscript(transcript: $transcript);

		try {
			$manager = $this->container->get(\OCP\SpeechToText\ISpeechToTextManager::class);
			$file = $this->repository->resolveSourceNode(path: (string)($transcript['sourceFilePath'] ?? ''));
			$rawText = $manager->transcribeFile($file, null, 'decidesk');
			$segments = $this->aligner->parseSegments(raw: $rawText);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidesk TranscriptionService: transcription failed',
				['transcriptId' => $transcriptId, 'error' => $e->getMessage()]
			);
			return $this->markFailed(transcript: $transcript, reason: $e->getMessage());
		}

		// Write the plain-text transcript file into the meeting folder.
		$transcriptPath = $this->writeTranscriptFile(transcript: $transcript, segments: $segments);

		$providerId = '';
		try {
			$providers = $manager->getProviders();
			if (is_array($providers) === true && $providers !== []) {
				$first = $providers[array_key_first($providers)];
				if (is_object($first) === true && method_exists($first, 'getName') === true) {
					$providerId = (string)$first->getName();
				}
			}
		} catch (\Throwable) {
			$providerId = '';
		}

		$transcript['status'] = 'done';
		$transcript['failureReason'] = '';
		$transcript['providerId'] = $providerId;
		$transcript['segments'] = $segments;
		$transcript['transcriptFilePath'] = ($transcriptPath ?? '');

		$transcript = $this->repository->saveTranscript(transcript: $transcript);

		// Agenda alignment is a derivation over stored data; run it now and on demand.
		return $this->align(transcriptId: (string)$this->repository->transcriptId(transcript: $transcript));
	}//end process()

	/**
	 * Parse a provider transcription result into neutral-label segments.
	 *
	 * Accepts either a structured JSON array of `{startTime,endTime,speaker,text}`
	 * objects (richer providers) or a plain-text blob (the
	 * ISpeechToTextManager::transcribeFile contract returns a string). For plain
	 * text the whole result becomes a single segment. Speaker labels are always
	 * neutral provider labels — never mapped to participant identities.
	 *
	 * @param string $raw Raw provider output (JSON or plain text).
	 *
	 * @return array<int,array<string,mixed>> Parsed segments.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function parseSegments(string $raw): array {
		return $this->aligner->parseSegments(raw: $raw);
	}//end parseSegments()

	/**
	 * Re-run agenda alignment for a Transcript (pure re-runnable derivation).
	 *
	 * Joins each segment's time window against the meeting-conduct timeline
	 * (agenda item start + actualDuration). Segments inside an item's window get
	 * its UUID; out-of-window segments are left unassigned (never guessed). When
	 * the meeting has no conduct timeline the transcript stays flat (all
	 * segments unassigned). Re-running never re-transcribes.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The re-aligned Transcript object.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function align(string $transcriptId): array {
		$transcript = $this->repository->fetchTranscript(transcriptId: $transcriptId);
		$meetingId = $this->repository->resolveMeetingId(transcript: $transcript);

		$timeline = [];
		if ($meetingId !== null) {
			$timeline = $this->aligner->buildTimeline(meetingId: $meetingId);
		}

		$segments = ($transcript['segments'] ?? []);
		if (is_array($segments) === false) {
			$segments = [];
		}

		$transcript['segments'] = $this->aligner->alignSegments(segments: $segments, timeline: $timeline);
		$transcript['alignedAt'] = date(DATE_ATOM);

		return $this->repository->saveTranscript(transcript: $transcript);
	}//end align()

	/**
	 * Pure alignment of segments against a timeline window list.
	 *
	 * Exposed (public) so it can be unit-tested without OR; given segments and a
	 * timeline of `{agendaItem, start, end}` windows it returns the segments with
	 * their `agendaItem` set when inside a window, unset otherwise.
	 *
	 * @param array<int,array<string,mixed>> $segments Transcript segments.
	 * @param array<int,array<string,mixed>> $timeline Ordered agenda windows (seconds).
	 *
	 * @return array<int,array<string,mixed>> Aligned segments.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function alignSegments(array $segments, array $timeline): array {
		return $this->aligner->alignSegments(segments: $segments, timeline: $timeline);
	}//end alignSegments()

	/**
	 * Set the transcript/recording retention policy for a governance body.
	 *
	 * @param string $bodyId The governance body UUID.
	 * @param string $policy One of keep|delete-recording|delete-both.
	 * @param int $days Retention window in days.
	 *
	 * @return void
	 *
	 * @throws MissingObjectException When the governance body cannot be found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function setRetentionPolicy(string $bodyId, string $policy, int $days): void {
		$this->repository->saveRetentionPolicy(bodyId: $bodyId, policy: $policy, days: $days);

	}//end setRetentionPolicy()

	/**
	 * Mark a Transcript failed with a stored reason and persist it.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 * @param string $reason Failure reason.
	 *
	 * @return array<string,mixed> The persisted failed Transcript.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function markFailed(array $transcript, string $reason): array {
		$transcript['status'] = 'failed';
		$transcript['failureReason'] = $reason;
		return $this->repository->saveTranscript(transcript: $transcript);
	}//end markFailed()

	/**
	 * Write the plain-text transcript file into the meeting's Minutes subfolder.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 * @param array<int,array<string,mixed>> $segments Parsed segments.
	 *
	 * @return string|null The written file path, or null on failure.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function writeTranscriptFile(array $transcript, array $segments): ?string {
		$meetingId = $this->repository->resolveMeetingId(transcript: $transcript);
		if ($meetingId === null) {
			return null;
		}

		try {
			$meeting = $this->repository->fetchMeeting(meetingId: $meetingId);
		} catch (\Throwable) {
			return null;
		}

		$lines = [];
		foreach ($segments as $segment) {
			$label = (string)($segment['speakerLabel'] ?? '');
			$text = (string)($segment['text'] ?? '');
			$prefix = '';
			if ($label !== '') {
				$prefix = $label . ': ';
			}

			$lines[] = $prefix . $text;
		}

		$content = implode("\n", $lines);
		$fileName = 'transcript-' . ((string)$this->repository->transcriptId(transcript: $transcript)) . '.txt';

		return $this->folderService->writeMeetingFile(
			meeting: $meeting,
			subfolder: 'Minutes',
			fileName: $fileName,
			content: $content
		);

	}//end writeTranscriptFile()
}//end class
