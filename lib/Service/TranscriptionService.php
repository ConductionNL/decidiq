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

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
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
class TranscriptionService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface          $container      DI container (lazy OR + NC providers).
     * @param LoggerInterface             $logger         The logger.
     * @param TranscriptionSourceResolver $sourceResolver Candidate-source resolver.
     * @param MeetingFolderService        $folderService  Meeting folder + file writer.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly TranscriptionSourceResolver $sourceResolver,
        private readonly MeetingFolderService $folderService,
    ) {
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
    public function isProviderAvailable(): bool
    {
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
    public function listSources(string $meetingId): array
    {
        $meeting = $this->fetchMeeting(meetingId: $meetingId);
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
     * @param string $meetingId   Meeting UUID.
     * @param string $sourceType  Source type (`talk-recording`|`uploaded-file`).
     * @param string $sourcePath  Path of the source file in the meeting folder.
     * @param string $confirmedBy Nextcloud UID confirming consent (server-attributed).
     * @param string $language    Optional BCP-47 language hint.
     *
     * @return array<string,mixed> The created Transcript object.
     *
     * @throws MissingObjectException    When the meeting cannot be found.
     * @throws InvalidArgumentException When source type/path is invalid.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function attach(
        string $meetingId,
        string $sourceType,
        string $sourcePath,
        string $confirmedBy,
        string $language=''
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

        $meeting = $this->fetchMeeting(meetingId: $meetingId);

        $transcript = [
            'title'          => (string) ($meeting['title'] ?? ($meeting['name'] ?? 'Transcript')),
            'sourceType'     => $sourceType,
            'sourceFilePath' => $sourcePath,
            'language'       => $language,
            'status'         => 'pending',
            'retentionState' => 'active',
            'consent'        => [
                'confirmedBy' => $confirmedBy,
                'confirmedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ],
            'relations'      => ['meeting' => $meetingId],
        ];

        return $this->saveTranscript(transcript: $transcript);

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
     * @throws MissingObjectException    When the Transcript cannot be found.
     * @throws DomainException          When consent is missing (code 422) or no provider (code 503).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function submit(string $transcriptId): array
    {
        $transcript = $this->fetchTranscript(transcriptId: $transcriptId);

        $consent = ($transcript['consent'] ?? null);
        if (is_array($consent) === false || trim((string) ($consent['confirmedBy'] ?? '')) === '') {
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
    public function process(string $transcriptId): array
    {
        $transcript = $this->fetchTranscript(transcriptId: $transcriptId);

        $consent = ($transcript['consent'] ?? null);
        if (is_array($consent) === false || trim((string) ($consent['confirmedBy'] ?? '')) === '') {
            return $this->markFailed(transcript: $transcript, reason: 'Consent missing at processing time.');
        }

        $transcript['status'] = 'processing';
        $transcript           = $this->saveTranscript(transcript: $transcript);

        try {
            $manager  = $this->container->get(\OCP\SpeechToText\ISpeechToTextManager::class);
            $file     = $this->resolveSourceNode(path: (string) ($transcript['sourceFilePath'] ?? ''));
            $rawText  = $manager->transcribeFile($file, null, 'decidesk');
            $segments = $this->parseSegments(raw: $rawText);
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
                    $providerId = (string) $first->getName();
                }
            }
        } catch (\Throwable) {
            $providerId = '';
        }

        $transcript['status']        = 'done';
        $transcript['failureReason'] = '';
        $transcript['providerId']    = $providerId;
        $transcript['segments']      = $segments;
        $transcript['transcriptFilePath'] = ($transcriptPath ?? '');

        $transcript = $this->saveTranscript(transcript: $transcript);

        // Agenda alignment is a derivation over stored data; run it now and on demand.
        return $this->align(transcriptId: (string) $this->transcriptId(transcript: $transcript));

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
    public function parseSegments(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) === true && $decoded !== [] && isset($decoded[0]) === true) {
            $segments = [];
            $counter  = 0;
            $labels   = [];
            foreach ($decoded as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }

                $rawSpeaker = (string) ($entry['speaker'] ?? ($entry['speakerLabel'] ?? ''));
                if ($rawSpeaker === '') {
                    $rawSpeaker = 'default';
                }

                // Map any provider-side speaker key to a neutral sequential label.
                if (isset($labels[$rawSpeaker]) === false) {
                    $counter++;
                    $labels[$rawSpeaker] = 'Speaker '.$counter;
                }

                $segments[] = [
                    'startTime'    => (float) ($entry['startTime'] ?? ($entry['start'] ?? 0)),
                    'endTime'      => (float) ($entry['endTime'] ?? ($entry['end'] ?? 0)),
                    'speakerLabel' => $labels[$rawSpeaker],
                    'text'         => (string) ($entry['text'] ?? ''),
                ];
            }//end foreach

            return $segments;
        }//end if

        // Plain-text fallback: one neutral-label segment covering the result.
        return [
            [
                'startTime'    => 0.0,
                'endTime'      => 0.0,
                'speakerLabel' => 'Speaker 1',
                'text'         => $trimmed,
            ],
        ];

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
    public function align(string $transcriptId): array
    {
        $transcript = $this->fetchTranscript(transcriptId: $transcriptId);
        $meetingId  = $this->resolveMeetingId(transcript: $transcript);

        $timeline = [];
        if ($meetingId !== null) {
            $timeline = $this->buildTimeline(meetingId: $meetingId);
        }

        $segments = ($transcript['segments'] ?? []);
        if (is_array($segments) === false) {
            $segments = [];
        }

        $transcript['segments']  = $this->alignSegments(segments: $segments, timeline: $timeline);
        $transcript['alignedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return $this->saveTranscript(transcript: $transcript);

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
    public function alignSegments(array $segments, array $timeline): array
    {
        $aligned = [];
        foreach ($segments as $segment) {
            // Drop any prior assignment so re-runs reflect the current timeline.
            unset($segment['agendaItem']);

            $start = (float) ($segment['startTime'] ?? 0);
            foreach ($timeline as $window) {
                $wStart = (float) ($window['start'] ?? 0);
                $wEnd   = (float) ($window['end'] ?? 0);
                if ($start >= $wStart && ($wEnd === 0.0 || $start < $wEnd)) {
                    $segment['agendaItem'] = (string) ($window['agendaItem'] ?? '');
                    break;
                }
            }

            $aligned[] = $segment;
        }//end foreach

        return $aligned;

    }//end alignSegments()

    /**
     * Build the conduct timeline windows from a meeting's agenda items.
     *
     * Each item contributes a window `[start, start+actualDuration)` in seconds
     * relative to the meeting start. Items without a recorded start are skipped;
     * an empty result means there is no usable timeline (flat-transcript case).
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return array<int,array<string,mixed>> Ordered timeline windows.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function buildTimeline(string $meetingId): array
    {
        $items = $this->fetchAgendaItems(meetingId: $meetingId);

        $meetingStart = null;
        $windows      = [];
        foreach ($items as $item) {
            $rawStart = ($item['actualStart'] ?? ($item['startTime'] ?? ($item['startedAt'] ?? null)));
            if ($rawStart === null || $rawStart === '') {
                continue;
            }

            try {
                $itemStart = (new DateTimeImmutable((string) $rawStart))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }

            if ($meetingStart === null || $itemStart < $meetingStart) {
                $meetingStart = $itemStart;
            }

            $duration  = (float) ($item['actualDuration'] ?? 0);
            $windows[] = [
                'agendaItem' => (string) ($item['id'] ?? ($item['uuid'] ?? '')),
                'absStart'   => (float) $itemStart,
                'duration'   => $duration,
            ];
        }//end foreach

        if ($meetingStart === null) {
            return [];
        }

        // Normalise to seconds-from-meeting-start to match segment offsets.
        $timeline = [];
        foreach ($windows as $window) {
            $relStart = ($window['absStart'] - (float) $meetingStart);
            $relEnd   = 0.0;
            if ($window['duration'] > 0) {
                $relEnd = ($relStart + $window['duration']);
            }

            $timeline[] = [
                'agendaItem' => $window['agendaItem'],
                'start'      => $relStart,
                'end'        => $relEnd,
            ];
        }

        return $timeline;

    }//end buildTimeline()

    /**
     * Mark a Transcript failed with a stored reason and persist it.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     * @param string              $reason     Failure reason.
     *
     * @return array<string,mixed> The persisted failed Transcript.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function markFailed(array $transcript, string $reason): array
    {
        $transcript['status']        = 'failed';
        $transcript['failureReason'] = $reason;
        return $this->saveTranscript(transcript: $transcript);

    }//end markFailed()

    /**
     * Write the plain-text transcript file into the meeting's Minutes subfolder.
     *
     * @param array<string,mixed>            $transcript The Transcript object.
     * @param array<int,array<string,mixed>> $segments   Parsed segments.
     *
     * @return string|null The written file path, or null on failure.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function writeTranscriptFile(array $transcript, array $segments): ?string
    {
        $meetingId = $this->resolveMeetingId(transcript: $transcript);
        if ($meetingId === null) {
            return null;
        }

        try {
            $meeting = $this->fetchMeeting(meetingId: $meetingId);
        } catch (\Throwable) {
            return null;
        }

        $lines = [];
        foreach ($segments as $segment) {
            $label  = (string) ($segment['speakerLabel'] ?? '');
            $text   = (string) ($segment['text'] ?? '');
            $prefix = '';
            if ($label !== '') {
                $prefix = $label.': ';
            }

            $lines[] = $prefix.$text;
        }

        $content  = implode("\n", $lines);
        $fileName = 'transcript-'.((string) $this->transcriptId(transcript: $transcript)).'.txt';

        return $this->folderService->writeMeetingFile(
            meeting: $meeting,
            subfolder: 'Minutes',
            fileName: $fileName,
            content: $content
        );

    }//end writeTranscriptFile()

    /**
     * Resolve the source File node from a Files path.
     *
     * @param string $path Path of the source file in the meeting folder.
     *
     * @return \OCP\Files\File The resolved file node.
     *
     * @throws RuntimeException When the file cannot be resolved.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function resolveSourceNode(string $path): \OCP\Files\File
    {
        if ($path === '') {
            throw new RuntimeException('Transcript has no source file path.');
        }

        $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
        $dir         = dirname($path);
        $base        = basename($path);
        $folderNode  = $fileService->createFolder($dir);
        $node        = $folderNode->get($base);

        if (($node instanceof \OCP\Files\File) === false) {
            throw new RuntimeException('Source path is not a file: '.$path);
        }

        return $node;

    }//end resolveSourceNode()

    /**
     * Fetch a meeting object by id (throws when absent).
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return array<string,mixed> The meeting object.
     *
     * @throws MissingObjectException When not found.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function fetchMeeting(string $meetingId): array
    {
        $objectService = $this->getObjectService();
        $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        if ($entity === null) {
            throw new MissingObjectException(message: sprintf('Meeting "%s" not found.', $meetingId));
        }

        return (array) $entity->jsonSerialize();

    }//end fetchMeeting()

    /**
     * Fetch a transcript object by id (throws when absent).
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return array<string,mixed> The transcript object.
     *
     * @throws MissingObjectException When not found.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function fetchTranscript(string $transcriptId): array
    {
        $objectService = $this->getObjectService();
        $entity        = $objectService->find(id: $transcriptId, register: 'decidesk', schema: 'transcript');
        if ($entity === null) {
            throw new MissingObjectException(message: sprintf('Transcript "%s" not found.', $transcriptId));
        }

        return (array) $entity->jsonSerialize();

    }//end fetchTranscript()

    /**
     * Fetch the agenda items linked to a meeting.
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return array<int,array<string,mixed>> Agenda item objects.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function fetchAgendaItems(string $meetingId): array
    {
        $objectService = $this->getObjectService();
        $entities      = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'agenda-item',
                'filters'  => [
                    'register'           => 'decidesk',
                    'schema'             => 'agenda-item',
                    '_relations.meeting' => $meetingId,
                ],
            ]
        );

        $items = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === true) {
                $items[] = $entity;
            } else if (method_exists($entity, 'getObject') === true) {
                $items[] = $entity->getObject();
            } else if (method_exists($entity, 'jsonSerialize') === true) {
                $items[] = (array) $entity->jsonSerialize();
            }
        }

        return $items;

    }//end fetchAgendaItems()

    /**
     * Persist a Transcript object via the OpenRegister object API.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return array<string,mixed> The persisted object.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function saveTranscript(array $transcript): array
    {
        $objectService = $this->getObjectService();
        $uuid          = $this->transcriptId(transcript: $transcript);

        $saved = $objectService->saveObject(
            object: $transcript,
            register: 'decidesk',
            schema: 'transcript',
            uuid: $uuid
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return (array) $saved->jsonSerialize();
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return $transcript;

    }//end saveTranscript()

    /**
     * Extract the transcript object UUID (id or @self.id), or null.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The UUID.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function transcriptId(array $transcript): ?string
    {
        $id = ($transcript['id'] ?? ($transcript['@self']['id'] ?? null));
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;

    }//end transcriptId()

    /**
     * Resolve the linked meeting UUID from a Transcript object.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The meeting UUID, or null when not linked.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function resolveMeetingId(array $transcript): ?string
    {
        $relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
        if (is_array($relation) === true) {
            $relation = ($relation['id'] ?? ($relation[0] ?? null));
        }

        if ($relation === null || $relation === '') {
            return null;
        }

        return (string) $relation;

    }//end resolveMeetingId()

    /**
     * Lazy-load the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException When OpenRegister is not installed.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is not available.', 0, $e);
        }

    }//end getObjectService()
}//end class
