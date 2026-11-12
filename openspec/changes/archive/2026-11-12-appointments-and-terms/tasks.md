# Tasks: appointments-and-terms

## Implementation Tasks

### Task 1: Register fragment 61 — Voordracht, TermijnRegeling, RoosterVanAftreden, RoosterRegel schemas with declarative dialects
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-001-voordracht-schema-on-openregister` (+ REQ-APT-002/REQ-APT-005/REQ-APT-009/REQ-APT-010)
- **files**: `lib/Settings/register.d/61-appointments-and-terms.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN schemas `voordracht`, `termijn-regeling`, `rooster-van-aftreden`, `rooster-regel` exist with all required fields, property titles, `x-schema-org` annotations, and `x-openregister-relations` to Person/GovernanceBody/Post/AgendaItem/VotingRound/Decision/Membership — and no schema outside fragment 61 (siblings own 40–60 and 62–65) is created or modified
  - GIVEN the voordracht schema WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with `ingediend → behandeld → benoemd | niet-benoemd | ingetrokken` (terminals final, `ingetrokken` reachable from both non-terminals, `benoemd` gated on a `besluit` reference) and no imperative status write path exists
  - GIVEN the rooster-regel schema WHEN inspected THEN `x-openregister-notifications` declares scheduled 6/3-month rappel triggers on `eindeTermijnDatum` (nl+en subjects naming member, body, role, date) and the rooster-van-aftreden schema declares the `publicatiedatum` predicate + `authorization.read` public rule — no bespoke reminder job, gate-18 clean
  - GIVEN a second body-wide TermijnRegeling for a body that already has one WHEN saved THEN validation rejects it naming the existing regeling
- [ ] Implement
- [ ] Test

### Task 2: Seed data — municipal committee + corporate RvC demo set
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-013-seed-data-for-all-four-schemas`
- **files**: `lib/Settings/register.d/61-appointments-and-terms.json` (seed section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 2 termijn-regelingen (Auditcommissie unlimited; RvC max 2 aansluitend), 3 voordrachten (`ingediend`, `benoemd` with agendapunt + secret votingRound + besluit + membership links, `ingetrokken`), 2 roosters (published RvC with `publicatiedatum`, unpublished Auditcommissie), and 5 rooster-regels exist per the design tables, linked to seeded persons/bodies/memberships with nil-UUID placeholders only for unresolvable refs
  - GIVEN the seeds WHEN the app renders THEN the regel inside the 6-month window drives the KPI and rappel demo, the termijnNummer-2 regel shows the max-term advisory warning, and the published RvC rooster is anonymously readable while the municipal one is not
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — voordrachten index/detail, rooster page, termijn-regeling config, menu
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-012-pages-menu-and-expiring-terms-kpi`
- **files**: `src/manifest.d/appointments-and-terms.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the voordrachten index renders with the specced columns (candidates, body, target role, voordragende partij, lifecycle, decision link) and quick filters (lifecycle, body, voordragende partij), all schema refs by slug (`voordracht`, `termijn-regeling`, `rooster-van-aftreden`, `rooster-regel`), never PascalCase
  - GIVEN a voordracht detail page WHEN opened THEN candidates and motivering render, linked agenda item / voting round / besluit / membership are navigable relations, and the audit-trail sidebar is present
  - GIVEN a per-body rooster page WHEN opened THEN regels render ordered by end-of-term date with `gegenereerdOp`, max-term warnings as text + icon (not color alone), and regenerate / CSV export / publish actions
- [ ] Implement
- [ ] Test

### Task 4: Dashboard KPI — terms expiring within N months + voordrachten per status
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-012-pages-menu-and-expiring-terms-kpi`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the expiring-terms KPI (default N=6) and voordrachten-per-status counts come from declarative source aggregations (no imperative counting endpoint) and the KPI routes to the pre-filtered rooster view
  - GIVEN the widget filter DSL lacks a relative-date token for the window cut WHEN implementing THEN the documented fallback applies (count non-ended regels, cut the window on the pre-filtered index — never a silently wrong count) and the resolution is recorded in the PR
- [ ] Implement
- [ ] Test

### Task 5: BenoemingService — assistive Membership creation with traceability and onboarding handoff
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-004-benoemingsbesluit-linkage-and-assistive-membership-creation` (+ REQ-APT-003)
- **files**: `lib/Service/BenoemingService.php`, `lib/Controller/AppointmentController.php`, `appinfo/routes.php`, `tests/Unit/Service/BenoemingServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `benoemd` voordracht WHEN the griffie confirms benoeming THEN a Membership is created prefilled (person, body, role, post, startDate = decision date, editable) via the OR abstraction, the voordracht's `membership` reference is set, and the appointing decision is traceable from the Membership via the voordracht
  - GIVEN a candidate with only `externeNaam` or a voordracht not in `benoemd` WHEN benoeming is attempted THEN the endpoint refuses (422) with a named reason — fail-closed, unit-proven; no new vote mechanics anywhere (ballot data stays in VotingRound)
  - GIVEN member-onboarding is present WHEN a Membership is created THEN a reference-only OnboardingTraject suggestion is surfaced; GIVEN it is absent THEN no suggestion and no error
  - GIVEN a non-secretary/griffie user WHEN calling the endpoint THEN the request is rejected (per-body guard, correct auth attributes — no-admin-idor/semantic-auth gates pass)
- [ ] Implement
- [ ] Test

### Task 6: RoosterService — term derivation, rooster (re)generation, CSV export
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-006-derived-term-number-and-end-of-term-date-per-membership` (+ REQ-APT-007/REQ-APT-008)
- **files**: `lib/Service/RoosterService.php`, `lib/Controller/AppointmentController.php`, `appinfo/routes.php`, `tests/Unit/Service/RoosterServiceTest.php`
- **acceptance_criteria**:
  - GIVEN membership history and effective regelingen WHEN terms are derived THEN consecutive reappointment increments the term number, a gap or role change resets it, end-of-term = term start + termijnDuurMaanden, and a role-specific regeling overrides the body-wide one (all unit-proven, pure function)
  - GIVEN a regenerate call WHEN it runs THEN regels are replaced with freshly derived ones ordered by end-of-term date, stale regels for ended memberships are removed, `gegenereerdOp` updates, and a 45-member body completes interactively without N+1 reads (bulk queries)
  - GIVEN a derived term exceeding maxAansluitendeTermijnen WHEN the rooster or a matching voordracht renders THEN an advisory warning appears and saving is never blocked
  - GIVEN the export endpoint WHEN called THEN a UTF-8-BOM CSV downloads with one row per regel (name, role, term number, term start, end-of-term, herbenoembaar) in end-of-term order, behind the same per-body read guard
- [ ] Implement
- [ ] Test

### Task 7: Vacancy flow — vacant Posts overview with griffie-confirmed voordracht suggestions
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md#requirement-req-apt-011-vacancy-flow-opens-the-post-and-suggests-a-voordracht`
- **files**: `lib/Service/RoosterService.php`, `lib/Controller/AppointmentController.php`, `src/manifest.d/appointments-and-terms.json`, `tests/Unit/Service/RoosterServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a Post with no active Membership (term end reached or resignation end-dated) WHEN the vacancy overview loads THEN the Post is listed as vacant (existing person-and-membership Post semantics — no new vacancy schema) with a voordracht suggestion prefilled with body, post, role
  - GIVEN a suggestion WHEN not confirmed THEN no voordracht object exists — creation happens only on explicit secretary/griffie confirm, never automatically
  - GIVEN a member reappointed with a consecutive Membership WHEN the overview loads THEN that Post is not listed as vacant
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for voordracht, benoeming, rooster, publication, and vacancy
- **spec_ref**: `openspec/changes/appointments-and-terms/specs/appointments-and-terms/spec.md`
- **files**: `tests/e2e/appointments-and-terms.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (backend-only invariants excluded to PHPUnit as appropriate)
  - GIVEN the seeded environment WHEN the suite runs THEN create → behandeld → link besluit → benoemd → assistive Membership (voordracht), withdraw → ingetrokken, regenerate → CSV export → publish → anonymous read (rooster), rappel/KPI visibility, and vacancy → confirmed suggestion pass end-to-end through the UI on the Postgres environment
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`); term derivation and fail-closed benoeming mutation-guarded (tests flip on behaviour change, no fake green)
- New/changed API endpoints covered by Newman/Postman tests (incl. published-predicate anonymous read and 422 refusals)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/appointments-and-terms.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle/spec-anchors)
- `openspec validate` passes
