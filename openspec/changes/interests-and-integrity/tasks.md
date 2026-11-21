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
- [x] Implement
- [x] Test
- Built earlier and renamed by integrity-disclosures-in-plain-words: fragment 62 declared Nevenfunctie, Geschenk and Integriteitsbeleid; fragment 81 carries them as `ancillary-position` and `declared-gift` (English names, same properties) and folds the policy into `body-governance-configuration`, retiring the Dutch-named schemas (`active: false`, `hardDelete: false`). Tested (20 Nov): `tests/Unit/Settings/IntegrityDisclosuresRegisterTest.php` checks the required fields, titles, `x-schema-org`, no pay amount, contact data or internal-remarks field, the refusal of a declaration missing organisation, role, paid flag or person, the canonical lifecycle with `ended` terminal, the public read predicate on `publicationDate <= $now` with no unconditional public read, and the retired schemas and folded policy fields. The anonymous read itself needs a live instance (Newman).

### Task 2: Declarative notifications — annual review rappel and integrity triggers
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification` (+ REQ-INT-009)
- **files**: `lib/Settings/register.d/62-interests-and-integrity.json`
- **acceptance_criteria**:
  - GIVEN a non-terminal Nevenfunctie last reviewed/declared more than 12 months ago WHEN the scheduled trigger evaluates THEN the holder is notified (nl+en subjects); reviewed and `beëindigd` objects trigger nothing
  - GIVEN a new/changed Nevenfunctie or a boven-drempel Geschenk WHEN the created/updated triggers fire THEN the body's configured integrity group (burgemeester/voorzitter/compliance) is notified; below-threshold geschenken trigger nothing
  - GIVEN the notification-dialect gate WHEN it scans the change THEN no imperative dispatch and no bespoke reminder BackgroundJob exists; the D3 recipient fallback is documented if the dialect cannot read the policy group
- [x] Implement
- [x] Test
- Built (21 Nov): the three notifications were declared earlier (fragment 62, carried by 81 under the English schema names); fragment `136-gift-threshold-and-review-cutoff.json` narrows two of them to the spec. The annual review reminder gets its 12-month cutoff (`reviewedAt` older than P12M, or never reviewed and `declaredAt` older than P12M, in OpenRegister's scheduled-filter grammar as 130-implementation-progress uses it). The gift notification fires only for a gift at or above its body's threshold: a created-trigger filter can only compare for equality, so `DeclaredGiftThresholdListener` (a save guard on `declared-gift`, create and update) stamps `thresholdStatus` `above` or `below` from the body's `giftThresholdAmount`, or EUR 50 when the body has none, and the trigger filters on `above`. The recipient fallback stays as documented (D3): the dialect cannot read `integrityNotificationGroup` from the body's configuration, so the literal integrity group is used, and the holder is reached through object-acl because `person` is a Person uuid and not a user id. No imperative dispatch, no BackgroundJob. Test: `tests/Unit/Listener/DeclaredGiftThresholdListenerTest.php` (default and configured thresholds, the boundary, re-stamp on update, a client's stamp replaced, unreadable configuration, subscription, the merged trigger filters). Firing them on a live instance is part of the e2e pass.

### Task 3: Seed data — policy, nevenfuncties, and geschenken across both seeded bodies
- **spec_ref**: `openspec/changes/interests-and-integrity/design.md#seed-data`
- **files**: `lib/Settings/register.d/62-interests-and-integrity.json` (seed section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 2 integriteitsbeleid objects (gemeenteraad openbaar/50/public gifts; RvC intern/100/internal), 5 nevenfuncties (openbaar published, q.q., gemeld, intern RvC mirroring the otherPositions seed string, beëindigd), and 3 geschenken (aanvaard below threshold, geweigerd boven-drempel, overgedragen) exist per the design tables, with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded stale `reviewedAt` and the council member without any declaration WHEN the dashboard and compliance panel render THEN the KPI and the panel are non-zero on a fresh install (ADR-016)
- [x] Implement
- [x] Test
- Built earlier in the example sets (seed-profiles moved seeds out of `register.d`): the municipality profile seeds the council policy and four ancillary positions and two gifts; the corporate profile seeds the supervisory board policy, its internal position and the transferred gift. Tested (20 Nov): `IntegrityDisclosuresRegisterTest::testTheExampleSetsSeedEveryKindOfDisclosure` checks both policies (public, EUR 50, gifts public; internal, EUR 100, gifts internal), the five kinds of position (public, q.q., declared, internal, ended), the three gift decisions around the threshold, the required fields of every seed, that nothing declared or internal carries a publication date, and that one review is over a year old so the dashboard counts it.

### Task 4: Manifest fragment — MyDeclarations, register index/detail pages, menu, export
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi`
- **files**: `src/manifest.d/interests-and-integrity.json`, `src/dialogs`/`src/modals` (declare/confirm/publish dialogs)
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN MyDeclarations, Nevenfuncties, NevenfunctieDetail, Geschenken, and GeschenkDetail render with the specced columns, quick filters (body, lifecycle/besluit, boven-drempel), and CSV export via the mass-export dialog (schema refs by slug, never PascalCase)
  - GIVEN a logged-in member on MyDeclarations WHEN they add/end a nevenfunctie, register a geschenk, confirm the annual review, or (as staff) publish/withdraw THEN each action is a plain saveObject field write carrying all fields forward (PUT-semantic) and other members' objects are not editable
  - GIVEN the page id `MyDeclarations` WHEN member-onboarding's nevenfuncties-intake step deep-links THEN the target resolves (stable-name contract: capability `interests-and-integrity`, slug `nevenfunctie`, page `MyDeclarations`)
- [x] Implement
- [x] Test
- Built (21 Nov): `MyDeclarationsPanel` in the below-header slot of `MyDeclarations` lists only the current user's own positions and gifts (the people whose `nextcloudUserId` is the user's) and carries "Declare another position" (`AncillaryPositionDialog`), "Register a gift" (`DeclaredGiftDialog`), "End position" (`AncillaryPositionEndDialog`, offered from `public` and `internal`, the states the lifecycle ends from) and "Still accurate" (sets `reviewedAt` to today and nothing else). Each is a saveObject of the whole object; a refusal is shown as the server gives it. Publish and withdraw are header actions on `AncillaryPositionDetail` and `DeclaredGiftDetail` that PATCH `publicationDate`/`depublicationDate` on the live object, as the delegation register does. Rules in `src/utils/ownDeclarations.js`; `tests/vitest/ownDeclarations.spec.js` checks the rules, every written record against the merged schema, and the manifest and registry wiring. Not checked here: that a member's own update passes OpenRegister's RBAC (the schema's `update` names only the administrator groups; the owner of an object is let through by OpenRegister), which needs a live instance.

### Task 5: Dashboard KPI and per-body compliance panel
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi`
- **files**: `src/manifest.json`, `src/components` (compliance panel)
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the "Nevenfuncties zonder actuele review" stat widget counts non-terminal nevenfuncties with reviews older than 12 months via a declarative source aggregation and deep-links to the pre-filtered index; if the filter DSL lacks a relative-date token the documented design fallback is applied (never a silently wrong count)
  - GIVEN a body with members lacking any (or any reviewed) declaration WHEN the griffie opens the compliance panel on the Nevenfuncties index THEN those members are marked from a client-side join of two standard OR list queries (memberships × nevenfuncties), labeled assistive, with no backend endpoint added
- [x] Implement
- [x] Test

### Task 6: Assistive nevenfuncties context on the COI dialog and chair panel
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces`
- **files**: `src/` (conflict-of-interest dialog + meeting COI summary components)
- **acceptance_criteria**:
  - GIVEN a participant with active nevenfuncties WHEN they open "Belangenverstrengeling melden" (REQ-COI-001) THEN their own nevenfuncties are listed with subject-keyword matches highlighted, and the COI note mechanism itself is unchanged
  - GIVEN the chair's COI summary panel (REQ-COI-002) WHEN a declaring participant has registered nevenfuncties THEN their entry links to the register; no auto-declaration, blocking, or scoring occurs anywhere
- [x] Implement (`src/utils/ancillaryContext.js`, `src/dialogs/ConflictDeclareDialog.vue`, `src/components/tabs/ConflictsTab.vue`; the chair link goes to the member's profile, which lists her other positions)
- [x] Test (`tests/vitest/ancillaryContext.spec.js`)

### Task 7: E2E coverage — Playwright scenarios for declarations, publication, and integrity flows
- **spec_ref**: `openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the suite runs THEN declare → verify → publish → anonymous read (nevenfunctie), register gift → boven-drempel badge + notification (geschenk), annual confirm → KPI drop, and COI dialog assistive block pass end-to-end
- [ ] Implement
- [ ] Test
- Written, not run (20 Nov): `tests/e2e/interests-and-integrity.spec.ts` (anonymous read of published, ended, declared and internal positions; register lenses and export; ex officio; gift lenses; the overdue-review tile). The self-service scenarios wait on task 4. Running it needs a live instance.

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
