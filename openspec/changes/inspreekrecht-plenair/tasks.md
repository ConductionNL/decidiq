# Tasks: inspreekrecht-plenair

## Implementation Tasks

### Task 1: Register fragment 64 — canonical inspraak-aanmelding + inspraak-beleid with declarative dialects
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-001-generalized-inspraak-aanmelding-schema-on-openregister` (+ REQ-INS-002/REQ-INS-003; notifications per REQ-INS-005/REQ-INS-010)
- **files**: `lib/Settings/register.d/64-inspreekrecht-plenair.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `inspraak-aanmelding` and `inspraak-beleid` schemas exist with all specced fields, property titles, and `x-schema-org` annotations, and no existing schema is modified (fragment number 64 exactly; 40–63/65 untouched)
  - GIVEN the lifecycle declaration WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with exactly the REQ-CVG-009 status enum (`aangemeld → goedgekeurd|afgewezen`, `goedgekeurd → gesproken|niet-verschenen`, terminals as specced), and `x-openregister-notifications` declares created/status-change triggers with nl+en subjects and no imperative dispatch anywhere (gate-18)
  - GIVEN schema RBAC WHEN a non-griffie user attempts a moderation transition or a post-approval edit of `contactgegevens`/`onderwerp` THEN OR refuses it, while griffie writes to `bijdrageTekst`/`transcriptSegment` on a goedgekeurd object succeed
- [ ] Implement
- [ ] Test

### Task 2: Coordination amendment — commissievergaderingen adopts the canonical schema
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-001-generalized-inspraak-aanmelding-schema-on-openregister` (single-canonical-definition scenario), `openspec/changes/inspreekrecht-plenair/design.md#d1-one-canonical-schema-in-fragment-64-the-commissie-change-adopts-it-extend-dont-fork`
- **files**: `openspec/changes/commissievergaderingen/design.md`, `openspec/changes/commissievergaderingen/specs.md`, `openspec/changes/commissievergaderingen/tasks.md`
- **acceptance_criteria**:
  - GIVEN the amended commissie change WHEN its register file task is read THEN it defines 7 schemas (no InspraakAanmelding) and its REQ-CVG-009/011 flows reference the decidesk-register `inspraak-aanmelding` via the generic `meeting` reference, with immutability scoped to the citizen field groups
  - GIVEN both changes' fragments/registers WHEN imported together in either order THEN exactly one `inspraak-aanmelding` schema exists and the fragment 64 import asserts loudly on a duplicate slug
- [ ] Implement
- [ ] Test

### Task 3: Seed data — beleid and aanmelding objects covering the full loop
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/design.md#seed-data`
- **files**: `lib/Settings/register.d/64-inspreekrecht-plenair.json` (seedData section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 2 inspraak-beleid objects (per-agendapunt/voornaam and vergadering/aantal) and 4 aanmeldingen (gesproken-with-bijdrage+transcript-placeholder, goedgekeurd, aangemeld, afgewezen-with-reason) exist per the design tables, with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded raadsvergadering WHEN opened THEN the goedgekeurd inspreker renders as a speaking slot and the aangemeld one as pending, so moderation, agenda rendering, and queue preload are demoable on install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 4: InspraakService + controller — policy-enforcing registration, moderation, referral
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline` (+ REQ-INS-005)
- **files**: `lib/Service/InspraakService.php`, `lib/Controller/InspraakController.php`, `appinfo/routes.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a registration WHEN the body has no beleid object, `inspraakMogelijk` is false, the niveau/agendaItem combination is wrong, or `meeting.start − aanmeldDeadlineUren` has passed THEN it is refused server-side with a specific message and no object is created; WHEN the griffier uses the explicit override THEN it is created and auditable
  - GIVEN moderation WHEN approving THEN spreektijd defaults from `standaardSpreektijdMinuten` and volgorde is set; WHEN rejecting without `afwijzingsReden` THEN refused; WHEN referring THEN meeting/agendaItem are re-targeted, status stays `aangemeld`, and the registrant is notified
  - GIVEN the endpoints WHEN gated THEN every method declares its auth posture with per-object guards (route-auth/no-admin-idor/semantic-auth gates pass) and no CRUD pass-through wrappers exist (redundant-controller gate)
- [ ] Implement
- [ ] Test

### Task 5: Agenda rendering — internal speaking slots and anonymised public projection
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda` (+ REQ-INS-008)
- **files**: `src/` (agenda-item detail insprekers block), public payload builder in `lib/Service/`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN an agenda item with goedgekeurde and aangemelde aanmeldingen WHEN the internal view renders THEN approved slots appear in volgorde order with sprekerNaam and minutes, pending ones visually distinct, afgewezen absent; meeting-level insprekers render in the meeting block for `niveau: vergadering`
  - GIVEN the public payload WHEN built under `aantal` / `voornaam` / `spreker-naam` THEN the projection matches the mode and PHPUnit asserts `contactgegevens` and `afwijzingsReden` are structurally absent in every mode (allow-list, mutation-guarded — the assertion fails when a field is added to the payload)
- [ ] Implement
- [ ] Test

### Task 6: Live wiring — speaker-queue preload and gesproken/niet-verschenen write-back
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back`
- **files**: `src/` (SpeakingTimePanel bridge via its extension surface), `lib/Service/InspraakService.php`, `tests/Unit/`
- **acceptance_criteria**:
  - GIVEN an opened meeting WHEN an agenda item with goedgekeurde aanmeldingen becomes current THEN the REQ-STM queue is preloaded in volgorde order with inspreker labels and per-entry time limits, ahead of ad-hoc entries, without modifying the SpeakingTimePanel contract
  - GIVEN a preloaded entry WHEN the chair marks gesproken or niet-verschenen THEN the aanmelding transitions accordingly via a PUT-semantic save and a unit test asserts an unrelated field (bijdrageTekst) survives; WHEN the chair merely removes the entry from the queue THEN the aanmelding status is unchanged
- [ ] Implement
- [ ] Test

### Task 7: Manifest fragment — griffie overview, list/detail pages, bijdrage/transcript linkage UI
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications`, `openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage`
- **files**: `src/manifest.d/inspreekrecht-plenair.json`, dialogs in `src/dialogs`/`src/modals`
- **acceptance_criteria**:
  - GIVEN the built app WHEN the griffier opens the inspraak overview THEN cross-meeting aanmeldingen list with status/body/date filters, pending first, deadline warnings computed from meeting start − aanmeldDeadlineUren, and approve/reject/refer actionable from the row (schema refs by slug `inspraak-aanmelding` / `inspraak-beleid`, never PascalCase — gates 28/30/51/52)
  - GIVEN a gesproken aanmelding WHEN the griffier attaches bijdrageTekst and a transcript segment THEN the agenda-item detail surfaces them with a deeplink, and the detail page carries Files leaf + audit-trail sidebar
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios across both capabilities
- **spec_ref**: `openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md`, `openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed specs THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (contract scenarios excluded to PHPUnit/Newman as specced)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN register → moderate (approve with spreektijd / reject with reason / refer) → agenda slot renders → queue preload → mark gesproken → status flows back → attach bijdrage passes end-to-end, plus the ALV meeting-level path and the deadline-refusal path
- [ ] Implement
- [ ] Test

## Verification

- `openspec validate --strict` passes; all tasks checked off; manual run of the acceptance criteria against the seeded environment; code review against REQ-INS-001…010.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — incl. deadline math, override, projections, PUT-semantic write-back (ADR-009)
- New/changed API endpoints covered by Newman/Postman tests; UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/inspreekrecht.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
