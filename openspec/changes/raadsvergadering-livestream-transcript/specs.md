# Spec: Raadsvergadering Livestream met Automatische Transcriptie

---

## Capability: livestream-embedding

### REQ-LIVE-001 — Livestream-embedding via HLS of MPEG-DASH

The system SHALL embed and play HLS or MPEG-DASH streams from a Livestream object on the Vergadering detail page.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | livestream-embedding |

The player MUST support:
- Play, pause, volume, and playback speed controls
- Full-screen mode
- Closed captions toggle
- Time display (current / total duration)
- Keyboard controls (space = play/pause, arrow keys = skip ±10s)

#### Scenario: Live stream renders in player
- **GIVEN** a Vergadering with a linked Livestream where status is `live` and streamUrl points to a valid HLS.m3u8
- **WHEN** a user opens the Vergadering detail page
- **THEN** the player renders immediately with a play button
- **AND** the video title and duration are displayed above the player
- **AND** captions are available (toggle visible even if not yet generated)

#### Scenario: VOD with recording URL displays
- **GIVEN** a Livestream with status `ended` and recordingUrl populated
- **WHEN** the user opens the Vergadering detail page
- **THEN** the player loads the VOD file instead of the streaming URL
- **AND** a chapter-navigation menu appears showing all Agendapunten with their start times
- **AND** clicking a chapter jumps the player to that timestamp

#### Scenario: Embargoed livestream shows message
- **GIVEN** a Livestream with accessControl `embargoed` and embargoedUntil in the future
- **WHEN** a non-authorized user opens the page
- **THEN** the player area displays a message: "This recording is embargoed until {date/time}. Public access coming soon."
- **AND** the player does NOT play

---

### REQ-LIVE-002 — Automatic transcription within 60 minutes

The system SHALL automatically generate a Transcript with Segments when a Livestream ends, if Vergadering.transcriptionPolicy is `auto`.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | livestream-embedding, automatic-transcription |

The Transcript MUST be generated using either Whisper-NL (local) or NOTUBIZ ASR (fallback), with the following guarantees:
- Turnaround: 60 minutes maximum from livestream.endedAt
- Completeness: ≥ 95% of livestream duration covered by TranscriptSegments
- Confidence: if engine is Whisper-NL and average confidence < 0.75, flag for manual review

#### Scenario: Transcription job starts when livestream ends
- **GIVEN** a Vergadering with transcriptionPolicy `auto` and a Livestream transitioning to status `ended`
- **WHEN** the livestream.endedAt timestamp is recorded
- **THEN** a background job is queued to start transcription immediately
- **AND** the Transcript record is created with status `pending`

#### Scenario: Transcript reaches draft status within 60 minutes
- **GIVEN** a Transcript with status `pending` and a running transcription job
- **WHEN** the ASR engine completes processing
- **THEN** the Transcript status becomes `draft`
- **AND** all TranscriptSegments are stored with startTime, endTime, text, and confidence
- **AND** ≥ 95% of [livestream.startedAt, livestream.endedAt] is covered by segment ranges

#### Scenario: Transcription failure triggers retry and notification
- **GIVEN** a transcription job fails (e.g. Whisper-NL GPU timeout, NOTUBIZ API 5xx)
- **WHEN** the failure is detected
- **THEN** the system retries immediately (attempt 1), then after 5 minutes (attempt 2), then after 15 minutes (attempt 3)
- **AND** if attempt 3 fails, the Transcript status becomes `pending` with error log
- **AND** a notification is sent to the Griffier group: "Transcription failed for meeting [title]. Please check manually."

#### Scenario: Low confidence triggers review flag
- **GIVEN** a Transcript with engine `whisper-nl` and average confidence 0.68 (below 0.75 threshold)
- **WHEN** transcription completes
- **THEN** the Transcript is marked with a flag `needs_review: true` in audit trail
- **AND** the griffier sees a yellow badge on the transcript: "Quality check recommended"
- **AND** the Transcript can still be published, but with a disclaimer note

---

## Capability: speaker-recognition

### REQ-LIVE-003 — Speaker recognition via microphone linkage

TranscriptSegments MUST be linked to Spreker records based on microphone ID from the zaalsysteem.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | speaker-recognition |

Microphone IDs come from openconnector's NOTUBIZ/iBabs adapters (events: `microphone.activated`, `microphone.deactivated`). Linking rules:
- If segment.microfoonId matches a Spreker.microfoonId in the same Vergadering, segment.speaker_id is set
- If no match found, segment.speakerLabel remains generic (e.g. "SPEAKER_INSPREKER_N") and a UI prompt asks for manual linkage
- If two Sprekers use the same microfoon at overlapping times, both segments flagged `crosstalk`

#### Scenario: Segment linked to registered speaker
- **GIVEN** a TranscriptSegment with microfoonId `MIC_RAADSLID_7` and a Vergadering with a Spreker where microfoonId is `MIC_RAADSLID_7`
- **WHEN** SpeakerRecognitionService runs
- **THEN** the segment.speaker_id is populated with the Spreker UUID
- **AND** the segment.speakerLabel is replaced with the Spreker's name + fractie (e.g. "Dhr. Jansen (VVD)")

#### Scenario: Unknown microphone prompts for manual linkage
- **GIVEN** a TranscriptSegment with microfoonId `MIC_UNKNOWN_99` that does not match any Spreker in the Vergadering
- **WHEN** linking service completes
- **THEN** the segment.speakerLabel remains "SPEAKER_INSPREKER_5" (generic label)
- **AND** the UI shows a prompt in the transcript viewer: "Unknown speaker (mic: MIC_UNKNOWN_99). [Assign to person]"
- **AND** a griffier can click to assign the segment to any Spreker or mark as "inspreker"

#### Scenario: Crosstalk is detected and flagged
- **GIVEN** TranscriptSegments A and B with the same microfoonId and overlapping timeframes (A: 100-120s, B: 105-125s)
- **WHEN** the ASR engine marks both segments as overlapping
- **THEN** both segments receive the flag `crosstalk`
- **AND** the transcript viewer highlights both segments with a red underline
- **AND** a note appears: "Multiple speakers detected at this moment"

---

## Capability: transcript-search

### REQ-LIVE-004 — Timestamping at agenda items

Each TranscriptSegment MUST be linked to an Agendapunt based on the timeline of chair announcements.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | automatic-transcription, deeplink-timestamps |

The system uses keyword heuristics to detect chair announcements (e.g. "we gaan over naar agendapunt 5", "point 3 is now", "item 2 follows"). For each detected announcement, the timestamp becomes the Agendapunt.startTime, and all following segments (until the next announcement) are linked to that Agendapunt.

#### Scenario: Automatic agendapunt detection from chair speech
- **GIVEN** a TranscriptSegment with speaker identified as Voorzitter and text containing "gaan over naar agendapunt 5"
- **WHEN** TranscriptLinkingService processes the transcript
- **THEN** the timestamp of that segment (startTime) is recorded as the start of Agendapunt 5
- **AND** all subsequent segments until the next agendapunt announcement are linked (agendapunt_id = uuid-agendapunt-5)

#### Scenario: Manual boundary adjustment cascades relink
- **GIVEN** a Griffier reviewing a transcript and noticing that agendapunt 3 actually started 45 seconds earlier
- **WHEN** the Griffier adjusts the segment boundary (dragging in the UI)
- **THEN** the system recalculates agendapunt boundaries automatically
- **AND** all affected downstream segments are relinked to the correct agendapunt
- **AND** an audit log entry records: "Boundary adjusted by [griffier] at [time]. Segments A-N relinked."

#### Scenario: Accuracy benchmark
- **GIVEN** a Vergadering with 25 agendapunten and a completed Transcript
- **WHEN** the system calculates how many agendapunten have the correct startTime (within 10 seconds of the official minute-taking)
- **THEN** ≥ 90% of agendapunten have correct start times
- **AND** any agendapunt with > 10s discrepancy is flagged for manual review

---

### REQ-LIVE-005 — Closed captions (WCAG 2.2 AA)

The system MUST generate WebVTT files that comply with WCAG 2.2 AA requirements for prerecorded captions.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | automatic-transcription, closed-captions |

WebVTT formatting rules (per BBC Subtitle Guidelines):
- Each cue MUST have start and end timestamps in HH:MM:SS.mmm format
- Each cue MUST contain at most 2 lines of text
- Each line MUST NOT exceed 32 characters (including speaker name tag)
- Speaker identification MUST be included (e.g. `<v Voorzitter>` in WebVTT, or explicit "Voorzitter:" prefix)

#### Scenario: WebVTT file generates with proper timing
- **GIVEN** a Transcript in `published` status with TranscriptSegments
- **WHEN** the WebVTT export is requested
- **THEN** a valid .vtt file is served with proper WEBVTT header, style block, and cues
- **AND** each cue has startTime → endTime with millisecond precision
- **AND** cues follow the 2-line / 32-char limit

#### Scenario: Player announces captions available to screenreader
- **GIVEN** a Blind user with a screenreader accessing the video player
- **WHEN** the page loads
- **THEN** the screenreader announces: "Closed captions available. Press C to toggle."
- **AND** when captions are enabled, the screenreader reads cue text as it plays

#### Scenario: Speaker names appear in captions
- **GIVEN** a TranscriptSegment with speaker linked to "Mw. Jansen (GroenLinks)"
- **WHEN** the WebVTT file is generated
- **THEN** the cue includes: `<v Mw. Jansen (GroenLinks)> ...` or the speaker name as explicit prefix
- **AND** the full cue (speaker + text) fits within the 2-line / 32-char guideline

---

### REQ-LIVE-006 — Audio description and non-verbal events

The system MUST support audio-only playback and flag non-verbal events (voting, applause) in the transcript.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | closed-captions, automatic-transcription |

Non-verbal events are flagged by ASR (e.g. `[applaus]`, `[geroep]`) and also by integration with the Besluit schema (voting moments).

#### Scenario: Audio-only stream served to bandwidth-limited users
- **GIVEN** a user with limited bandwidth choosing "audio only" from player settings
- **WHEN** the player switches streams
- **THEN** an MP3 or OGG audio file is served from the Livestream.recordingUrl (audio-only extraction)
- **AND** captions and chapter markers are still available
- **AND** file size is ≤ 20% of full video

#### Scenario: Voting event appears in transcript
- **GIVEN** a Stemming record for a Motion created at timestamp 02:15:30
- **WHEN** that timestamp falls within a TranscriptSegment timeframe
- **THEN** a synthetic TranscriptSegment is created: `[Stemming over motie M2024-15: 21 voor, 18 tegen, motie aangenomen]`
- **AND** the segment is timestamped and linked to the relevant Agendapunt

#### Scenario: Applause and crowd noise flagged
- **GIVEN** an ASR engine detecting non-speech audio (applause, heckling)
- **WHEN** a TranscriptSegment is generated for that audio
- **THEN** the segment text includes markup: `[applaus]` or `[geroep]`
- **AND** the segment is flagged with `non-verbal: true` for UI filtering

---

## Capability: transcript-correction

### REQ-LIVE-007 — Manual correction workflow for griffier

Griffiers MUST be able to correct TranscriptSegments with audit trail.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | transcript-correction |

Correction workflow:
1. Griffier opens a segment in correction mode
2. Audio for that segment (startTime → endTime) plays in a inline player
3. Text is editable; on save, originalText is preserved and corrected=true is set
4. When all low-confidence segments (confidence < 0.6) are corrected, Transcript.status becomes `corrected`
5. Publishing a corrected transcript adds badge: "redactioneel geverifieerd"

#### Scenario: Griffier corrects a misheard word
- **GIVEN** a TranscriptSegment with text "...en de windmolens..." but confidence 0.52
- **WHEN** a Griffier with role `decidesk-griffier` opens the segment in correction mode
- **THEN** the audio from segment.startTime plays in an inline player
- **AND** the text is editable (read-write input field)
- **AND** a warning badge shows "Low confidence (52%)"

#### Scenario: Corrected segment preserves original
- **GIVEN** a Griffier editing segment text from "windmolens" to "windmolest..." (typo fixed)
- **WHEN** the Griffier saves
- **THEN** segment.text becomes "windmolest..." (corrected text)
- **AND** segment.originalText becomes "windmolens" (original ASR text)
- **AND** segment.corrected becomes true
- **AND** audit trail logs: "Corrected by [griffier] at [time]. Before: ... After: ..."

#### Scenario: Transcript status auto-transitions to corrected
- **GIVEN** a Transcript with 12 segments, of which 3 have confidence < 0.6 (all with corrected=false)
- **WHEN** the Griffier corrects all 3 low-confidence segments
- **THEN** the system checks: `SELECT COUNT(*) FROM transcript_segments WHERE transcript_id=X AND confidence < 0.6 AND corrected=false`
- **AND** if count = 0, Transcript.status auto-transitions from `draft` to `corrected`
- **AND** a toast notification confirms: "Transcript marked as verified."

#### Scenario: Published corrected transcript gets badge
- **GIVEN** a Transcript with status `corrected` ready for publication
- **WHEN** the Griffier clicks "Publish"
- **THEN** Transcript.status becomes `published`
- **AND** the meeting detail page shows a badge next to the transcript link: "[✓ Redactioneel geverifieerd]"
- **AND** the footer of the transcript viewer displays: "Transcript verified by [griffier] on [date]"

---

## Capability: deeplink-timestamps

### REQ-LIVE-008 — Deep-linking to timestamp

Each combination of Vergadering and timestamp MUST have a permanent URL that opens the stream at that moment.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | deeplink-timestamps |

URL pattern: `/vergadering/{vergadering_uuid}?t=HH:MM:SS`. The player automatically jumps to the given timestamp and starts playback.

#### Scenario: Journalist shares a deeplink to a specific quote
- **GIVEN** a journalist researching a council decision finds a relevant quote in the transcript at 02:34:15
- **WHEN** the journalist right-clicks the segment and selects "Copy link to moment"
- **THEN** the clipboard contains: `https://decidesk.example.nl/vergadering/550e8400-e29b-41d4-a716-446655440000?t=02:34:15`
- **AND** when a reader opens that link, the player jumps to 02:34:15 and starts playing

#### Scenario: Deeplink in decision list
- **GIVEN** a Decision record with genomenOp timestamp (when it was decided) at 03:45:22
- **WHEN** the Decision is displayed in the decision list
- **THEN** a button "🎬 View at moment" appears next to the decision text
- **AND** clicking the button navigates to `/vergadering/{id}?t=03:45:22`

#### Scenario: Player parameter parsing
- **GIVEN** a user visiting `/vergadering/550e8400?t=01:23:45`
- **WHEN** the page loads
- **THEN** the player parses the `t` parameter as `3825` seconds (1*3600 + 23*60 + 45)
- **AND** the player's currentTime is set to 3825 before play begins
- **AND** no timestamp conversion errors occur

---

### REQ-LIVE-009 — Full-text search over transcript content

Transcripts MUST be fully searchable within a single meeting and across all meetings.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | transcript-search |

Search features:
- Per-meeting search: user searches within one Vergadering's transcript
- Fleet-wide search: search across all published transcripts
- Filters: by speaker, by fractie, by year, by agendapunt
- Ranking: by relevance, by date
- Snippets: show 20 words before and after the match

#### Scenario: Single-meeting search
- **GIVEN** a user on a Vergadering detail page viewing the transcript
- **WHEN** the user enters "windturbine" in the transcript search box
- **THEN** the system highlights all occurrences of "windturbine" in the transcript viewer
- **AND** the sidebar shows: "3 matches found. [Jump to first]"
- **AND** each match is accompanied by speaker name and timestamp

#### Scenario: Fleet-wide search with facetting
- **GIVEN** a user on the fleet-wide search page
- **WHEN** the user enters query "windturbine" and applies filters: fractie=PvdA, year=2024
- **THEN** the system returns all TranscriptSegments containing "windturbine" where:
  - Segment speaker is in PvdA fractie
  - Segment's Vergadering startDate is in year 2024
- **AND** results are ranked by relevance (TF-IDF style)
- **AND** each result shows: `[Gemeente] - [Vergadering date] - [Speaker] ([Fractie]): "...{{ snippet }}..."`

#### Scenario: Snippet context display
- **GIVEN** search result for "windturbine" in a 50-word segment
- **WHEN** the result is displayed
- **THEN** the snippet shows: "...en met de milieueisen voor windturbine in het noorden..."
- **AND** the search term is highlighted in the snippet
- **AND** 20 words before and 20 words after are shown (or fewer if near boundaries)

---

### REQ-LIVE-010 — Archive retention and deletion

Meetings MUST be archived or deleted according to the gemeentelijke retention policy.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | archive-retention |

Retention policy defaults to 7 years per Selectielijst gemeenten (raadsvergaderingen are "permanent" for decisions, video/audio is 7-10 years in practice). Soft-delete (mark as deleted_retention) triggers a 30-day grace period before hard-delete.

#### Scenario: Retention cron job marks meeting for deletion
- **GIVEN** a Vergadering with archiveRetentionYears=7 and startedAt timestamp 8 years in the past
- **WHEN** the retention job runs (e.g. daily)
- **THEN** the Vergadering, its Livestream, Transcript, and all TranscriptSegments are marked status=`deleted_retention`
- **AND** an audit log entry is created: "Marked for deletion per retention policy (7 years). Will hard-delete on [30 days from now]."
- **AND** the meeting remains visible on the decision-list page but with a notice: "Media archived on [date]"

#### Scenario: WOB/WOO hold prevents deletion
- **GIVEN** a Vergadering marked for deletion (status=deleted_retention) and a WOO (Wet open overheid) request covering documents from that meeting
- **WHEN** the admin creates a hold record linked to the Vergadering
- **THEN** the retention job detects the hold and skips hard-delete
- **AND** the audit log shows: "Deletion hold: WOO procedure pending. Reassess on [hold expiry date]."

#### Scenario: Post-deletion decision list shows stub
- **GIVEN** a Vergadering whose media has been hard-deleted after the 30-day grace period
- **WHEN** the decision-list page loads
- **THEN** decisions from that meeting are still visible in the list
- **AND** the video/transcript link shows a disabled state with message: "Media removed on [date] per retention policy"
- **AND** the official decision text remains (not deleted, as decisions are permanent per law)

---

## MODIFIED Requirements

### REQ-LIVE-011 — Vergadering.livestream and .transcript relations

The Vergadering entity MUST support optional relations to Livestream and Transcript.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | livestream-embedding, automatic-transcription |

#### Scenario: Livestream relation is populated
- **GIVEN** a Vergadering with a linked Livestream
- **WHEN** the API returns the Vergadering object
- **THEN** the response includes `livestream: { id, provider, streamUrl, status, ... }`
- **AND** the status field indicates whether streaming is currently active

#### Scenario: Missing livestream does not error
- **GIVEN** a Vergadering with no linked Livestream
- **WHEN** the API returns the Vergadering object
- **THEN** the response includes `livestream: null` (not an error)
- **AND** the UI gracefully hides the player section

---

## Data Integrity

### REQ-LIVE-012 — Cascading updates on segment corrections

When a TranscriptSegment is corrected, related indices and searches MUST be invalidated and regenerated.

| Property | Value |
|---|---|
| Type | MUST |
| Capability | transcript-correction |

#### Scenario: Full-text search index updates
- **GIVEN** a corrected TranscriptSegment where originalText="molens" and new text="turbines"
- **WHEN** the correction is saved
- **THEN** the Transcript.fullText field is regenerated (removing old, adding new)
- **AND** OpenRegister's full-text search index is updated
- **AND** subsequent searches for "turbines" include this segment; searches for old "molens" exclude it

---

## Error Handling

All APIs MUST return consistent error envelopes conforming to RFC 7807 (Problem Details for HTTP APIs).

**Error types:**
- `bad_request` — invalid input parameters
- `unauthorized` — user not authenticated
- `forbidden` — user not authorized for this resource
- `not_found` — resource does not exist
- `conflict` — state conflict (e.g. cannot correct a published transcript)
- `internal_server_error` — unexpected server error

#### Example error response
```json
{
  "type": "https://decidesk.example.nl/errors/segment-already-published",
  "title": "Segment Already Published",
  "status": 409,
  "detail": "Cannot correct segment in a transcript with status 'published'. Create a new version or request unpublish.",
  "instance": "/transcripts/660e8400/segments/770e8400"
}
```
