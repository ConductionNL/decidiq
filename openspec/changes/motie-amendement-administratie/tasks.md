# Tasks — Decidesk Motie en Amendement Administratie

> Scope reminder: this change implements Motie, Amendement, Stemresultaat, and
> UitvoeringsUpdate schemas on OpenRegister, plus full UI/API for motion and
> amendment administration from submission to execution tracking. See `proposal.md`,
> `design.md`, and `specs/motie-amendement-administratie/spec.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass.
> Do not mark tasks done by inspection — run the listed commands.

## 1. OpenRegister Schema Registration (Dependency: OR with Motie/Amendement/Stemresultaat/UitvoeringsUpdate schemas)

- [ ] 1.1 Verify that openregister has released Motie, Amendement, Stemresultaat, UitvoeringsUpdate schema definitions with all required fields per REQ-MOT-001 through REQ-MOT-004.
  **Acceptance:** `openspec ls --app openregister` shows four new schema ids; each has all required fields in the schema JSON

- [ ] 1.2 Add a test fixture file `tests/Fixtures/OpenRegisterSchemas.php` that stubs the four schemas (ids, field lists) for unit tests in CI environments where the full openregister runtime may not be available.
  **Acceptance:** `php -l tests/Fixtures/OpenRegisterSchemas.php` is clean; unit tests can import and use the fixture.

---

## 2. Data Models — Motion, Amendment, VoteResult, ExecutionUpdate

- [ ] 2.1 Create `lib/Db/Motion.php` (Doctrine ORM entity mapping to Motion schema). Fields: id, uuid, title, proposer_id, proposer_party_id, co_signers (JSON), preamble, dispositif, meeting_id, agenda_item_id, motie_status, voting_type, execution_status, execution_deadline, portfolio_holder_id, submitted_at, published_at, created_at, updated_at.
  **Acceptance:** class loads without errors; `php -l` is clean; doctrine:schema:validate finds no errors.

- [ ] 2.2 Create `lib/Db/Amendment.php` (Doctrine ORM entity). Fields: id, uuid, title, proposer_id, proposer_party_id, co_signers (JSON), proposal_id, original_text, modified_text, rationale, amendement_status, voting_type, submitted_at, created_at, updated_at.
  **Acceptance:** class loads; doctrine validation passes.

- [ ] 2.3 Create `lib/Db/VoteResult.php`. Fields: id, uuid, motie_or_amendement_id, motie_or_amendement_type (ENUM: "motie" | "amendement"), raadslid_id, fractie_id, fractie_name_snapshot, vote, voting_explanation (nullable), voted_at. All timestamp/party fields immutable (no setters).
  **Acceptance:** fractie_id and fractie_name_snapshot have no public setters; unit test asserts immutability.

- [ ] 2.4 Create `lib/Db/ExecutionUpdate.php`. Fields: id, uuid, motie_id, status_change, explanation, attachments (JSON), updated_by_id, updated_at, created_at.
  **Acceptance:** class loads; timestamps immutable.

---

## 3. Database Migrations

- [ ] 3.1 Create migration `migrations/Version20240614000001CreateMotionTable.php` creating the motions table with all fields from 2.1. Ensure:
  - Primary key: uuid
  - Foreign keys: meeting_id → meetings.uuid, agenda_item_id → agenda_items.uuid, proposer_id → persons.id, portfolio_holder_id → persons.id, proposer_party_id → fracties.id
  - Indexes: meeting_id, motie_status, execution_status, submitted_at DESC
  **Acceptance:** `php app/console doctrine:migrations:migrate` executes without error; `php app/console doctrine:schema:validate` returns 0.

- [ ] 3.2 Create migration for amendments table (similar structure, foreign key to proposals.uuid).
  **Acceptance:** migration executes; schema valid.

- [ ] 3.3 Create migration for vote_results table with composite unique index (motie_or_amendement_id, raadslid_id) to prevent duplicate votes.
  **Acceptance:** migration executes; schema valid; unit test confirms constraint.

- [ ] 3.4 Create migration for execution_updates table.
  **Acceptance:** migration executes; schema valid.

---

## 4. Services — Business Logic Layer

- [ ] 4.1 Create `lib/Service/MotionService.php` with methods:
  - `createMotion(array $data): Motion` — validate fields per REQ-MOT-001, set motie_status=ingediend, assign M-{year}-{seq} id, persist.
  - `updateMotionStatus(string $motionId, string $newStatus): void` — validate state transition, update motie_status.
  - `getMotion(string $uuid): ?Motion`
  - `listMotions(array $filters): array` — support filters: meeting_id, motie_status, execution_status, year, party_id.
  - `rescheduleMotion(string $motionId, string $nextMeetingId): void` — for aangehouden motions.
  **Acceptance:** unit tests for each method pass; state transitions reject invalid transitions (e.g., verworpen → aangenomen); id generation is unique and formatted correctly.

- [ ] 4.2 Create `lib/Service/AmendmentService.php` with:
  - `createAmendment(array $data): Amendment` — validate original_text matches proposal text (REQ-AMD-001), set amendement_status=ingediend, assign A-{year}-{seq} id.
  - `generateDiff(string $amendmentId): array` — return side-by-side original vs. modified.
  - `updateAmendmentStatus(string $amendmentId, string $newStatus): void`
  - `getAmendment(string $uuid): ?Amendment`
  - `listAmendments(array $filters): array`
  **Acceptance:** unit test asserts original_text validation rejects mismatches; diff generation is symmetric; amendment id is unique and formatted.

- [ ] 4.3 Create `lib/Service/VotingService.php` with:
  - `recordVote(string $motieOrAmendementId, string $raadsleidId, string $voteValue, ?string $explanation): VoteResult` — snapshot fractie at creation time per REQ-MOT-003. Immutable fields locked.
  - `getVoteResult(string $uuid): ?VoteResult`
  - `getVotingMatrix(string $motieOrAmendementId): array` — return voting summary (counts + breakdown by party if head-to-head).
  - `hasVoted(string $motieOrAmendementId, string $raadsleidId): bool`
  **Acceptance:** unit test asserts fractie_id is copied from raadslid-fractie-history at vote time, never updated after; immutable fields have no setters.

- [ ] 4.4 Create `lib/Service/ExecutionService.php` with:
  - `addExecutionUpdate(string $motionId, array $updateData): ExecutionUpdate` — validate fields, create update, sync latest status to Motion.execution_status.
  - `getExecutionTimeline(string $motionId): array` — return all ExecutionUpdates ordered by updated_at DESC.
  - `getMotionsNeedingUpdates(int $daysSilence = 90): array` — query motions with latest ExecutionUpdate > N days old.
  **Acceptance:** unit tests assert status sync happens; getMotionsNeedingUpdates returns correct set; date comparison is correct (>90 days, not >=).

---

## 5. Controllers — HTTP API Layer

- [ ] 5.1 Create `lib/Controller/MotionController.php` with endpoints:
  - `POST /api/motions` — create motion (REQ-MOT-001), require authenticated user + party membership.
  - `GET /api/motions/{uuid}` — fetch single motion.
  - `GET /api/motions` — list motions with filters (status, year, party). Paginate 20/page.
  - `PATCH /api/motions/{uuid}` — update motie_status, portfolio_holder_id (griffier-only for status, alderman for portfolio_holder).
  - `POST /api/motions/{uuid}/publish` — publish to public portal (griffier-only).
  - `POST /api/motions/{uuid}/reschedule` — for aangehouden motions (griffier-only).
  - `GET /api/motions/search` — full-text search with filters (REQ-MOT-006).
  **Acceptance:** integration tests for each endpoint; auth checks pass; filtering works.

- [ ] 5.2 Create `lib/Controller/AmendmentController.php`:
  - `POST /api/amendments` — create (REQ-AMD-001, validation of original_text).
  - `GET /api/amendments/{uuid}`
  - `GET /api/amendments?proposal_id=...` — filter by proposal.
  - `GET /api/amendments/{uuid}/diff` — side-by-side view.
  - `PATCH /api/amendments/{uuid}` — update status.
  **Acceptance:** unit test asserts original_text validation rejects mismatches; diff is correct.

- [ ] 5.3 Create `lib/Controller/VotingController.php`:
  - `POST /api/motions/{motionId}/votes` — record a single vote (REQ-STEM-001, REQ-STEM-002, REQ-STEM-003). Body: {raadsleid_id, vote, explanation?}.
  - `GET /api/motions/{motionId}/votes` — get all votes for a motion (voting matrix data).
  - `POST /api/motions/{motionId}/lock-votes` — griffier-only, lock the voting round.
  - `GET /api/motions/{motionId}/voting-matrix` — present voting UI data (REQ-STEM-001).
  **Acceptance:** unit tests for auth, voting matrix UI data structure, absence pre-population, divergence detection.

- [ ] 5.4 Create `lib/Controller/ExecutionController.php`:
  - `POST /api/motions/{motionId}/executions` — add ExecutionUpdate (REQ-EXEC-001, REQ-EXEC-002, REQ-EXEC-003).
  - `GET /api/motions/{motionId}/executions` — timeline (REQ-MOT-004).
  - `GET /api/motions/dashboard` — portfolio holder dashboard (REQ-EXEC-001). Auth: logged-in alderman.
  - `GET /api/motions/needing-updates` — motions >90 days silent (REQ-EXEC-002).
  **Acceptance:** integration tests; filtering and sorting work; overdue highlighting correct.

- [ ] 5.5 Create `lib/Controller/ReportController.php`:
  - `POST /api/reports/endofterm` — trigger end-of-term report job (REQ-MOT-008, griffier-only). Returns job id.
  - `GET /api/reports/endofterm/{jobId}` — poll job status; return download link on completion.
  **Acceptance:** background job queues; async generation works; PDF downloadable.

---

## 6. Background Jobs

- [ ] 6.1 Create `lib/Jobs/ReminderJob.php` (REQ-EXEC-002). Runs daily via Nextcloud's background job queue. Queries motions with >90 days silence, sends email to portfolio_holder_id. Log each reminder in audit trail.
  **Acceptance:** job executes without error; email(s) sent; audit trail recorded.

- [ ] 6.2 Create `lib/Jobs/PublishMotionJob.php`. On motion status change to aangenomen|verworpen, queue this job. Generate public page at `/griffie/moties/{M-year-seq}` with all REQ-MOT-007 requirements (WCAG AA, OWMS metadata).
  **Acceptance:** public page renders; WCAG AA check passes (axe or similar); OWMS metadata present in HTML.

- [ ] 6.3 Create `lib/Jobs/EndOfTermReportJob.php`. Async job generating end-of-term PDF (REQ-MOT-008). On completion, email griffier with link. Handle failures gracefully (retry).
  **Acceptance:** job completes; PDF is valid and contains all required sections; email sent with download link.

---

## 7. Frontend — Vue Components and Views

- [ ] 7.1 Create `resources/js/views/MotionList.vue`. Displays table of motions with columns: title, proposers, status, execution_status, deadline. Sortable, filterable (status, year, party). Pagination 20/page. Links to detail view.
  **Acceptance:** component renders; filters work; sorting works; links navigate correctly.

- [ ] 7.2 Create `resources/js/views/MotionDetail.vue`. Shows full motion (preamble, dispositif, proposers, co-signers), voting results (VotingMatrix), execution timeline, actions (add execution update, reschedule, publish). Conditional rendering based on user role (raadslid, griffier, alderman).
  **Acceptance:** all sections render; buttons respect auth; data loads correctly.

- [ ] 7.3 Create `resources/js/views/MotionCreate.vue`. Form to submit new motion (title, preamble, dispositif, meeting, agenda item, co-signers). Validation. On submit, calls `POST /api/motions`.
  **Acceptance:** form renders; validation works; submit succeeds; motion appears in list.

- [ ] 7.4 Create `resources/js/components/VotingMatrix.vue` (REQ-STEM-001). Grid of councilors × vote buttons (voor/tegen/onthouden). Real-time tally. Touch-friendly. "Lock vote" button (griffier-only). Absence pre-population from meeting attendance.
  **Acceptance:** component renders; voting updates tally; lock works; absence handling correct.

- [ ] 7.5 Create `resources/js/views/AmendmentList.vue`. Table of amendments filtered by proposal or meeting. Links to detail.
  **Acceptance:** renders; filters work; links navigate.

- [ ] 7.6 Create `resources/js/views/AmendmentCreate.vue`. Form to submit amendment: proposal, original_text (with copy-paste helper from proposal text), modified_text, rationale. On submit, calls `POST /api/amendments` and validates original_text match server-side.
  **Acceptance:** form renders; original_text validation displays error on mismatch; submit succeeds on valid input.

- [ ] 7.7 Create `resources/js/views/AmendmentDetail.vue`. Shows amendment, diff view (side-by-side), voting results, status, actions.
  **Acceptance:** diff renders correctly; data loads; actions available.

- [ ] 7.8 Create `resources/js/views/ExecutionTimeline.vue`. Timeline of all ExecutionUpdates for a motion. Each entry shows date, status, explanation, attachments. "Add Update" button. Overdue warning if >90 days silent.
  **Acceptance:** timeline renders; entries ordered by date DESC; add-update button works; overdue highlighted.

- [ ] 7.9 Create `resources/js/views/PortfolioHolderDashboard.vue` (REQ-EXEC-001). Dashboard filtered to current user's assigned motions. Grouped by execution_status. Sorted by deadline. Red badges for >90 days silent. Quick-add ExecutionUpdate form.
  **Acceptance:** dashboard renders; grouping/sorting correct; overdue badge appears; quick-add form works.

- [ ] 7.10 Create `resources/js/views/MotionSearch.vue` (REQ-MOT-006). Full-text search + advanced filters (year, party, portfolio_holder, vote history). Results paginated, sorted by relevance then date DESC. Links to motion detail.
  **Acceptance:** search executes; filters apply; results correct; cross-term search works.

---

## 8. Public Portal Pages

- [ ] 8.1 Create `resources/views/public/motie-detail.html.twig` (REQ-MOT-007). Template for `/griffie/moties/{M-year-seq}`. Displays:
  - Motion title, proposers, co-signers
  - Full preamble and dispositif
  - Vote tally (counts by vote value, optionally by party if hoofdelijk)
  - Vote breakdown table (if public voting is configured): councilor name | party | vote | explanation (if published)
  - Current execution_status and latest ExecutionUpdate summary
  - Amendment links (if any)
  - Metadata footer (published date, classification, license)
  **Acceptance:** page renders; WCAG AA check passes; OWMS metadata present.

- [ ] 8.2 Create route `GET /griffie/moties/{motionId}` in `lib/Controller/PublicMotionController.php`. Fetch motion, check status (must be aangenomen or verworpen for public access), render template. Include OWMS/schema.org metadata headers.
  **Acceptance:** route works; public access granted only for adopted/rejected motions; metadata headers present.

- [ ] 8.3 Create Atom/RSS feed `/griffie/moties/feed?portfolio_holder=...` (REQ-MOT-007). Entries: adopted motions, ordered by published_at DESC. Per-entry: title, link, publication date, summary.
  **Acceptance:** feed renders; entries correct; subscribe URL works in feed reader.

---

## 9. Search and Filtering

- [ ] 9.1 Extend `lib/Service/MotionService.php::listMotions()` to support full-text search on title, preamble, dispositif, execution explanations. Use OpenRegister's FTS index if available; fallback to LIKE queries.
  **Acceptance:** search on keyword returns relevant motions; performance acceptable (<1s for typical result set).

- [ ] 9.2 Add filter support for motions by: year (range), motie_status, execution_status, party_id (proposer or via vote history), portfolio_holder_id. Filters compose with AND logic.
  **Acceptance:** each filter tested; complex queries (e.g., "year 2024 AND status=aangenomen AND party=vvd") return correct subset.

- [ ] 9.3 Add vote history query: `listMotionsVotedByCouncilor(string $raadsleidId, ?string $voteValue = null): array` — return all motions where raadsleid voted, optionally filtering by vote value (voor/tegen/onthouden).
  **Acceptance:** query correct; fractie_name_snapshot reflects party at vote time.

---

## 10. Amendment Adoption Integration

- [ ] 10.1 Extend `lib/Service/AmendmentService.php::updateAmendmentStatus()` to handle status = aangenomen. When an amendment is adopted:
  - Fetch the linked Proposal.
  - Create a new Proposal version with modified_text substituted for original_text.
  - Link the amendment to the new proposal version.
  - Update the proposal's change log.
  **Acceptance:** unit test asserts new version created; change log entry recorded.

- [ ] 10.2 Add endpoint `POST /api/amendments/{amendmentId}/adopt` (griffier-only). On success, amendment status becomes aangenomen, and integration (10.1) runs.
  **Acceptance:** endpoint works; integration succeeds; proposal version updated.

---

## 11. "Motie-bingo" Detection and Warnings

- [ ] 11.1 Extend `lib/Service/MotionService.php::updateMotionStatus()` to detect overgenomen-door-college WITHOUT a concurrent ExecutionUpdate. If transitioning to overgenomen-zonder-concrete-actie, set a flag `has_vague_takeover = true`.
  **Acceptance:** flag set correctly when overgenomen without ExecutionUpdate; cleared when ExecutionUpdate added.

- [ ] 11.2 Add UI warning on motion detail (REQ-MOT-005): if `has_vague_takeover = true`, display yellow warning "Motion taken over without concrete action plan. Add an ExecutionUpdate."
  **Acceptance:** warning renders on detail view; clears when ExecutionUpdate added.

- [ ] 11.3 Create vague-takeover list view `/motions/vague-takeovers`. Displays all motions with `has_vague_takeover = true`. Filtered by year, party, portfolio_holder. Quick-add ExecutionUpdate button.
  **Acceptance:** view renders; list correct; quick-add works.

---

## 12. 90-Day Execution Reminder System

- [ ] 12.1 Create `lib/Jobs/ReminderJob.php` (see 6.1). Runs daily. Query motions with latest ExecutionUpdate.updated_at < now() - 90 days. Email portfolio_holder_id + record in audit trail.
  **Acceptance:** job executes; emails sent; audit trail entries created.

- [ ] 12.2 Add UI badge on dashboard (resources/js/views/PortfolioHolderDashboard.vue) showing red warning for motions in execution_status=in-behandeling or gedeeltelijk-uitgevoerd AND >90 days since last ExecutionUpdate.
  **Acceptance:** badge appears correctly; badge count matches query result.

- [ ] 12.3 Add email template `resources/mails/MotionReminderMail.php`. Sent by ReminderJob. Includes motion title, time since update, link to add update.
  **Acceptance:** email renders; links work; Mailer sends without error.

---

## 13. Voting Explanation (Stemverklaring) and Opt-in Publication

- [ ] 13.1 Extend `lib/Service/VotingService.php::recordVote()` to check if councilor's vote diverges from party recommendation (REQ-STEM-003). If divergence detected, return a response flag `needs_explanation`.
  **Acceptance:** unit test asserts divergence detection correct; flag returned on mismatch.

- [ ] 13.2 Extend VotingMatrix.vue (7.4) to prompt for explanation when divergence detected. Explanation text field (max 200 chars), checkbox "Publish to public portal" (unchecked by default).
  **Acceptance:** prompt appears on divergence; text field works; checkbox saves preference.

- [ ] 13.3 Extend public motion page (8.1) to display voting explanations only if published (voting_explanation IS NOT NULL and publication_opted_in = true). Display as "Councilor Name (Party): [explanation]".
  **Acceptance:** published explanations appear; unpublished explanations hidden; no PII leak.

---

## 14. End-of-Term Report Generation

- [ ] 14.1 Create `lib/Jobs/EndOfTermReportJob.php` (see 6.3). On trigger by griffier, query all motions from completed term. Generate PDF with:
  - All motions (grouped by year, then status)
  - Status distribution (counts)
  - Open motions (execution_status != uitgevoerd|afgewezen) marked for carryover
  - Party voting breakdown on major motions
  - Portfolio holder rankings by motion count
  **Acceptance:** job completes; PDF is valid; all sections present; PDF < 10 MB.

- [ ] 14.2 Create endpoint `POST /api/reports/endofterm` (griffier-only) to trigger report job (ReportController 5.5). Returns job id. Endpoint `GET /api/reports/endofterm/{jobId}` polls status + provides download link on completion.
  **Acceptance:** endpoints work; job queues; download works; email sent on completion.

- [ ] 14.3 Create email template `resources/mails/EndOfTermReportMail.php`. Sent when job completes. Includes link to download PDF.
  **Acceptance:** email renders; link works.

---

## 15. Bulk Import of Carryover Motions

- [ ] 15.1 Create bulk-import endpoint `POST /api/motions/bulk-import-carryover` (griffier-only). Body: list of motion UUIDs from previous term with `execution_status != uitgevoerd|afgewezen`. For each:
  - Create a clone motie (new UUID, same title/text, status=ingediend).
  - Set `portfolio_holder_id` to new alderman (from request body or auto-match by portfolio department).
  - Add a tag "geövergenomen vorige raad".
  - Record in audit trail.
  - Notify each party chair of inherited motions (per party).
  **Acceptance:** bulk import works; clones created; portfolio holders reassigned; emails sent; audit trail recorded.

- [ ] 15.2 Create UI for carryover import (admin/griffier view). Show previous term's open motions + reassignment form (dropdown per portfolio_holder). Submit button triggers bulk import.
  **Acceptance:** UI renders; reassignment dropdowns populated; submit calls endpoint; motions appear in new term list.

---

## 16. Testing

- [ ] 16.1 Create `tests/Unit/Service/MotionServiceTest.php`. Test: creation, status transitions, id generation, reschedule logic, filtering, search.
  **Acceptance:** all tests pass; coverage >80%.

- [ ] 16.2 Create `tests/Unit/Service/AmendmentServiceTest.php`. Test: creation, original_text validation, diff generation, status transitions.
  **Acceptance:** original_text validation rejects mismatches; diff correct; tests pass.

- [ ] 16.3 Create `tests/Unit/Service/VotingServiceTest.php`. Test: vote recording, fractie snapshot immutability, voting matrix, absence handling, divergence detection.
  **Acceptance:** fractie_id immutable; voting matrix correct; divergence detected correctly; tests pass.

- [ ] 16.4 Create `tests/Unit/Service/ExecutionServiceTest.php`. Test: execution update creation, status sync, 90-day query, timeline ordering.
  **Acceptance:** status sync correct; 90-day query boundaries correct; tests pass.

- [ ] 16.5 Create `tests/Integration/MotionWorkflowTest.php`. End-to-end: create motion → agenda → vote → adopt → execute. Verify all side effects (status changes, email triggers, public page generation).
  **Acceptance:** full workflow succeeds; emails sent; public page generated; data consistent.

- [ ] 16.6 Create `tests/Integration/AmendmentWorkflowTest.php`. End-to-end: create amendment → vote → adopt → proposal integration.
  **Acceptance:** amendment adopted; proposal text updated; change log recorded.

- [ ] 16.7 Create browser/E2E tests (if using Cypress or similar):
  - Create motion from UI, verify in list
  - Vote on motion, verify tally
  - Add execution update, verify timeline
  - Search motions, verify results
  **Acceptance:** E2E tests pass in CI.

---

## 17. Quality Gates

- [ ] 17.1 Run `composer phpcs`. Fix any new PHPCS warnings in lib/, resources/, tests/.
  **Acceptance:** PHPCS exits 0.

- [ ] 17.2 Run `composer phpmd`. Fix any new PHPMD findings.
  **Acceptance:** PHPMD exits 0 (or baseline updated with rationale).

- [ ] 17.3 Run `composer psalm`. Fix any new Psalm errors.
  **Acceptance:** Psalm exits 0.

- [ ] 17.4 Run `composer phpstan`. Fix any new PHPStan errors.
  **Acceptance:** PHPStan exits 0.

- [ ] 17.5 Run `composer test:unit` and `composer test:all`. All tests must pass.
  **Acceptance:** PHPUnit exits 0; no skipped tests.

- [ ] 17.6 Run `composer check:strict`. The whole pipeline (lint + phpcs + phpmd + psalm + phpstan + test:all) must exit 0.
  **Acceptance:** `check:strict` prints `ALL CHECKS PASSED`.

- [ ] 17.7 Run WCAG AA accessibility check on all public pages (8.1, 8.2).
  **Acceptance:** axe or similar tool reports no WCAG AA violations; color contrast >4.5:1; heading hierarchy correct.

---

## 18. Documentation

- [ ] 18.1 Create `docs/features/motions-amendments.md`. Sections: Overview, Motion Lifecycle, Amendment Workflow, Voting, Public Portal, Search & Filtering, Examples.
  **Acceptance:** Markdown lints clean; all features documented; examples included.

- [ ] 18.2 Create `docs/features/execution-tracking.md`. Sections: Execution Status, Portfolio Holder Dashboard, Reminders, Reports, Carryover Import.
  **Acceptance:** Markdown lints clean; all features documented.

- [ ] 18.3 Link new docs from `README.md` (Features section) and main docs navigation.
  **Acceptance:** links exist and resolve.

- [ ] 18.4 Create API documentation (OpenAPI/Swagger spec or similar) for all new endpoints (motions, amendments, voting, execution, reports).
  **Acceptance:** OpenAPI spec is valid; all endpoints documented; examples present.

---

## 19. Migration and Rollback

- [ ] 19.1 Verify all database migrations are reversible (`php app/console doctrine:migrations:migrate --down`).
  **Acceptance:** down-migration executes without error; schema rolls back.

- [ ] 19.2 Create a `migrations/Version20240701000000RollbackMotionAmendment.php` that reverses all four tables if needed.
  **Acceptance:** rollback migration exists and is tested.

---

## 20. Launch Readiness Checklist

- [ ] 20.1 All 19 task groups completed and acceptance criteria passed.
- [ ] 20.2 Code review approved by team lead.
- [ ] 20.3 UI/UX tested on mobile and desktop browsers (Chrome, Firefox, Safari).
- [ ] 20.4 Performance tested: search <1s, voting matrix <100ms, PDF generation <30s for 200 motions.
- [ ] 20.5 Deployment checklist: database migrations, background job registration, feature flags (if any), monitoring alerts.
- [ ] 20.6 Rollout plan: gradual rollout to 3–5 pilot municipalities before general release.
  **Acceptance:** all items checked; PR ready to merge.
