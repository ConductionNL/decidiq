# Design: Meeting transcription and AI-assisted draft minutes

## Context

The minutes pipeline in decidesk has a specified middle and end (structured minute-taking editor, review/approval workflow, Docudesk rendering — all in resolution-minutes) but no specified beginning: the path from the spoken meeting to a first structured draft. Every competitor leads with that path. Nextcloud provides the platform pieces — Talk call recording, `OCP\SpeechToText` providers, the TaskProcessing/AI provider framework with local ExApp backends — and decidesk's market position (sovereign, on-premise, AVG-clean) demands using them instead of a cloud transcription API.

The capability is therefore orchestration, not ML: route audio to a provider, structure the result along the agenda, hand a draft to the existing editor.

## Goals / Non-goals

- **Goal:** recording → transcript → agenda-aligned draft minutes, entirely through NC provider abstractions, with humans owning everything after the draft.
- **Goal:** an honest consent/retention posture — this is the feature most likely to create AVG exposure if specced casually.
- **Non-goal:** live/streaming transcription or captions during the meeting (V2 candidate; different Talk API surface).
- **Non-goal:** speaker identification (mapping provider speaker labels to participant identities) — high privacy cost, low minute-drafting value; segments keep neutral labels ("Speaker 1").
- **Non-goal:** any change to the minutes approval workflow, and any public exposure of recordings/transcripts.

## Decisions

### D1 — Sources: Talk recording or uploaded file, both via NC Files

A transcription source is either the Talk call recording of the meeting's own conversation (Talk stores recordings as files; nextcloud-integration already creates the conversation per meeting) or an audio file uploaded into the meeting's Files folder (covers physical meetings recorded on a dictaphone — the majority of council/board meetings). Either way the source of record is a file in the meeting folder — documents live in NC Files, never as blobs in OR objects. The `Transcript` object stores a file reference, not content.

### D2 — Consent is a recorded precondition, not a checkbox

Recording a governance meeting processes personal data of everyone in the room. The attach flow requires the chair (or secretary on the chair's behalf) to confirm that participants were informed, and stores `{confirmedBy, confirmedAt}` on the `Transcript` object before any transcription job is accepted. No consent record → the service refuses the job. This is a workflow guarantee decidesk can make cheaply and auditors ask for; per-participant consent collection is body policy and out of scope.

### D3 — Transcription through `OCP\SpeechToText`, async, status-tracked

`TranscriptionService` discovers the available SpeechToText provider through the NC manager; absence is a first-class state (feature reported unavailable, attach/consent still usable). Jobs run async through the NC background-job/provider framework — meeting audio is hours long; nothing blocks a request. The `Transcript.status` lifecycle is `pending → processing → done | failed`, with an ADR-031 rule notifying the secretary on completion or failure. Background jobs are registered via the `Application::register()` boot context (the valid IBootstrap path — decidesk has been burned by the invalid `registerJob` pattern before).

### D4 — Agenda alignment from the conduct timeline

Meeting conduct already records when agenda items are opened (agenda-management real-time tracking, `actualDuration`). Segment timestamps are joined against that timeline to tag each segment with an agenda item; segments outside any item window stay unassigned rather than guessed. Alignment is a pure, re-runnable function over stored data (timeline corrections re-align without re-transcribing). When no timeline exists (recording of a meeting conducted outside decidesk), the transcript stays flat and the draft generator falls back to whole-meeting summarization with agenda items listed as headings only.

### D5 — Draft generation through TaskProcessing, draft-only by construction

`MinutesDraftService` assembles per-agenda-item prompts (item title + aligned segments + recorded votes/decisions from the structured data) and requests summaries plus decision/action-item suggestions through the NC TaskProcessing/AI provider abstraction. Hard rules:

- The output object is a **draft for the existing editor** — the service writes into the minute-taking template structure (the resolution-minutes pre-population shape), never directly into a `Minutes` object with lifecycle standing.
- Every AI-generated section carries provenance (`aiGenerated: true`, provider id, generation time) rendered as a visible banner + per-section markers in the editor; secretary accept/edit/discard per section (delta on resolution-minutes).
- Detected "decisions" and "action items" are **suggestions** cross-checked against the structured record: a suggested decision that matches a recorded voting outcome links to it; one that matches nothing is flagged as unverified, not silently inserted as fact.
- No provider → the action is hidden and the transcript alone is the secretary's source.

### D6 — Retention and confidentiality

Recordings and transcripts are the most sensitive artifacts decidesk will hold (verbatim deliberation). Posture:

- OR RBAC restricts `Transcript` objects to the governance body's members; the files inherit the meeting folder's access.
- Both are **permanently ineligible for public publication** — added to the public-publication structural deny-list terms (the approved minutes are the public record; the verbatim recording never is).
- A per-body retention policy (e.g. delete recording + raw transcript N days after minutes approval; keep | delete-recording-only | delete-both) is enforced by a scheduled job; deletion is recorded in the meeting's audit trail. Default: delete both 30 days after minutes approval.

### D7 — Storage, RBAC, notifications: OpenRegister + NC Files only

`Transcript` objects in the decidesk register; declarative ADR-031 notification rules only (transcription done/failed); audio and transcript text files in NC Files; no app-local tables, no imperative dispatch, no app-local AI/STT pipeline or external API client.

## Risks

- **Provider quality variance (Dutch council audio, dialect, crosstalk).** Mitigation: draft-only posture with mandatory human review; unverified-suggestion flagging; the feature degrades to "transcript as reference material" without harm.
- **Hallucinated decisions in drafts.** Mitigation: D5's cross-check against structured voting/decision records; unverified suggestions visually flagged; approval workflow unchanged.
- **AVG exposure from retained recordings.** Mitigation: consent precondition, body-scoped access, deny-listed from publication, default-on retention deletion, audit-trailed deletion.
- **Long-job resource pressure on small instances.** Mitigation: NC background-job scheduling (provider frameworks queue their own work); status lifecycle keeps the UI honest about pending work.
- **Talk recording availability differs per install.** Mitigation: upload-a-file path is co-equal (D1), so the capability works without Talk recording entirely.
