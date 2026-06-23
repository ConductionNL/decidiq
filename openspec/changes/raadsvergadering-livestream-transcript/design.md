# Design — Raadsvergadering Livestream met Automatische Transcriptie

## Context

DecideDesk manages council meetings from scheduling through decision-making and archival. Currently, meeting minutes are text-based and transcription is manual. The addition of livestream + automatic transcription requires:

1. **Storage layer** — 4 new entities in OpenRegister (Livestream, Transcript, TranscriptSegment, Spreker)
2. **Integration layer** — Whisper-NL or NOTUBIZ ASR pipeline; microphone-to-speaker linking via openconnector
3. **Player layer** — HLS/DASH streaming with WCAG-compliant captions
4. **Search layer** — Full-text indexing of transcript content
5. **UI layer** — Transcript viewer, deeplink generator, correction workflow

**Current decidesk state:**
- Meeting data stored in CalDAV (VEVENT) with OpenRegister wrappers (ADR-002)
- Agenda items, decisions, motions stored in OpenRegister (ADR-000, ADR-001)
- No streaming or transcription code exists
- UI has meeting detail page and decision list page

**Stakeholders:**
- Griffies (council secretaries) — want faster decision-list generation
- Burgers (citizens) — want to find relevant moments without watching entire recordings
- Journalisten — want quotes with timestamp links
- Accessibility advocates — want WCAG compliance

## Goals / Non-Goals

**Goals:**

- Embed HLS/DASH streams in meeting detail page with WCAG-compliant player
- Automatically generate Dutch transcripts within 60 minutes via Whisper-NL or NOTUBIZ ASR
- Link TranscriptSegments to Agendapunten via speaker announcements
- Recognize speakers via microphone IDs from zaalsysteem
- Generate WebVTT closed captions
- Implement full-text search across all transcripts
- Support deeplinks to timestamps (`?t=HH:MM:SS`)
- Provide correction workflow for griffies with audit-trail
- Implement retention policies per Archiefwet (default 7 years)

**Non-Goals:**

- Real-time captions during livestream (v2)
- LLM-generated summaries per agendapunt (v2)
- Sentiment analysis or interrupt-counting (analytics, separate spec)
- Translation to English or simplified Dutch (v2)
- Biometric speaker recognition (privacy risk; microphone linking is substitute)
- Video editing or re-indexing features

## Decisions

### D1: Four-table entity model (separate from ADR-000 governance data)

The context-brief defines four new schema: `Livestream`, `Transcript`, `TranscriptSegment`, `Spreker`. These are stored as separate OpenRegister entities, not as extensions to existing Meeting/Participant/Speech.

**Why:**
- Each entity has distinct lifecycle and access patterns (e.g. Transcript has status: pending → processing → draft → published → corrected)
- Transcript is optional for a meeting (no 1:1 requirement); Livestream is optional; Spreker is per-vergadering-specific (not global Person)
- Separation enables independent archival policies (Spreker data deleted at 5 years; Transcript at 7 years per selection)
- Microphone-to-speaker linking is operational, not biographical — separate from Person

**Alternatives considered:**
- Extend Meeting directly with transcript fields (loses lifecycle control, bloats entity)
- Store transcript as unstructured text blob (loses segment-level granularity for search, deeplink, correction)
- Use Person + Role instead of Spreker (loses per-meeting context like `microfoonId`, `sprektijd`)

### D2: Whisper-NL as default ASR engine, with NOTUBIZ/iBabs fallback

The `Transcript` entity stores `engine: enum(whisper-nl, notubiz-asr, ibabs-asr, human-corrected)`. Decidesk's transcription service tries Whisper-NL first (local GPU) and falls back to NOTUBIZ/iBabs if unavailable.

**Why:**
- Whisper-NL (University of Twente / Common Voice) achieves 12% WER on Dutch parliamentary speech vs 18% for NOTUBIZ (based on gemeente Utrecht 2025 eval)
- Runs locally — no vendor lock-in, no cloud data transfer, own AVG grondslag
- Falls back gracefully for communes without GPU capacity
- Confidence scores are comparable across engines

**Alternatives considered:**
- NOTUBIZ-ASR only (easier integration, but worse quality and vendor lock-in)
- Microsoft Azure Speech (cloud, EU-hosted, but requires API key infrastructure)
- No ASR, manual transcription only (defeats goal of 60-minute turnaround)

### D3: Microphone-based speaker recognition, not voice fingerprinting

TranscriptSegment links to Spreker via `microfoonId` from the zaalsysteem (NOTUBIZ, iBabs), not via voice biometrics.

**Why:**
- Voice fingerprinting (pyannote.audio) would be AVG-classified as biometric personal data (artikel 9)
- Microphone linking is operational metadata (which physical mic was used), not biometric
- Fallback for unidentified speakers (e.g. ad-hoc inspreker) is manual linkage, acceptable overhead
- Privacy-first approach aligns with Dutch governance norms

**Alternatives considered:**
- Voice fingerprinting (privacy risk under AVG artikel 9; rejected)
- Manual speaker labeling only (infeasible at scale; 3-4 hour meetings × multiple sprekers)

### D4: WebVTT for captions, not SRT or EBU-TT-D

The Transcript entity exports `vtt: uri` pointing to a WebVTT file served by decidesk.

**Why:**
- WebVTT is HTML5 video standard, native support in all modern browsers
- Supports speaker name styling (e.g. `SPEAKER
<v> Mw. Jansen (GroenLinks):`) and cue-region positioning
- BBC Subtitle Guidelines (2-lines × 32-char cues) provide readability baseline
- Trivial to convert to SRT or EBU-TT-D on export if needed
- No external player or library required

**Alternatives considered:**
- SRT (lacks styling, too simplistic)
- EBU-TT-D (overkill; designed for broadcast with complex metadata)

### D5: Agendapunt linking via keyword detection, with manual override

Linking TranscriptSegments to Agendapunten happens in two stages:
1. Automatic: listen for voorzitter's "we gaan over naar agendapunt N" pattern (confidence: keyword-heuristic)
2. Manual override: griffiers can manually adjust segment boundaries, triggering automatic re-link of downstream segments

**Why:**
- Voorzitters reliably announce agendapunten by name/number in structured way
- Keyword matching is fast, deterministic, and auditable
- Manual override is needed for ad-hoc restructuring or transcription errors
- 90%+ accuracy achievable without ML (target per REQ-LIVE-004)

**Alternatives considered:**
- Full ML-based segmentation (overkill; keyword heuristic sufficient)
- Time-based bucketing (fragile; voorzitters vary pacing)
- No automatic linking, manual-only (violates 90% accuracy target)

### D6: Separate `Spreker` entity (not Person + Membership)

A new `Spreker` entity holds per-meeting speaker context (role, fractie, microfoonId, sprektijd). Person and Membership records stay unchanged.

**Why:**
- One Person can have multiple roles in one meeting (e.g. chair then as member during voting)
- Membership is long-term (person is councillor for 4 years); Spreker is per-meeting (person is chair *this meeting*)
- Fractie is volatile (person switches faction); capturing fractie at meeting-time is necessary for historical accuracy
- Microphone ID is transient (person sits at different mic each meeting); centralizing in Spreker avoids polluting Person/Membership

**Alternatives considered:**
- Extend Person with optional microfoonId (loses transaction semantics; person records would be mutated per meeting)
- Extend Speech (but Speech doesn't exist in current decidesk impl; deferred per ADR-001)

### D7: Soft-delete for transcripts during retention, not hard-delete

When archiveRetentionYears expires, the Vergadering, Livestream, Transcript, and TranscriptSegments are marked `status: deleted_retention` and marked for hard-delete after 30-day grace period.

**Why:**
- Griffies occasionally reference old transcripts during dispute resolution or research
- Decision-list-page stays accessible; transcript-viewer shows "video and transcript removed on {date} per retention policy"
- 30-day grace allows recovery if deletion was accidental or if a WOB/WOO hold is discovered
- Audit trail preserved: deletion event logged with reason and timestamp

**Alternatives considered:**
- Hard-delete immediately (loses recovery option; risky)
- Never delete (violates archival law and storage costs)

### D8: Full-text search via OpenRegister's built-in index

Transcript.fullText field is indexed by OpenRegister's search service. Queries use OpenRegister's REST API with filtering.

**Why:**
- OpenRegister already manages full-text indexing for all entities
- Decidesk doesn't need custom Elasticsearch or Solr
- Integration is REST call + filtering on response
- Federation with other apps' searches (future: cross-app search)

**Alternatives considered:**
- Custom Elasticsearch (infrastructure burden; over-engineered)
- SQL LIKE (slow at scale; inefficient)

## Architecture

### Data model

**Livestream** → Vergadering (one-to-one, optional)
- id, uuid, uri (inherited from OpenRegister)
- vergadering_id → Vergadering
- provider: enum(notubiz, ibabs, companion, vimeo, youtube-live, custom-hls)
- streamUrl: string (HLS .m3u8 or DASH .mpd URL)
- posterImage: uri (preview thumbnail)
- status: enum(scheduled, live, ended, archived)
- startedAt, endedAt, duration: ISO 8601
- recordingUrl: uri (VOD after ended)
- dvrEnabled: bool
- accessControl: enum(public, authenticated, embargoed)
- embargoedUntil: datetime (optional)

**Transcript** → Vergadering (one-to-one, optional)
- id, uuid, uri
- vergadering_id → Vergadering
- livestream_id → Livestream (optional)
- language: BCP-47 (default nl-NL)
- engine: enum(whisper-nl, notubiz-asr, ibabs-asr, human-corrected)
- engineVersion: string (e.g. "whisper-large-v3-nl-20250501")
- confidence: 0..1 (average across all segments)
- generatedAt, correctedAt: datetime
- correctedBy: string (User ID)
- status: enum(pending, processing, draft, published, corrected, deleted_retention)
- wordCount: int
- fullText: text (indexed for full-text search)
- vtt: uri (WebVTT file for captions)

**TranscriptSegment** (atomic unit)
- id, uuid, uri
- transcript_id → Transcript
- startTime, endTime: decimal seconds (float, allows sub-second granularity)
- speakerLabel: string (e.g. "SPEAKER_03" before linking, or "INSPREKER_5")
- speaker_id → Spreker (optional, filled after linking)
- microfoonId: string (link to zaalsysteem)
- text: text (full segment text)
- confidence: 0..1 (ASR confidence)
- agendapunt_id → AgendaItem (optional, filled after linking)
- corrected: bool (true if manually edited)
- originalText: text (only if corrected=true)
- flags: array of enum(inaudible, crosstalk, foreign-language, technical-issue)

**Spreker** (speaker context per meeting)
- id, uuid, uri
- vergadering_id → Vergadering
- persoon_id → Person (optional; required for raadsleden, griffier, voorzitter)
- rol: enum(voorzitter, griffier, raadslid, wethouder, burgemeester, inspreker, ambtenaar, gast)
- fractie_id → Fractie (optional; only for raadsleden)
- microfoonId: string
- aanwezigVanaf, aanwezigTot: datetime
- sprektijd: ISO 8601 duration (aggregated from TranscriptSegments)

**Vergadering** (extended)
- +livestream_id → Livestream (optional)
- +transcript_id → Transcript (optional)
- +transcriptionPolicy: enum(none, auto, human-reviewed) (default: auto)
- +archiveRetentionYears: int (default: 7)

### Seed data

**Livestream**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "vergadering_id": "uuid-meeting-001",
  "provider": "notubiz",
  "streamUrl": "https://cdn.notubiz.nl/meeting-001.m3u8",
  "posterImage": "https://cdn.notubiz.nl/meeting-001-poster.jpg",
  "status": "ended",
  "startedAt": "2026-05-22T19:00:00+02:00",
  "endedAt": "2026-05-22T22:30:00+02:00",
  "duration": "PT3H30M",
  "recordingUrl": "https://cdn.notubiz.nl/meeting-001-vod.mp4",
  "dvrEnabled": true,
  "accessControl": "public"
}
```

**Transcript**
```json
{
  "id": "660e8400-e29b-41d4-a716-446655440000",
  "vergadering_id": "uuid-meeting-001",
  "livestream_id": "550e8400-e29b-41d4-a716-446655440000",
  "language": "nl-NL",
  "engine": "whisper-nl",
  "engineVersion": "whisper-large-v3-nl-20250501",
  "confidence": 0.82,
  "generatedAt": "2026-05-22T23:15:00+02:00",
  "status": "draft",
  "wordCount": 12847,
  "fullText": "Voorzitter: Ik open de vergadering... [full transcript text]",
  "vtt": "https://decidesk.local/transcripts/660e8400.vtt"
}
```

**TranscriptSegment**
```json
{
  "id": "770e8400-e29b-41d4-a716-446655440000",
  "transcript_id": "660e8400-e29b-41d4-a716-446655440000",
  "startTime": 45.23,
  "endTime": 78.91,
  "speakerLabel": "SPEAKER_01",
  "speaker_id": "uuid-spreker-voorzitter",
  "microfoonId": "MIC_VOORZITTER_1",
  "text": "We gaan over naar agendapunt 3, begroting 2026.",
  "confidence": 0.94,
  "agendapunt_id": "uuid-agendapunt-003",
  "corrected": false
}
```

**Spreker**
```json
{
  "id": "880e8400-e29b-41d4-a716-446655440000",
  "vergadering_id": "uuid-meeting-001",
  "persoon_id": "uuid-person-hans-bakker",
  "rol": "voorzitter",
  "microfoonId": "MIC_VOORZITTER_1",
  "aanwezigVanaf": "2026-05-22T18:55:00+02:00",
  "aanwezigTot": "2026-05-22T22:35:00+02:00",
  "sprektijd": "PT18M42S"
}
```

### Service layer

**TranscriptionService**
- `startTranscription(livestreamId): Job` — queue async transcription job
- `processTranscription(livestreamId): Transcript` — calls Whisper-NL or NOTUBIZ API, parses output, creates Transcript + TranscriptSegments
- `retryFailedTranscription(transcriptId, attempt): void` — retry up to 3 times with exponential backoff

**SpeakerRecognitionService**
- `linkSpeakersToSegments(transcriptId): void` — match segmentLabel / microfoonId to Spreker records
- `deectCrosstalk(segment[]): void` — flag segments where two speakers overlap in time

**TranscriptLinkingService**
- `linkSegmentsToAgendapunten(transcriptId): void` — heuristic keyword matching for agendapunt announcements
- `manuallyAdjustBoundary(segmentId, newStartTime): void` — griffier override + cascade relinking

**TranscriptSearchService**
- `search(query, filters): SearchResult[]` — wrapper around OpenRegister full-text search
- `export(transcriptId, format): Stream` — WebVTT, SRT, or plain text

**RetentionService**
- `markForDeletion(vergaderings[], retentionYears): void` — mark Transcript + Livestream as deleted_retention
- `hardDeleteAfterGrace(gracePeriodDays): void` — actually delete after grace period

### API layer

**REST endpoints** (all under `/api/decidesk/v1/`):

- `POST /livestreams` — create livestream (chair-only)
- `GET /livestreams/{livestreamId}` — read livestream
- `PATCH /livestreams/{livestreamId}` — update status (e.g. live → ended)
- `GET /transcripts/{transcriptId}` — read transcript metadata
- `GET /transcripts/{transcriptId}/segments` — list segments (paginated)
- `PATCH /transcripts/{transcriptId}/segments/{segmentId}` — correct segment (griffier-only, audit-logged)
- `GET /transcripts/search?q=...&filters=...` — search
- `GET /transcripts/{transcriptId}/webvtt` — WebVTT file
- `GET /vergadering/{vergaderungId}?t=HH:MM:SS` — deeplink (redirect to player at timestamp)

### UI layer

**Components:**
- `LivestreamPlayer.vue` — HLS/DASH player with captions toggle, speed/volume controls
- `TranscriptViewer.vue` — collapsible segment list with speaker names, time-linked highlights
- `TranscriptSearch.vue` — search form + result grid with pagination
- `CorrectSegmentModal.vue` — edit interface for griffiers with audio playback

**Integration points:**
- Meeting detail page embeds `LivestreamPlayer` + `TranscriptViewer` below agenda
- Decision list page adds "view at {timestamp}" link next to each decision
- Search page adds transcript results tab

## Implementation phases

**Phase 1: Data model + basic transcription**
- Create four new OpenRegister entities and migrations
- TranscriptionService with Whisper-NL integration
- ASR background job (triggered when livestream ends)

**Phase 2: Linking + search**
- SpeakerRecognitionService (microphone→Spreker)
- TranscriptLinkingService (agendapunt matching)
- TranscriptSearchService + OpenRegister index integration
- Segment correction workflow + audit trail

**Phase 3: UI + player**
- LivestreamPlayer, TranscriptViewer, TranscriptSearch components
- Deeplink support (`?t=HH:MM:SS`)
- Decision list integration

**Phase 4: Retention + Polish**
- RetentionService + cron job
- WCAG testing + accessibility audit
- Performance optimization (caching, pagination)

## Dependencies & constraints

- **openconnector** must provide NOTUBIZ/iBabs event streams (livestream.started/ended, microphone.activated)
- **Whisper-NL** must be locally available or fallback to NOTUBIZ API
- **OpenRegister** must support full-text indexing (already does per ADR-003)
- **CalDAV** for meeting storage (already in use per ADR-002)

## Open questions for review

1. **Retention policy override:** Should WOB/WOO requests automatically trigger a hold, or require manual admin action?
2. **Inspreker privacy:** Should inspreker transcripts have shorter retention (2 years default) than raadsleden (7 years)?
3. **Confidence threshold:** At what segment confidence should we flag for manual review? (Proposal: < 0.70)
4. **Parallel Whisper instances:** If GPU capacity is limited, should we batch transcriptions overnight or queue real-time?
