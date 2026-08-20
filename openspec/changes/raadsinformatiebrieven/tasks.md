# Tasks: raadsinformatiebrieven

## Implementation Tasks

### Task 1: Register fragment 51 — Raadsinformatiebrief + TechnischeVraag schemas with declarative dialects
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/specs/raadsinformatiebrieven-register/spec.md#requirement-req-rib-001-raadsinformatiebrief-schema-on-openregister` (+ REQ-RIB-002/REQ-RIB-004/REQ-RIB-005/REQ-RIB-006/REQ-RIB-008)
- **files**: `lib/Settings/register.d/51-raadsinformatiebrieven.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `raadsinformatiebrief` and `technische-vraag` schemas exist with all required fields, property titles, `x-schema-org` annotations (`schema:Message` / `schema:Question`), and the `number` pattern `^RIB-\d{4}-\d+$`, and no existing schema is modified
  - GIVEN both schemas WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with the specced transitions (`verzonden → geagendeerd → betrokken-bij-behandeling` incl. direct skip; `gesteld → beantwoord`), and `x-openregister-notifications` declares the created-trigger to council members via `kind:object-acl`/`kind:groups` with nl+en subjects — no imperative dispatch anywhere
  - GIVEN a RIB with `publicatiedatum` in the past WHEN read anonymously via the OR predicate surface THEN it is returned; without the predicate it is not; a TechnischeVraag in `gesteld` with a predicate set is never returned anonymously (compound rule or documented D4 fallback)
  - GIVEN the TechnischeVraag schema WHEN inspected THEN it carries no deadline/termijn/college-workflow properties (REQ-RIB-005 boundary)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic Dutch municipal objects for both schemas
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/design.md#seed-data`
- **files**: `lib/Settings/register.d/51-raadsinformatiebrieven.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 3 RIBs (one geagendeerd+published with afgedaneToezegging link, one verzonden unpublished, one betrokken-bij-behandeling with relatedMotion) and 3 technische vragen (one beantwoord+published, two gesteld) exist per the design tables, linked to seeded body/meeting/agenda-item/toezegging objects with only nil-UUID placeholders for unresolved refs
  - GIVEN the seeded objects WHEN the RIB index and detail render THEN publish, agendering, record-answer, and toezegging-evidence flows are all demoable on install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — RIB index/detail pages, menu, and Q&A thread
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/specs/raadsinformatiebrieven-register/spec.md#requirement-req-rib-007-list-page-detail-page-with-qa-thread-search-and-csv-export`
- **files**: `src/manifest.d/raadsinformatiebrieven.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the Raadsinformatiebrieven index renders with columns number/onderwerp/portefeuillehouder/category/sentAt/lifecycle, quick filters (lifecycle, category, body, portefeuillehouder), and `_search` matching number and onderwerp (schema refs by slug `raadsinformatiebrief`/`technische-vraag`, never PascalCase)
  - GIVEN a RIB detail page WHEN opened THEN letter + bijlagen render via the Files leaf, links (agendaItem/toezegging/dossier/decision/motie) are navigable, the technische-vragen thread lists that RIB's questions in order, the audit trail sidebar is present, and CSV export works via the mass-export dialog
- [ ] Implement
- [ ] Test

### Task 4: Dialogs and flows — add question, record answer, publish/withdraw, number pre-fill
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/specs/raadsinformatiebrieven-register/spec.md#requirement-req-rib-004-technischevraag-schema-for-the-per-rib-qa-thread` (+ REQ-RIB-006; numbering per design.md D5)
- **files**: `src/dialogs/` / `src/modals/` (dedicated dialog components per modal-isolation gate), manifest action wiring
- **acceptance_criteria**:
  - GIVEN a RIB detail WHEN a member/griffie adds a technische vraag THEN a TechnischeVraag is created in `gesteld` referencing the RIB; recording answer text + afdeling + date sets `beantwoord`; both via dedicated dialogs, saves PUT-semantic (all fields carried forward)
  - GIVEN staff on a RIB/answered question WHEN they publish or withdraw THEN `publicatiedatum`/`depublicatiedatum` is set via an explicit confirming dialog; publish is not offered on `gesteld` questions
  - GIVEN the RIB creation dialog WHEN opened THEN the number field pre-fills the next free `RIB-{jaar}-{volgnummer}` for the current year and remains user-editable; a pattern-invalid number is rejected on save
- [ ] Implement
- [ ] Test

### Task 5: Toezegging afdoening evidence — reverse-relation surfacing on ToezeggingDetail
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/specs/raadsinformatiebrieven-register/spec.md#requirement-req-rib-003-toezegging-afdoening-link-surfaces-as-evidence-never-duplicates-the-lifecycle`
- **files**: `src/manifest.d/raadsinformatiebrieven.json` or ToezeggingDetail manifest section (reverse-relation list on `afgedaneToezegging`)
- **acceptance_criteria**:
  - GIVEN a RIB with `afgedaneToezegging` set WHEN the ToezeggingDetail opens THEN the RIB is shown as afdoening evidence with a navigable link, and the RIB detail links back to the toezegging
  - GIVEN the linkage WHEN the toezegging is later afgedaan by the griffier THEN no automatic lifecycle transition or state copy happened from the RIB side (toezegging stayed `open` until the explicit griffie action; RIB carries no afdoening state)
- [ ] Implement
- [ ] Test

### Task 6: E2E coverage — Playwright scenarios for the RIB register
- **spec_ref**: `openspec/changes/raadsinformatiebrieven/specs/raadsinformatiebrieven-register/spec.md`
- **files**: `tests/e2e/`, `tests/Unit/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (the two static-convention scenarios are excluded to schema review/gate-18 as specced)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN register RIB → agenderen → notification visible → publish → public list live-status, and add technische vraag → answer → publish answered / unanswered-never-public pass end-to-end; Newman covers the anonymous predicate negatives
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed logic covered by PHPUnit register-fragment shape assertions (`tests/Unit/`); no backend services/controllers expected in this change — if any appear, revisit design D3
- Anonymous predicate surface covered by Newman/Postman negatives (unpublished RIB, unanswered question)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/raadsinformatiebrieven.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle)
- `openspec validate` passes
