# Proposal: Meeting transcription and AI-assisted draft minutes

## Why

FEATURE-REEVALUATION-2026-06-11 lists this as EXPECTED-GAP #1: "every competitor in the app's own FEATURES.md analysis (iBabs, Notubiz, Diligent, Fellow) leads with this; FEATURES.md lists it as Enterprise (#67/#68) but no spec or change exists. Should be built on NC Talk recording + the existing OR AI Chat Companion, not an app-local pipeline."

The secretary's biggest time cost in any governance body is turning a 2-hour meeting into minutes. Decidesk already has the surrounding machinery — Talk conversations per meeting (nextcloud-integration), a structured minute-taking template, and a minutes approval workflow (resolution-minutes) — but the hardest mile, from spoken meeting to first draft, is absent from every spec and change. Meanwhile Nextcloud ships exactly the platform abstractions this needs: Talk call recording, the SpeechToText provider interface, and the TaskProcessing/AI provider framework (local Whisper/LLM ExApps — data never leaves the instance, which is precisely the pitch against Diligent/Fellow cloud transcription).

This change specs the missing capability as a thin orchestration over those platform providers, handing off to the existing minutes workflow rather than redefining it.

## What Changes

- **Create the new `meeting-transcription` capability**: attach a meeting recording (a Talk call recording of the meeting's conversation, or an uploaded audio file in the meeting's NC Files folder), record the consent confirmation, transcribe asynchronously through the Nextcloud SpeechToText provider abstraction, and store the transcript as a file in the meeting folder plus a `Transcript` object with timestamped segments.
- **Agenda alignment**: transcript segments are mapped to agenda items using the timeline the meeting conduct already records (agenda item start/`actualDuration`), so the draft is structured per agenda item — the shape the minutes template expects.
- **AI-assisted draft minutes**: generate a structured draft (per-agenda-item summary, detected decisions and action-item suggestions) from the aligned transcript through the Nextcloud TaskProcessing/AI provider abstraction. The output is ALWAYS a draft: it pre-fills the existing resolution-minutes editor, is clearly marked as AI-generated, and goes through the unchanged human review/approval workflow. Nothing AI-generated is ever auto-approved or auto-published.
- **Privacy and retention**: recording requires a chair-confirmed consent step (participants informed, AVG); recordings and raw transcripts are restricted to the governance body, are NEVER eligible for public publication (deny-listed in the public-publication capability's terms), and are deleted per a configurable retention policy once minutes are approved.
- **Graceful degradation**: without a SpeechToText provider the attach/consent flow still works and transcription is reported unavailable; without an AI provider the transcript is still produced and the secretary writes minutes from it manually.

## Capabilities

### New Capabilities

- `meeting-transcription`: recording attachment with consent, provider-based transcription, agenda-aligned transcript segments, AI-assisted draft minutes generation, retention — all via Nextcloud provider abstractions, no app-local STT/LLM pipeline.

### Modified Capabilities

- `resolution-minutes`: ADDED requirement — the minute-taking editor can be initialized from an AI-generated draft with visible provenance, per-section accept/edit/discard, and an unchanged approval workflow.

## Capabilities note

Explicitly NOT a change to the minutes capability's lifecycle: drafting, review, approval, locking, and Docudesk rendering stay exactly as specified in resolution-minutes. This capability ends where the draft enters the editor.

## Impact

- **Schemas**: new `Transcript` schema in `decidesk_register.json` (meeting reference, source recording file reference, provider id, language, status `pending|processing|done|failed`, consent record `{confirmedBy, confirmedAt}`, segments `[{startTime, endTime, speakerLabel, agendaItem?, text}]`, retention state). `hardDelete` honoring the retention policy. One ADR-031 notification rule (transcription finished/failed → secretary).
- **Storage / RBAC / notifications**: all from OpenRegister; recordings and transcript files in the meeting's NC Files folder (Files integration — documents belong in NC Files); access via OR RBAC restricted to body members; notifications declarative only.
- **Backend**: `TranscriptionService` (provider discovery, async job submission via the NC background job framework, segment parsing, agenda alignment) and `MinutesDraftService` (TaskProcessing prompt assembly, draft structure, provenance marking). No bundled models, no external API calls outside the NC provider framework.
- **Frontend**: recording/consent panel on the meeting detail view, transcript view with per-agenda-item segments, "Generate draft minutes" action, draft-review banner in the minutes editor.
- **Dependencies (all optional, feature-gated)**: Talk with call recording, a SpeechToText provider (e.g. Whisper ExApp), a TaskProcessing/AI provider. Hard dependency only on OpenRegister + Files, as today.
- **Out of scope**: live (in-meeting, streaming) transcription and live captions; speaker identification against participant records (segments carry provider speaker labels only); translation of transcripts; transcription of arbitrary non-meeting audio; publishing transcripts (never public).
