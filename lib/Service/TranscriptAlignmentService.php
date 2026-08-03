<?php

/**
 * Decidesk Transcript Alignment Service
 *
 * The pure derivations of the meeting-transcription pipeline: parsing a
 * provider result into neutral-label segments, building the meeting-conduct
 * timeline from agenda items, and joining segments against that timeline.
 * None of it mutates a Transcript — TranscriptionService owns persistence.
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

/**
 * Re-runnable derivations over transcript segments.
 *
 * Speaker labels are always neutral provider labels — never mapped to
 * participant identities — and out-of-window segments are left unassigned
 * rather than guessed.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptAlignmentService
{
    /**
     * Constructor.
     *
     * @param TranscriptRepository $repository Object access for agenda items.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly TranscriptRepository $repository,
    ) {
    }//end __construct()

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
            return $this->parseStructuredSegments(entries: $decoded);
        }

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
     * Map a decoded provider segment list onto neutral sequential labels.
     *
     * @param array<int,mixed> $entries Decoded provider entries.
     *
     * @return array<int,array<string,mixed>> Parsed segments.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function parseStructuredSegments(array $entries): array
    {
        $segments = [];
        $counter  = 0;
        $labels   = [];
        foreach ($entries as $entry) {
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

    }//end parseStructuredSegments()

    /**
     * Pure alignment of segments against a timeline window list.
     *
     * Given segments and a timeline of `{agendaItem, start, end}` windows it
     * returns the segments with their `agendaItem` set when inside a window,
     * unset otherwise.
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
    public function buildTimeline(string $meetingId): array
    {
        $windows = $this->collectAbsoluteWindows(
            items: $this->repository->fetchAgendaItems(meetingId: $meetingId)
        );
        if ($windows === []) {
            return [];
        }

        // Normalise to seconds-from-meeting-start to match segment offsets.
        $meetingStart = (float) min(array_column($windows, 'absStart'));

        $timeline = [];
        foreach ($windows as $window) {
            $relStart = ($window['absStart'] - $meetingStart);
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
     * Collect each agenda item's absolute start (epoch seconds) and duration.
     *
     * Items without a parseable recorded start are skipped.
     *
     * @param array<int,array<string,mixed>> $items Agenda item objects.
     *
     * @return array<int,array<string,mixed>> Absolute windows.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function collectAbsoluteWindows(array $items): array
    {
        $windows = [];
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

            $windows[] = [
                'agendaItem' => (string) ($item['id'] ?? ($item['uuid'] ?? '')),
                'absStart'   => (float) $itemStart,
                'duration'   => (float) ($item['actualDuration'] ?? 0),
            ];
        }//end foreach

        return $windows;

    }//end collectAbsoluteWindows()
}//end class
