# Tasks: Meeting transcription and AI-assisted draft minutes

## 1. Schema (decidesk_register.json)
- [ ] 1.1 Add `Transcript` schema (meeting reference, source file reference, source type `talk-recording|uploaded-file`, provider id, language, status `pending|processing|done|failed` + failure reason, consent record `{confirmedBy, confirmedAt}`, segments `[{startTime, endTime, speakerLabel, agendaItem?, text}]`, retention state, draft-generation provenance; `hardDelete: false`) — no audio/content blobs in the object, file references only.
- [ ] 1.2 Add ADR-031 `x-openregister-notifications` rules: transcription done → secretary; transcription failed → secretary.
- [ ] 1.3 Add the per-body retention policy fields to the body/admin configuration (policy `keep|delete-recording|delete-both`, days-after-approval; default `delete-both`/30).
- [ ] 1.4 Verify the dialect gate passes after the edits.

## 2. Transcription backend (lib/)
- [ ] 2.1 Source resolution: list candidate sources for a meeting (Talk call recording of the meeting's conversation via the Talk integration; audio files in the meeting's Files folder). Graceful absence of Talk/recording.
- [ ] 2.2 Consent precondition: attach endpoint records `{confirmedBy, confirmedAt}`; transcription submission refuses without a consent record (server-side, not a UI-only check).
- [ ] 2.3 `TranscriptionService`: SpeechToText provider discovery via the NC manager (provider absence = first-class unavailable state); async job submission via the NC background-job framework, registered in `Application::register()` boot context (the valid IBootstrap path — NOT the invalid `registerJob` pattern that previously left decidesk jobs unregistered); status lifecycle `pending→processing→done|failed`; segment parsing with neutral speaker labels (no participant identity mapping); transcript text file written to the meeting folder.
- [ ] 2.4 Agenda alignment as a pure re-runnable derivation joining segment timestamps to the conduct timeline (agenda item start + `actualDuration`); unassigned group for out-of-window segments; flat-transcript fallback when no timeline exists.
- [ ] 2.5 Retention scheduled job: enforce the per-body policy after minutes approval (delete files per policy, update `Transcript` retention state, audit-trail entry on the meeting); same valid job-registration path as 2.3.
- [ ] 2.6 Routes for attach/consent, transcribe, re-align, generate-draft, and retention-config ONLY (staff RBAC guards per method — no-admin-idor gate); plain CRUD stays on the OR object API per ADR-022 (redundant-controller gate).
- [ ] 2.7 Extend the public-publication structural deny-list with `Transcript` and recording files (coordinate with publish-decisions-via-opencatalogi task 2.2 if both land — the deny-list lives in one place).

## 3. Draft generation backend (lib/)
- [ ] 3.1 `MinutesDraftService`: TaskProcessing/AI provider discovery (absence hides the action); per-agenda-item prompt assembly (item title + aligned segments + recorded votes/decisions); whole-meeting fallback for flat transcripts.
- [ ] 3.2 Draft output written into the minute-taking template structure (the resolution-minutes pre-population shape) with provenance (`aiGenerated: true`, provider id, generated-at) per section — never into a lifecycle-bearing `Minutes` object, never auto-approve/publish.
- [ ] 3.3 Suggestion cross-check: decisions/action items matched against recorded voting outcomes and decisions (link on match, `unverified` flag on no match).

## 4. Frontend
- [ ] 4.1 Transcription panel on the meeting detail view: source picker (Talk recording / meeting-folder file), consent confirmation dialog (in `src/modals/`, modal-isolation gate), status display per lifecycle state, unavailable-provider messaging.
- [ ] 4.2 Transcript view: segments grouped per agenda item + unassigned group; re-align action after timeline corrections.
- [ ] 4.3 "Generate draft minutes" action (hidden without an AI provider) and the minutes-editor integration: start-from-draft choice, provenance banner, per-section AI markers with accept/edit/discard, unverified-suggestion flags. Approval flow untouched.
- [ ] 4.4 Admin/body settings for the retention policy via IInitialState/loadState (NC settings framework, NOT vue-router — admin-router gate); NcSelect with `inputLabel`; i18n keys in English source, nl + en translations.

## 5. Tests + verification
- [ ] 5.1 PHPUnit: consent refusal, provider-absent states (STT and AI separately), status lifecycle incl. failure branch, segment parsing + neutral labels, alignment matrix (in-window, out-of-window, corrected timeline re-run, no-timeline fallback), suggestion cross-check (match links, no-match flags), retention job per policy, publication deny-list refusal.
- [ ] 5.2 Newman (`tests/integration/`): consent-required 4xx contract, transcript RBAC/IDOR (non-member 403/404), endpoint auth posture, no unauthenticated access to transcript data.
- [ ] 5.3 Playwright (UI only, per the Playwright-UI/Newman-API split): attach an uploaded recording with consent; provider-unavailable messaging; transcript view grouped by agenda item; generate draft and see banner + per-section markers; discard one section; approve AI-initialized minutes through the normal workflow. Annotate for gate-19 (backend/API excludes already inline in the spec deltas). Use a stub/mock provider fixture for deterministic e2e.
- [ ] 5.4 Run hydra gates (notification-dialect, no-admin-idor, redundant-controller, route-auth/reachability, stub-scan, spec-coverage with `@spec` tags, e2e-coverage) and `composer check:strict`; fix anything pre-existing the touched files surface.
- [ ] 5.5 Live verify against the dev container with the Whisper/STT ExApp if available (otherwise the mocked provider path) — full chain: upload → consent → transcribe → align → generate → edit → approve; bump `appinfo/info.xml` version (immutable-cache bust).

## 6. Docs + follow-ups
- [ ] 6.1 Update docs/intro + feature docs: the sovereign transcription story (on-instance providers, data never leaves), consent + retention posture, draft-only AI stance.
- [ ] 6.2 File follow-up issues: live/streaming transcription + captions; optional speaker-to-participant mapping behind explicit per-participant consent; transcript translation; MCP tool exposing "summarize this meeting's transcript" via the existing AI Chat Companion.
