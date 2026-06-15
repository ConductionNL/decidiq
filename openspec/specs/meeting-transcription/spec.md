# meeting-transcription Specification

## Purpose
TBD - created by archiving change meeting-transcription-ai-minutes. Update Purpose after archive.
## Requirements
### Requirement: Recording attachment with consent precondition

The system SHALL let the secretary or chair attach a transcription source to a meeting: the Talk call recording of the meeting's conversation, or an audio file from the meeting's Nextcloud Files folder. Before any transcription job is accepted, a consent confirmation SHALL be recorded on the `Transcript` object (`confirmedBy`, `confirmedAt` — the chair or secretary confirms participants were informed of the recording). The source SHALL remain a file reference into the meeting folder; audio content SHALL NOT be stored inside OpenRegister objects. Without a consent record the transcription request SHALL be refused.

#### Scenario: Attach an uploaded recording with consent

- **GIVEN** a secretary on the detail view of a concluded meeting with an audio file in its Files folder
- **WHEN** they attach the file as transcription source and confirm the consent statement
- **THEN** a `Transcript` object is created in status `pending` with the file reference and the consent record (`confirmedBy`, `confirmedAt`)

#### Scenario: Transcription refused without consent

- **GIVEN** an attach flow where the consent confirmation is not given
- **WHEN** transcription is requested
- **THEN** the request is refused with a consent-required error and no transcription job is submitted

#### Scenario: Talk recording offered as source

@e2e exclude Talk-recording discovery — depends on a Talk call recording fixture; covered by PHPUnit on the source resolver with a mocked Talk file
- **GIVEN** a meeting whose Talk conversation has a call recording
- **WHEN** the secretary opens the attach flow
- **THEN** the Talk recording is offered as a selectable source alongside files from the meeting folder

---

### Requirement: Asynchronous transcription via the SpeechToText provider abstraction

The system SHALL transcribe attached recordings through the Nextcloud SpeechToText provider abstraction, asynchronously via the Nextcloud background-job framework — never an app-local STT implementation or a direct external API call. The `Transcript.status` lifecycle SHALL be `pending → processing → done | failed`. The resulting transcript SHALL be stored as a text file in the meeting's Files folder and as timestamped segments (`startTime`, `endTime`, `speakerLabel`, `text`) on the `Transcript` object; speaker labels SHALL be the provider's neutral labels and SHALL NOT be mapped to participant identities. A declarative ADR-031 notification rule SHALL notify the secretary on completion or failure. When no SpeechToText provider is available, the attach/consent flow SHALL still function and transcription SHALL be reported unavailable.

#### Scenario: Transcription completes and notifies

- **GIVEN** a `Transcript` in status `pending` with a valid source and consent, and a SpeechToText provider available
- **WHEN** the transcription job completes
- **THEN** the status is `done`, timestamped segments are stored, a transcript text file exists in the meeting folder, and the secretary receives the ADR-031 completion notification

#### Scenario: Provider failure is a first-class state

@e2e exclude provider-failure branch — covered by PHPUnit with a failing provider
- **GIVEN** a transcription job whose provider errors
- **WHEN** the job finishes
- **THEN** the `Transcript` status is `failed` with the failure reason stored, the secretary is notified, and the job can be re-requested

#### Scenario: No provider degrades gracefully

- **GIVEN** an instance without any SpeechToText provider
- **WHEN** the secretary opens the transcription panel
- **THEN** attaching a source and recording consent still work, and the transcribe action is shown as unavailable with an explanation instead of failing

#### Scenario: Speaker labels stay neutral

@e2e exclude data-shape assertion — covered by PHPUnit on segment parsing
- **WHEN** segments are stored from any provider result
- **THEN** `speakerLabel` values are neutral provider labels (e.g. "Speaker 1") and no participant identity mapping is stored

---

### Requirement: Agenda alignment of transcript segments

The system SHALL map transcript segments to agenda items by joining segment timestamps against the meeting-conduct timeline (agenda item start times and `actualDuration` recorded during the meeting). Segments outside any agenda item's window SHALL remain unassigned — never guessed. Alignment SHALL be a re-runnable derivation over stored data: correcting the timeline re-aligns segments without re-transcribing. When no conduct timeline exists, the transcript SHALL remain flat and downstream draft generation SHALL fall back to whole-meeting summarization.

#### Scenario: Segments grouped per agenda item

- **GIVEN** a `done` transcript for a meeting whose conduct recorded agenda item timings
- **WHEN** the secretary opens the transcript view
- **THEN** segments are grouped under the agenda items whose time windows contain them, and out-of-window segments appear in an "unassigned" group

#### Scenario: Timeline correction re-aligns without re-transcribing

@e2e exclude re-derivation contract — covered by PHPUnit on the alignment function
- **GIVEN** an aligned transcript and a corrected agenda item start time
- **WHEN** alignment is re-run
- **THEN** segment grouping reflects the corrected timeline and the transcription job is not re-executed

---

### Requirement: AI-assisted draft minutes generation

The system SHALL generate a structured draft from an aligned transcript through the Nextcloud TaskProcessing/AI provider abstraction: a per-agenda-item summary plus suggested decisions and action items. The draft SHALL be written into the minute-taking template structure for the existing resolution-minutes editor — NEVER directly into a `Minutes` object with lifecycle standing, and NEVER auto-approved or auto-published. Every generated section SHALL carry provenance (`aiGenerated: true`, provider id, generated-at). Suggested decisions and action items SHALL be cross-checked against the structured meeting record: suggestions matching a recorded voting outcome or decision SHALL link to it; unmatched suggestions SHALL be flagged as unverified. When no AI provider is available, the generate action SHALL be hidden and the transcript SHALL remain available as reference material.

#### Scenario: Generate a draft from the transcript

- **GIVEN** an aligned `done` transcript and an available AI provider
- **WHEN** the secretary triggers "Generate draft minutes"
- **THEN** a draft in the minute-taking template structure is produced with per-agenda-item summaries and provenance on every generated section, and no `Minutes` object is approved or published by the action

#### Scenario: Unverified suggestion flagged

- **GIVEN** a generated draft containing a suggested decision that matches no recorded voting outcome
- **WHEN** the secretary reviews the draft
- **THEN** that suggestion is visibly flagged as unverified, while a suggestion matching a recorded outcome links to the structured record

#### Scenario: No AI provider hides generation

- **GIVEN** an instance with a SpeechToText provider but no TaskProcessing/AI provider
- **WHEN** the secretary views a `done` transcript
- **THEN** the "Generate draft minutes" action is not offered and the transcript view remains fully usable

---

### Requirement: Confidentiality and retention of recordings and transcripts

Access to `Transcript` objects and their files SHALL be restricted to the governance body's members via OpenRegister RBAC and the meeting folder's Files access. Recordings and transcripts SHALL be permanently ineligible for public publication — included in the public-publication structural deny-list; the approved minutes are the only public record of a meeting. A per-body retention policy (`keep`, `delete-recording`, `delete-both`; default `delete-both` 30 days after minutes approval) SHALL be enforced by a scheduled background job, and each retention deletion SHALL be recorded in the meeting's audit trail.

#### Scenario: Non-member access denied

@e2e exclude RBAC contract — covered by Newman IDOR suite
- **WHEN** an authenticated user who is not a member of the governance body requests a `Transcript` object or its file
- **THEN** access is denied with HTTP 403/404 by OpenRegister RBAC and Files permissions

#### Scenario: Transcript publication structurally refused

@e2e exclude deny-list contract — covered by PHPUnit on the publication payload service
- **WHEN** a publish request targets a `Transcript` object or a recording file
- **THEN** payload construction is refused as not-publishable regardless of status or actor

#### Scenario: Retention job deletes after approval

@e2e exclude scheduled background job — verified at the PHPUnit layer by invoking the job class directly
- **GIVEN** a body with the default retention policy and minutes approved more than 30 days ago
- **WHEN** the retention job runs
- **THEN** the recording and raw transcript files are deleted, the `Transcript` object reflects the retention state, and the deletion is recorded in the meeting's audit trail

