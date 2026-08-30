# Tasks — Raadsvergadering Livestream met Automatische Transcriptie

> Scope reminder: this change implements livestream embedding, automatic transcription via Whisper-NL,
> speaker recognition, transcript search, and archival for decidiq.
> See `proposal.md`, `design.md`, and `specs.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass.
> Do not mark tasks done by inspection — run the listed commands.

## 1. Database schema & OpenRegister entities

- [ ] 1.1 Create OpenRegister schema definitions for Livestream, Transcript, TranscriptSegment, and Spreker
  - Files: `docs/openregister/livestream-schema.json`, `transcript-schema.json`, `segment-schema.json`, `spreker-schema.json`
  - Each schema MUST define all properties from design.md's data model section
  - Each schema MUST include validation rules (type, required, enum values)
  - **Acceptance:** `openregister verify-schema docs/openregister/*.json` returns 0 errors

- [ ] 1.2 Create database migrations for Livestream, Transcript, TranscriptSegment, Spreker tables
  - Files: `lib/Migration/Version*.php` (one per entity, or bundled)
  - Tables MUST include: id, uuid, uri, versions, createdAt, updatedAt, owner, organization (OpenRegister std fields)
  - Foreign key constraints: livestream.vergadering_id → meeting, transcript.livestream_id, segment.transcript_id, etc.
  - Indexes: transcript(vergadering_id), segment(transcript_id, speaker_id, agendapunt_id), full-text on transcript(fullText)
  - **Acceptance:** `php -r "require 'lib/Migration/VersionX.php'; $m = new VersionX(); echo 'OK';"`

- [ ] 1.3 Register schemas in Nextcloud app config
  - File: `lib/AppInfo/Application.php` in the `register()` method
  - Use `IRegistrationContext::registerEntitySchema()` (if available) or OpenRegister's equivalent
  - **Acceptance:** OpenRegister admin panel lists all 4 schemas under decidiq app

## 2. LivestreamService (embedding & streaming)

- [ ] 2.1 Create `lib/Service/LivestreamService.php` with CRUD methods
  - `createLivestream(array $data): Livestream` — create new Livestream linked to Vergadering
  - `getLivestream(uuid): Livestream` — fetch by ID
  - `updateLivestream(uuid, array $updates): Livestream` — update status, recordingUrl, etc.
  - `deleteLivestream(uuid): void` — soft-delete (status=deleted_retention)
  - **Acceptance:** unit test `testCreateLivestream` creates a valid Livestream with all required fields

- [ ] 2.2 Implement Livestream status transitions (scheduled → live → ended → archived)
  - Method: `transitionStatus(uuid, string $newStatus): Livestream`
  - Allowed transitions: scheduled→live, live→ended, ended→archived, any→deleted_retention
  - Disallow invalid transitions (e.g. ended→live)
  - **Acceptance:** unit test `testInvalidStatusTransition` returns error for scheduled→ended

- [ ] 2.3 Integrate HLS/DASH player support (API level)
  - GET `/api/decidiq/v1/livestreams/{livestreamId}` returns Livestream object with streamUrl, recordingUrl, status
  - Response includes CORS headers so player can fetch .m3u8/.mpd from third-party CDNs
  - **Acceptance:** `curl -H "Authorization: Bearer TOKEN" /api/decidiq/v1/livestreams/{id}` returns 200 with streamUrl

## 3. TranscriptionService (ASR pipeline)

- [ ] 3.1 Create `lib/Service/TranscriptionService.php` with Whisper-NL integration
  - `startTranscription(livestreamId): Job` — queue async transcription
  - `processTranscription(livestreamId, attempt=1): Transcript` — download VOD, run Whisper-NL, parse output
  - `retryFailedTranscription(transcriptId, attempt): void` — exponential backoff (immediate, +5m, +15m)
  - Whisper-NL invocation: run Python subprocess or call HTTP API (if available)
  - **Acceptance:** unit test `testStartTranscription` queues a job; integration test `testProcessTranscription` produces a Transcript with segments

- [ ] 3.2 Implement fallback to NOTUBIZ/iBabs ASR if Whisper-NL unavailable
  - Check: if Whisper-NL returns non-200 or GPU is saturated, call NOTUBIZ API
  - Store engine=`notubiz-asr` in Transcript record
  - **Acceptance:** integration test mocks Whisper-NL failure and verifies fallback is called

- [ ] 3.3 Parse ASR output into TranscriptSegment objects
  - Input: ASR JSON/output (timestamps, text, confidence per segment)
  - Output: array of TranscriptSegment records with startTime, endTime, text, confidence
  - Aggregate confidence into Transcript.confidence (average)
  - **Acceptance:** unit test parses sample Whisper-NL output correctly; segments have correct time ranges

- [ ] 3.4 Implement retry logic with exponential backoff
  - Attempt 1: immediate
  - Attempt 2: +5 minutes
  - Attempt 3: +15 minutes
  - On failure: Transcript.status=`pending`, log error, send notification to Griffier
  - **Acceptance:** unit test verifies retry delays; integration test sends notification on 3rd failure

- [ ] 3.5 Implement transcription timeout (60-minute SLA)
  - Monitor: track transcription start time
  - Timeout: if transcription not complete within 60 minutes, kill job and mark Transcript.status=`pending` with timeout error
  - Alert: notify Griffier of timeout
  - **Acceptance:** unit test `testTranscriptionTimeout` kills job after 60 minutes and sends alert

- [ ] 3.6 Implement background job trigger on livestream.endedAt
  - Event listener: when Livestream.status transitions to `ended`, check Vergadering.transcriptionPolicy
  - If transcriptionPolicy=`auto`, queue TranscriptionService::startTranscription()
  - **Acceptance:** integration test creates a livestream, transitions to ended, verifies job is queued

## 4. SpeakerRecognitionService (microphone linking)

- [ ] 4.1 Create `lib/Service/SpeakerRecognitionService.php` for microphone-to-speaker linking
  - `linkSpeakersToSegments(transcriptId): void` — match segment.microfoonId to Spreker.microfoonId
  - `detectCrosstalk(segment[]): void` — flag overlapping segments with same microfoon as crosstalk
  - **Acceptance:** unit test matches segment to Spreker; flags crosstalk correctly

- [ ] 4.2 Implement microphone-ID matching algorithm
  - For each TranscriptSegment:
    - Lookup Spreker records in same Vergadering with matching microfoonId
    - If 1 match found: set segment.speaker_id and populate speaker name
    - If 0 matches: leave segment.speakerLabel generic (e.g. "SPEAKER_INSPREKER_N")
    - If 2+ matches: log warning (shouldn't happen in well-configured system)
  - **Acceptance:** unit test with fixture Sprekers and segments; verifies correct linking

- [ ] 4.3 Implement crosstalk detection
  - For each segment: check if any other segment in the transcript has:
    - Same microfoonId
    - Overlapping timeframe (segment.startTime < other.endTime AND segment.endTime > other.startTime)
  - If overlap found: flag both segments with `crosstalk: true`
  - **Acceptance:** unit test with overlapping time ranges; segments are flagged

- [ ] 4.4 Create UI prompt for unlinked segments
  - Component: `UnlinkedSpeakerPrompt.vue`
  - Render when segment.speaker_id is null
  - Show: speaker label, microphone ID, audio snippet
  - Allow: assign to existing Spreker or create new (inspreker)
  - **Acceptance:** component displays; user can select speaker from dropdown

## 5. TranscriptLinkingService (agendapunt matching)

- [ ] 5.1 Create `lib/Service/TranscriptLinkingService.php` for segment-to-agendapunt linking
  - `linkSegmentsToAgendapunten(transcriptId): void` — heuristic keyword matching
  - `manuallyAdjustBoundary(segmentId, newStartTime): void` — override + cascade relink
  - **Acceptance:** unit test links segments to correct agendapunten with ≥90% accuracy (using fixture data)

- [ ] 5.2 Implement keyword heuristic for chair announcements
  - Keywords: "gaan over naar agendapunt", "volgende agendapunt", "point", "item", "volgende onderwerp"
  - Regex pattern to extract agendapunt number/title
  - For each detected keyword: record timestamp and associated agendapunt
  - Link all subsequent segments until next announcement
  - **Acceptance:** unit test matches sample transcript text; correctly identifies agendapunt boundaries

- [ ] 5.3 Implement manual boundary adjustment with cascade
  - Input: segmentId, newStartTime
  - Action: adjust segment boundary; recalculate all linked segments for affected agendapunt range
  - Audit: log the adjustment with old/new boundaries
  - **Acceptance:** unit test adjusts boundary; verifies downstream segments are relinked

- [ ] 5.4 Implement accuracy reporting
  - Compare detected agendapunt start times vs. official Agendapunt records
  - Report: % of agendapunten with ≥10 second accuracy
  - Flag: any agendapunt with > 10s discrepancy for review
  - **Acceptance:** unit test calculates accuracy correctly; flags outliers

## 6. TranscriptSearchService (full-text search)

- [ ] 6.1 Create `lib/Service/TranscriptSearchService.php` for search and export
  - `search(query: string, filters: array): SearchResult[]` — wrapper around OpenRegister full-text search
  - `export(transcriptId, format: string): Stream` — export WebVTT, SRT, or plain text
  - **Acceptance:** unit test searches across test transcripts; returns ranked results

- [ ] 6.2 Implement per-meeting search
  - Query: search transcript text within a single Vergadering
  - Result: array of TranscriptSegments with matched speaker, timestamp, snippet
  - **Acceptance:** unit test searches within one meeting; returns correct segments

- [ ] 6.3 Implement fleet-wide search with filters
  - Query: search across all published transcripts
  - Filters: by speaker, by fractie, by year, by agendapunt
  - Result: sorted by relevance (TF-IDF or OpenRegister's ranking)
  - **Acceptance:** unit test applies filters; verifies results match criteria

- [ ] 6.4 Implement snippet extraction (context window)
  - For each match: extract 20 words before and after
  - Handle boundary cases (match at start/end of segment)
  - Highlight search term in snippet
  - **Acceptance:** unit test extracts snippets correctly; no buffer overrun at boundaries

- [ ] 6.5 Implement Transcript.fullText aggregation
  - When Transcript is created or segments are corrected, regenerate Transcript.fullText
  - fullText = concatenation of all segments' text in time order, with speaker names
  - Index this field with OpenRegister's full-text search
  - **Acceptance:** unit test; fullText contains all segment text; OpenRegister search finds it

## 7. WebVTT generation (captions)

- [ ] 7.1 Create `lib/Service/WebVttService.php` for caption export
  - `generateWebVtt(transcriptId): string` — generate WebVTT-format string
  - Format: WEBVTT header, optional STYLE block, cues with startTime → endTime
  - **Acceptance:** unit test generates valid WebVTT syntax; validates with `webvttvalidator.com` API or local parser

- [ ] 7.2 Implement BBC Subtitle Guidelines (2 lines × 32 chars)
  - For each segment: split text into lines of ≤ 32 chars
  - Maximum 2 lines per cue
  - If speaker name + text exceeds 2 lines, create multiple cues
  - **Acceptance:** unit test; all cues are ≤ 2 lines × 32 chars

- [ ] 7.3 Implement speaker-name styling in WebVTT
  - Format: `<v Speaker Name>` tag or explicit "Speaker Name:" prefix
  - Example: `<v Dhr. Jansen (VVD)> We moeten voorzichtig zijn.`
  - **Acceptance:** unit test generates correctly-formatted speaker tags

- [ ] 7.4 Implement WebVTT endpoint for player
  - GET `/api/decidiq/v1/transcripts/{transcriptId}/webvtt` returns WebVTT file
  - Content-Type: `text/vtt`
  - CORS headers for cross-origin player access
  - **Acceptance:** curl request returns valid WebVTT; player can parse and render captions

- [ ] 7.5 Implement non-verbal event markup
  - ASR flags: `[applaus]`, `[geroep]`, `[hoestbui]` in segment text
  - These are passed through to WebVTT as-is
  - **Acceptance:** unit test preserves ASR flags; WebVTT contains them

## 8. Transcript correction workflow (griffier)

- [ ] 8.1 Create `lib/Service/TranscriptCorrectionService.php` for segment editing
  - `correctSegment(segmentId, correctedText): TranscriptSegment` — update text, preserve original
  - `autoTransitionStatus(transcriptId): void` — check if all low-confidence segments are corrected
  - **Acceptance:** unit test saves correction; verifies originalText is preserved and corrected=true

- [ ] 8.2 Implement low-confidence flagging
  - When Transcript is generated: identify segments with confidence < 0.60
  - Mark Transcript with flag: `needsReview: true`
  - UI shows warning badge on transcript
  - **Acceptance:** unit test with fixture segments; correctly flags low-confidence ones

- [ ] 8.3 Implement auto-transition from draft → corrected
  - After each correction: check: `SELECT COUNT(*) FROM segments WHERE transcript_id=X AND confidence < 0.6 AND corrected=false`
  - If count = 0: Transcript.status becomes `corrected`
  - Fire event: `transcript.corrected`
  - **Acceptance:** unit test; verifies status transitions when all low-conf segments are corrected

- [ ] 8.4 Create correction UI component
  - File: `resources/js/components/CorrectSegmentModal.vue`
  - Features:
    - Display segment speaker, timestamp, original text, audio player (startTime → endTime)
    - Editable text field
    - Save / Cancel buttons
    - On save: call `lib/Controller/TranscriptController::correctSegment()`
  - **Acceptance:** component renders; user can edit text and save; API is called

- [ ] 8.5 Create audit trail for corrections
  - Table: `transcript_corrections` (segmentId, correctedBy, correctedAt, beforeText, afterText)
  - Or: log to Nextcloud audit event (`OCP\IEventDispatcher`)
  - **Acceptance:** unit test; correction event is logged with correct fields

## 9. Deeplink support (timestamps)

- [ ] 9.1 Implement `?t=HH:MM:SS` URL parameter parsing
  - File: `resources/js/components/LivestreamPlayer.vue`
  - Parse query param `t` as HH:MM:SS or MMM (seconds)
  - Convert to seconds: `hh*3600 + mm*60 + ss`
  - Set player.currentTime before autoplay
  - **Acceptance:** unit test parses all valid formats; ignores invalid ones without error

- [ ] 9.2 Implement deeplink generation in transcript viewer
  - For each TranscriptSegment: display "Copy link" button
  - On click: build URL `/vergadering/{vergaderungId}?t=HH:MM:SS` and copy to clipboard
  - **Acceptance:** component renders button; generates correct deeplinks; copies to clipboard

- [ ] 9.3 Implement deeplink in decision list
  - For each Decision with genomenOp timestamp: render "🎬 View at moment" button
  - On click: navigate to `/vergadering/{id}?t=HH:MM:SS`
  - **Acceptance:** button appears on decision; click navigates to correct timestamp

## 10. Retention & archival

- [ ] 10.1 Create `lib/Service/RetentionService.php` for lifecycle management
  - `markForDeletion(vergaderinguuids[], retentionYears): void` — soft-delete
  - `hardDeleteAfterGrace(gracePeriodDays): void` — actual removal
  - `placeWOBHold(vergaderingUuid, holdUuid): void` — prevent deletion during legal hold
  - **Acceptance:** unit test marks meeting for deletion; verifies status is deleted_retention

- [ ] 10.2 Implement configurable retention policy
  - Config: `Vergadering.archiveRetentionYears` (default 7)
  - Allow comune-admins to customize per-body (e.g., 5, 7, 10 years)
  - **Acceptance:** API allows setting retention; default is 7; persists to database

- [ ] 10.3 Implement cron job for retention enforcement
  - File: `lib/Cron/RetentionJob.php` or similar
  - Runs daily: find meetings with startedAt > archiveRetentionYears in the past
  - Mark for deletion: Livestream, Transcript, TranscriptSegments, Spreker records
  - Audit log: reason, expected hard-delete date
  - **Acceptance:** unit test (mock time) marks old meetings; cron command runs without error

- [ ] 10.4 Implement WOB/WOO hold mechanism
  - Table: `retention_holds` (vergaderingId, holdType, holdReason, expiryDate)
  - On cron run: skip deletion if hold exists and not expired
  - **Acceptance:** unit test; marked-for-deletion meeting is skipped if hold exists

- [ ] 10.5 Implement 30-day grace period
  - Mark: status = `deleted_retention`, deletionScheduledFor = now + 30 days
  - Hard-delete cron: only delete if now >= deletionScheduledFor
  - **Acceptance:** unit test; soft-deleted record is not hard-deleted within 30 days; is deleted after

- [ ] 10.6 Create post-deletion stub on decision list
  - When Decision.vergadering.transcript status is deleted_retention
  - Render: disabled link with message "Media removed on {date} per retention policy"
  - **Acceptance:** component renders correctly; decision text remains visible

## 11. API Controllers

- [ ] 11.1 Create `lib/Controller/LivestreamController.php`
  - POST `/api/decidiq/v1/livestreams` — create
  - GET `/api/decidiq/v1/livestreams/{id}` — read
  - PATCH `/api/decidiq/v1/livestreams/{id}` — update
  - DELETE `/api/decidiq/v1/livestreams/{id}` — soft-delete
  - Auth: chair-only for creation/update
  - **Acceptance:** phpunit tests pass; all endpoints return correct status codes

- [ ] 11.2 Create `lib/Controller/TranscriptController.php`
  - GET `/api/decidiq/v1/transcripts/{id}` — read metadata
  - GET `/api/decidiq/v1/transcripts/{id}/segments` — list segments
  - GET `/api/decidiq/v1/transcripts/{id}/webvtt` — export captions
  - PATCH `/api/decidiq/v1/transcripts/{id}/segments/{segmentId}` — correct segment
  - POST `/api/decidiq/v1/transcripts/search` — search
  - Auth: participant for view; griffier for correct
  - **Acceptance:** all endpoints tested; auth checks work

- [ ] 11.3 Implement consistent error responses (RFC 7807)
  - All errors return JSON: `{ type, title, status, detail, instance }`
  - Test: 404 for missing transcript, 403 for unauthorized, 400 for bad request
  - **Acceptance:** phpunit test checks error format; all endpoints return RFC 7807 format

## 12. Frontend components (Vue)

- [ ] 12.1 Create `resources/js/components/LivestreamPlayer.vue`
  - Features: HLS/DASH playback, captions toggle, speed/volume, fullscreen, chapter nav
  - Props: livestreamUrl, recordingUrl, captions (VTT URL), chapters (Agendapunten), t (timestamp)
  - Emit: onReady, onPlay, onPause, onSeek
  - Use: video.js or similar HLS-capable library
  - **Acceptance:** component renders in browser; plays video; toggles captions; jumps to timestamp

- [ ] 12.2 Create `resources/js/components/TranscriptViewer.vue`
  - Features: display segments in list, speaker highlighting, timestamp links, search highlighting
  - Props: segments[], selectedSegmentId
  - Emit: onSegmentClick (jump to timestamp), onCorrectClick (open correction modal)
  - Styling: speaker color-coding, low-confidence warning badges
  - **Acceptance:** component renders all segments; clicking segment emits event; badges display correctly

- [ ] 12.3 Create `resources/js/components/TranscriptSearch.vue`
  - Features: search input, results list, filters (speaker, fractie, year, agendapunt)
  - Search: call API on debounce, display results with snippets
  - **Acceptance:** component renders; search works; filters apply; results display correctly

- [ ] 12.4 Create `resources/js/components/CorrectSegmentModal.vue`
  - Features: audio player (segment range), editable text, save/cancel buttons
  - Save: call API, update Transcript status if needed
  - **Acceptance:** modal renders; audio plays segment; text is editable; save calls API

- [ ] 12.5 Integrate components into Meeting detail page
  - File: `resources/js/views/MeetingDetail.vue`
  - Add: LivestreamPlayer (top), TranscriptViewer (below), links to deeplinks
  - Conditional: only render if Livestream/Transcript exist
  - **Acceptance:** page loads without error; components appear below agenda when data present

- [ ] 12.6 Integrate components into Decision list page
  - File: `resources/js/views/DecisionList.vue`
  - Add: "🎬 View at moment" button next to each decision with timestamp
  - Click: navigate to `/vergadering/{id}?t=HH:MM:SS`
  - **Acceptance:** button appears; click navigates correctly

## 13. Testing

- [ ] 13.1 Write unit tests for all Services
  - Files: `tests/Unit/Service/TranscriptionService*Test.php`, etc.
  - Coverage: ≥ 85% for each service class
  - **Acceptance:** `phpunit tests/Unit/Service/` returns ≥ 85% coverage

- [ ] 13.2 Write integration tests
  - Files: `tests/Integration/Service/*IntegrationTest.php`
  - Coverage: end-to-end flows (livestream → transcription → search)
  - **Acceptance:** `phpunit tests/Integration/Service/` all pass

- [ ] 13.3 Write API endpoint tests
  - Files: `tests/Integration/Controller/*ControllerTest.php`
  - Coverage: all endpoints, error cases, auth checks
  - **Acceptance:** `phpunit tests/Integration/Controller/` all pass

- [ ] 13.4 Write component tests (Vue)
  - Files: `resources/js/components/__tests__/*.spec.js`
  - Coverage: rendering, user interaction, emitted events
  - **Acceptance:** `npm run test:unit` all pass; jest coverage ≥ 80%

- [ ] 13.5 Write accessibility tests
  - Coverage: WCAG 2.2 AA compliance (captions, screenreader, keyboard nav)
  - Tools: axe-core, WAVE, manual testing
  - **Acceptance:** axe-core reports 0 critical/serious issues on all pages

## 14. Documentation

- [ ] 14.1 Create feature documentation
  - File: `docs/features/livestream-and-transcription.md`
  - Content: feature overview, user guide (griffier & citizen), API examples, troubleshooting
  - **Acceptance:** markdown renders without errors; covers all user roles

- [ ] 14.2 Create operator documentation
  - File: `docs/operators/livestream-setup.md`
  - Content: Whisper-NL setup, NOTUBIZ fallback config, retention policy config, cron job setup
  - **Acceptance:** includes example config; deployment instructions clear

- [ ] 14.3 Document entity schemas
  - File: `docs/api/schemas/livestream.md`, `transcript.md`, etc.
  - Content: property definitions, validation rules, example objects
  - **Acceptance:** matches actual schema definitions in code

- [ ] 14.4 Document API endpoints
  - File: `docs/api/livestream.md`, `transcript.md`
  - Content: endpoint paths, methods, auth requirements, request/response examples
  - **Acceptance:** generated from OpenAPI/Swagger spec or manually verified

## 15. Final polish

- [ ] 15.1 Code review & quality checks
  - Run: `composer check:strict` (static analysis)
  - Run: `npm run lint` (ESLint)
  - Fix: all warnings and errors
  - **Acceptance:** zero warnings/errors in both

- [ ] 15.2 Performance testing
  - Load test: 100-hour transcript, search for common terms (< 1s response)
  - Memory test: transcription job uses < 4GB RAM
  - Player test: captions load within 500ms
  - **Acceptance:** benchmarks pass; no OOM errors

- [ ] 15.3 Accessibility audit
  - Run: automated tests (axe-core, WAVE)
  - Manual: keyboard nav, screenreader with NVDA/JAWS
  - Fix: all issues
  - **Acceptance:** WCAG 2.2 AA conformance verified

- [ ] 15.4 Security review
  - Check: SQL injection (parameterized queries)
  - Check: XSS (Vue escaping, HTML sanitization)
  - Check: CSRF (token validation)
  - Check: Auth (role-based access control)
  - **Acceptance:** no security issues found; code reviewed by security team

- [ ] 15.5 Merge & release
  - Create PR from feature branch
  - Get approval from code reviewer, architect, and QA
  - Merge to main
  - Tag release version (e.g., `v2.5.0`)
  - **Acceptance:** PR merged; release tagged; CHANGELOG.md updated
