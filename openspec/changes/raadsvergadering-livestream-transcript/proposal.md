---
kind: code
depends_on: []
---

# Raadsvergadering Livestream met Automatische Transcriptie

## Why

Nederlandse gemeenten zijn wettelijk verplicht om raadsvergaderingen openbaar te maken (Gemeentewet artikel 23). In de praktijk worden deze streams via NOTUBIZ, iBabs of Companion-streaming geleverd. Het terugkijken van een vergadering duurt echter veel tijd — een gemiddelde raadsvergadering duurt 3-4 uur — en burgers, journalisten en belangenorganisaties moeten vaak het hele bestand doorlopen om één agendapunt of één sprekersmoment te vinden.

Bovendien zijn de huidige streams meestal niet WCAG-conform (geen closed captions, geen transcript, geen hoofdstuk-navigatie), wat een toegankelijkheidsprobleem vormt voor ongeveer 1,5 miljoen doven en slechthorenden in Nederland.

Deze spec voegt aan decidiq een livestream- en transcriptie-laag toe die:

1. De HLS/MPEG-DASH stream van de griffie-aanbieder embed in de decidiq-vergaderpagina
2. Automatisch een Nederlandstalig transcript genereert met Whisper-NL of de NOTUBIZ ASR-API
3. Sprekers detecteert door koppeling met de microfoonbron uit het zaalsysteem
4. Transcript-segmenten linkt aan agendapunten zodat de tijdlijn navigeerbaar is
5. Het resultaat publiceert met permanent-link-niveau referenties (deeplink naar tijdstempel)

De waarde voor gemeenten zit in drie hoeken:
- **Toegankelijkheid en juridische compliance** (WCAG 2.2 AA + EU Accessibility Act per juni 2025)
- **Transparantie** (burgers en journalisten kunnen snel vinden wat fractie X over onderwerp Y heeft gezegd)
- **Interne efficiency** (griffies kunnen besluitenlijsten sneller produceren uit transcript-segmenten)

## What Changes

**NEW entities in decidiq:**
- `Livestream` — koppelt een Vergadering aan een streaming-bron (provider, streamUrl, status, recordingUrl, DVR-enabled, embargo-control)
- `Transcript` — volledige transcript van één vergadering (language, engine, confidence, status, fullText, VTT-export)
- `TranscriptSegment` — atomair transcript-fragment per spreker-turn (speaker, startTime, endTime, text, confidence, agendapunt-koppeling, flags)
- `Spreker` — sprekers-koppeling per vergadering (rol, fractie, microfoonId, sprektijd)

**Uitbreiding van bestaande entiteiten:**
- `Vergadering` → voegt `livestream`, `transcript`, `transcriptionPolicy`, `archiveRetentionYears` toe

**NEW backend services:**
- Livestream-embedding en HLS/DASH-player integration
- ASR-pipeline (Whisper-NL of NOTUBIZ-adapter) voor automatische transcriptie
- Sprekerherkenning via microfoonkoppeling
- Transcript-segmentering en agendapunt-linking
- WebVTT-export voor closed captions
- Deeplink-generatie (tijdstempel-navigatie)
- Full-text-search index over transcripts
- Archief-retentie-job conform Archiefwet

**MODIFIED:**
- Vergadering-detailpagina toont livestream-player en transcript-viewer
- Besluitenlijst linkt naar transcript met timestamp-navigatie
- Zoekinterface ondersteunt transcript-filtering
- Griffie-correctie-workflow met audit-trail

**Out of scope (toekomstige iteraties):**
- Real-time captions tijdens livestream
- Automatische samenvattingen per agendapunt via LLM
- Vertaling naar Engels of vereenvoudigd Nederlands
- Sentiment-analyse en interruptie-statistieken

## Capabilities

### New Capabilities

**livestream-embedding** — HLS/DASH-player geïntegreerd in vergader-detailpagina met captions-toggle, volume- en speed-control

**automatic-transcription** — Whisper-NL of NOTUBIZ ASR genereert transcript binnen 60 minuten na afloop

**speaker-recognition** — TranscriptSegments gekoppeld aan sprekers via microfoonbron

**transcript-search** — Full-text-search over transcript-content per vergadering en fleet-wide

**deeplink-timestamps** — Permanente URLs naar specifieke momenten in de stream (`/vergadering/{id}?t=02:34:15`)

**closed-captions** — WebVTT-export met WCAG 2.2 AA compliance

**transcript-correction** — Griffie kan segmenten handmatig corrigeren met audit-trail

**archive-retention** — Configureerbare retentie-policies conform Archiefwet

### Modified Capabilities

**vergadering-details** — voegt livestream-player, transcript-viewer en deeplink-generator toe

**decisioning-interface** — linkt besluitenlijst naar transcript met timestamp-navigatie

### Unchanged Capabilities

Alle bestaande decidiq-specs (`p2-meeting-management`, `p2-motion-and-voting`, etc.) blijven ongewijzigd. Livestream/transcript zijn zuiver additieve features.

## Impact

**Code:**
- `lib/Entity/Livestream.php`, `Transcript.php`, `TranscriptSegment.php`, `Spreker.php` (new entities)
- `lib/Service/TranscriptionService.php` (new ASR-pipeline)
- `lib/Service/SpeakerRecognitionService.php` (microphone linking)
- `lib/Service/TranscriptSearchService.php` (full-text search)
- `lib/Controller/LivestreamController.php`, `TranscriptController.php` (new APIs)
- `lib/Migration/` (new database schema migrations)
- `docs/features/livestream-and-transcription.md` (new feature docs)

**Frontend:**
- New Vue components: `LivestreamPlayer.vue`, `TranscriptViewer.vue`, `TranscriptSearch.vue`
- Modified `MeetingDetail.vue` to embed player and transcript
- Modified `DecisionList.vue` to add timestamp links

**Database:**
- 4 new tables: `livestream`, `transcript`, `transcript_segment`, `spreker`
- New indexes on `transcript.vergadering_id`, `transcript_segment.transcript_id`, `transcript_segment.speaker_id`
- Full-text search index on `transcript.fullText`

**Dependencies:**
- Hard dependency on openconnector adapters voor NOTUBIZ/iBabs koppeling
- Optional: Whisper-NL (local) of NOTUBIZ ASR (cloud API)
- Existing: CalDAV storage for meeting lifecycle

**Reused (no changes):**
- `OCA\Decidiq\Service\MeetingService` — meeting CRUD
- `OCA\Decidiq\Service\AgendaService` — agenda items
- `OCA\OpenRegister\Service\ObjectService` — entity queries

## Standards & Sources

- **WCAG 2.2 AA** — Web Content Accessibility Guidelines, captions prerecorded (1.2.2), captions live (1.2.4)
- **WebVTT** — Web Video Text Tracks (W3C standard for HTML5 captions)
- **HLS (RFC 8216)** en **MPEG-DASH (ISO/IEC 23009-1)** — streaming protocols
- **Gemeentewet artikel 23** — openbaarheid raadsvergaderingen
- **Archiefwet 1995** en **Selectielijst gemeenten en intergemeentelijke organen 2020** — retention policies
- **AVG artikel 6 lid 1 sub e** — legal basis for processing speaker personal data
- **Whisper-NL** — OpenAI Whisper fork trained on Dutch parliamentary data
- **W3C Media Fragments URI** (`#t=` syntax) — deeplinking to timestamps

## Cross-app Integration

- **decidiq** (base) — owns `Vergadering`, `Agendapunt`, `Besluit`, `Stemming`. New entities: `Livestream`, `Transcript`, `TranscriptSegment`, `Spreker`
- **openconnector** — provides NOTUBIZ/iBabs adapters; publishes events for livestream and microphone data
- **openregister** — hosts schema definitions; provides full-text search
- **docudesk** — can embed transcript-excerpt in PDF export
- **mydash** — KPIs: transcript count, confidence scores, correction time

## Acceptance Criteria

A change is complete when:

1. All four new entities are defined in OpenRegister schema + migrations exist
2. Livestream player renders HLS/DASH streams in decidiq UI with player controls (play/pause/speed/volume)
3. Transcript is automatically generated within 60 minutes after livestream ends (transcriptionPolicy=auto)
4. TranscriptSegments are linked to Agendapunten (90%+ accuracy)
5. Speakers are recognized and linked to TranscriptSegments via microphone IDs
6. WebVTT file is generated and captions render in the player (WCAG 2.2 AA compliant)
7. Full-text search works across all transcripts with proper result ranking
8. Deeplinks work: `/vergadering/{id}?t=HH:MM:SS` jumps to correct timestamp
9. Griffie can correct TranscriptSegments with audit-trail
10. Retention job deletes transcripts after policy period (default 7 years)
11. All APIs return consistent error envelopes (RFC 7807)
12. Test coverage ≥ 85% on new services
