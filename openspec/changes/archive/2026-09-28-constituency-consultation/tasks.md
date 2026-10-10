# Tasks: constituency-consultation

## Implementation Tasks

### Task 1: Register fragment 48 — schemas and declarative dialects
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-001-memberconsultation-schema-on-openregister`
- **files**: `lib/Settings/register.d/48-constituency-consultation.json`
- **acceptance_criteria**:
  - GIVEN a clean install WHEN the register configuration loads THEN the `member-consultation` and `member-consultation-response` schemas exist with all REQ-CCO-001/REQ-CCO-002/REQ-CCO-004 properties, every property carrying a `title`, and `decidesk_register.json` unmodified
  - GIVEN the fragment WHEN the lifecycle dialect is inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with states `concept → open → gesloten → verwerkt` (`verwerkt` terminal), and gates 28/30/51/52 pass
  - GIVEN the fragment WHEN the notification rules are inspected THEN `x-openregister-notifications` declares the on-open trigger and the scheduled closing-soon trigger with Dutch and English subjects, and the notification-dialect gate passes
  - GIVEN a save missing `question`, `responseType`, `closesAt`, or both link targets THEN OpenRegister validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data for both schemas
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-001-memberconsultation-schema-on-openregister`
- **files**: `lib/Settings/register.d/48-constituency-consultation.json` (x-openregister.seedData)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN consultations are listed THEN the three seed consultations from design.md Seed Data exist (fractie single-choice `open`, body-members multi-choice `concept`, nc-group open-text `verwerkt` with a filled `results` summary), with nil-UUID placeholders resolved to seed refs
  - GIVEN a clean install WHEN responses are listed THEN the three seed responses exist and the open consultation shows a non-trivial progress count
  - GIVEN the seeded `verwerkt` consultation WHEN its linked target's detail page opens THEN the meeting-context section is non-empty on install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: Audience resolution service
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-002-generic-audience-model`
- **files**: `lib/Service/ConsultationAudienceService.php`, `tests/Unit/Service/ConsultationAudienceServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `body-members` consultation THEN the audience is exactly the persons with an active Membership in `audienceBody` (endDate null or future), expired memberships excluded
  - GIVEN a `fractie` consultation THEN the audience is the subset of active memberships whose `party` equals `audienceParty`
  - GIVEN an `nc-group` consultation THEN the audience is exactly the NC group's members via IGroupManager, with no GovernanceBody requirement
  - GIVEN any resolution THEN it happens server-side from stored audience properties, never from client input
- [ ] Implement
- [ ] Test

### Task 4: Guarded response intake and edit
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-004-response-collection-respects-respond-once-and-edit-until-close`
- **files**: `lib/Service/ConsultationResponseService.php`, `lib/Controller/ConsultationController.php`, `appinfo/routes.php`, `tests/Unit/Service/ConsultationResponseServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an audience member on an open consultation within the window WHEN they respond THEN a response is created with server-set `respondentId`/`submittedAt`; a second create by the same member returns a conflict
  - GIVEN a non-audience authenticated user WHEN they submit a response THEN HTTP 403 and no object is created (Newman IDOR test)
  - GIVEN a consultation whose `closesAt` passed but lifecycle is still `open` WHEN a response arrives THEN it is rejected (window over stale status); edits by the owner succeed before close and fail after
  - GIVEN the controller methods THEN each carries the correct NC auth attribute matching its in-body per-object guard (route-auth + semantic-auth + no-admin-idor gates)
- [ ] Implement
- [ ] Test

### Task 5: Results summary on verwerkt
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-006-results-summary-as-meeting-input-artifact`
- **files**: `lib/Service/ConsultationSummaryService.php`, `lib/Controller/ConsultationController.php`, `tests/Unit/Service/ConsultationSummaryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `gesloten` choice consultation WHEN the initiator transitions it to `verwerkt` THEN `results` holds audience size, response count, and per-option counts, and contains no respondent identity (asserted by PHPUnit)
  - GIVEN an open-text consultation WHEN the initiator supplies a digest at verwerking THEN `results` carries the count and the digest
  - GIVEN a client write attempting to set `results` directly THEN it is rejected (server-written only; Newman negative test)
  - GIVEN the summary path THEN no VotingRound/Vote object is created or modified (REQ-CCO-005 boundary)
- [ ] Implement
- [ ] Test

### Task 6: Manifest pages — index, detail, respond surface, non-binding labels
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-008-list-detail-and-respond-pages-per-manifest-conventions`
- **files**: `src/manifest.d/constituency-consultation.json`, `src/modals/` (respond modal), `src/` respond surface components
- **acceptance_criteria**:
  - GIVEN the manifest fragment WHEN the app builds THEN a Raadplegingen index (columns: question, audience, linked item, closesAt, lifecycle; quick filters on lifecycle and audience type) and a detail page render via CnPageRenderer with slug schema refs only
  - GIVEN an audience member on an open consultation's detail page THEN they can submit/edit their response; NcSelect fields carry `inputLabel`; the respond modal lives in its own file (modal-isolation gate)
  - GIVEN an anonymous-responses consultation THEN the response section shows no names or UIDs (Playwright asserts, plus the flag-flip mutation check)
  - GIVEN index, detail, and respond surfaces THEN each displays the "niet-bindende raadpleging" label (Playwright)
- [ ] Implement
- [ ] Test

### Task 7: Meeting-context input section on agenda item and decision detail
- **spec_ref**: `openspec/changes/constituency-consultation/specs/constituency-consultation/spec.md#requirement-req-cco-005-a-raadpleging-is-non-binding-and-is-not-a-votinground-or-publicconsultation`
- **files**: `src/manifest.d/constituency-consultation.json` (reverse-lookup sections), `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN a `verwerkt` consultation linked to an agenda item WHEN that agenda item's detail page opens THEN a "Raadpleging (niet-bindend)" section shows the question, lifecycle, and summary counts via reverse lookup (same for a linked decision)
  - GIVEN an unauthenticated client WHEN it queries the OR published-predicate surface or any decidiq route THEN no consultation or response data is returned (Newman negative test)
  - GIVEN a decision with a raadpleging summary and a later formal VotingRound THEN the round's tallies contain only formal Votes and no raadpleging counts
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests (incl. 403 non-audience, conflict duplicate, closed-window, results-write-rejected)
- UI changes covered by Playwright browser tests (respond flow, anonymity, non-binding labels, meeting-context section)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation added in `docs/features/achterbanraadpleging.md` with screenshot (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings; i18n keys in English (ADR-005)
- `openspec validate` passes; hydra gates green on register + manifest changes (28/30/51/52, notification-dialect, e2e-coverage)
