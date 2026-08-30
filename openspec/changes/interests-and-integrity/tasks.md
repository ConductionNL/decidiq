# Tasks: interests-and-integrity

## Implementation Tasks

### Task 1: Register fragment 62 — Nevenfunctie, Geschenk, Integriteitsbeleid schemas with declarative dialects
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister` (+ REQ-INT-002/REQ-INT-003/REQ-INT-004/REQ-INT-005)
- **files**: `lib/Settings/register.d/62-interests-and-integrity.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `nevenfunctie`, `geschenk`, and `integriteitsbeleid` schemas exist with all required fields, property titles, and `x-schema-org` annotations, and no existing schema is modified
  - GIVEN the Nevenfunctie schema WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with states `gemeld → openbaar | intern → beëindigd` and `beëindigd` terminal, and neither schema carries remuneration amounts, private contact data, or a free-form internal-remarks property
  - GIVEN a Nevenfunctie with `publicatiedatum` in the past WHEN read anonymously via the OR predicate surface THEN it is returned; without the predicate (or for an `intern` RvC declaration) it is not; the public-publication eligibility gates are untouched
  - GIVEN a Nevenfunctie missing organisatie, functie, bezoldigd, or person WHEN saved THEN OpenRegister validation rejects it
- [ ] Implement
- [ ] Test

### Task 2: Declarative notifications — annual review rappel and integrity triggers
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification` (+ REQ-INT-009)
- **files**: `lib/Settings/register.d/62-interests-and-integrity.json`
- **acceptance_criteria**:
  - GIVEN a non-terminal Nevenfunctie last reviewed/declared more than 12 months ago WHEN the scheduled trigger evaluates THEN the holder is notified (nl+en subjects); reviewed and `beëindigd` objects trigger nothing
  - GIVEN a new/changed Nevenfunctie or a boven-drempel Geschenk WHEN the created/updated triggers fire THEN the body's configured integrity group (burgemeester/voorzitter/compliance) is notified; below-threshold geschenken trigger nothing
  - GIVEN the notification-dialect gate WHEN it scans the change THEN no imperative dispatch and no bespoke reminder BackgroundJob exists; the D3 recipient fallback is documented if the dialect cannot read the policy group
- [ ] Implement
- [ ] Test

### Task 3: Seed data — policy, nevenfuncties, and geschenken across both seeded bodies
- **spec_ref**: `openspec/changes/interests-and-integrity/design.md#seed-data`
- **files**: `lib/Settings/register.d/62-interests-and-integrity.json` (seed section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 2 integriteitsbeleid objects (gemeenteraad openbaar/50/public gifts; RvC intern/100/internal), 5 nevenfuncties (openbaar published, q.q., gemeld, intern RvC mirroring the otherPositions seed string, beëindigd), and 3 geschenken (aanvaard below threshold, geweigerd boven-drempel, overgedragen) exist per the design tables, with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded stale `reviewedAt` and the council member without any declaration WHEN the dashboard and compliance panel render THEN the KPI and the panel are non-zero on a fresh install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 4: Manifest fragment — MyDeclarations, register index/detail pages, menu, export
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi`
- **files**: `src/manifest.d/interests-and-integrity.json`, `src/dialogs`/`src/modals` (declare/confirm/publish dialogs)
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN MyDeclarations, Nevenfuncties, NevenfunctieDetail, Geschenken, and GeschenkDetail render with the specced columns, quick filters (body, lifecycle/besluit, boven-drempel), and CSV export via the mass-export dialog (schema refs by slug, never PascalCase)
  - GIVEN a logged-in member on MyDeclarations WHEN they add/end a nevenfunctie, register a geschenk, confirm the annual review, or (as staff) publish/withdraw THEN each action is a plain saveObject field write carrying all fields forward (PUT-semantic) and other members' objects are not editable
  - GIVEN the page id `MyDeclarations` WHEN member-onboarding's nevenfuncties-intake step deep-links THEN the target resolves (stable-name contract: capability `interests-and-integrity`, slug `nevenfunctie`, page `MyDeclarations`)
- [ ] Implement
- [ ] Test

### Task 5: Dashboard KPI and per-body compliance panel
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi`
- **files**: `src/manifest.json`, `src/components` (compliance panel)
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the "Nevenfuncties zonder actuele review" stat widget counts non-terminal nevenfuncties with reviews older than 12 months via a declarative source aggregation and deep-links to the pre-filtered index; if the filter DSL lacks a relative-date token the documented design fallback is applied (never a silently wrong count)
  - GIVEN a body with members lacking any (or any reviewed) declaration WHEN the griffie opens the compliance panel on the Nevenfuncties index THEN those members are marked from a client-side join of two standard OR list queries (memberships × nevenfuncties), labeled assistive, with no backend endpoint added
- [ ] Implement
- [ ] Test

### Task 6: Assistive nevenfuncties context on the COI dialog and chair panel
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces`
- **files**: `src/` (conflict-of-interest dialog + meeting COI summary components)
- **acceptance_criteria**:
  - GIVEN a participant with active nevenfuncties WHEN they open "Belangenverstrengeling melden" (REQ-COI-001) THEN their own nevenfuncties are listed with subject-keyword matches highlighted, and the COI note mechanism itself is unchanged
  - GIVEN the chair's COI summary panel (REQ-COI-002) WHEN a declaring participant has registered nevenfuncties THEN their entry links to the register; no auto-declaration, blocking, or scoring occurs anywhere
- [ ] Implement
- [ ] Test

### Task 7: E2E coverage — Playwright scenarios for declarations, publication, and integrity flows
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the suite runs THEN declare → verify → publish → anonymous read (nevenfunctie), register gift → boven-drempel badge + notification (geschenk), annual confirm → KPI drop, and COI dialog assistive block pass end-to-end
- [ ] Implement
- [ ] Test

## Verification

- All tasks checked off; manual testing against acceptance criteria; code review against spec requirements
- `openspec validate --strict` passes

## Quality checklist

- No new PHP expected (zero-backend change); if any lands, PHPUnit covers it and `composer check:strict` stays clean
- Newman covers the anonymous predicate reads (published returned, internal/unpublished not)
- UI changes covered by Playwright browser tests (gate-19)
- Feature documentation in `docs/features/interests-and-integrity.md` with screenshots (ADR-010), including the otherPositions supersession and REQ-012 relation notes
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle, custom-widget ratchet for the compliance panel)
