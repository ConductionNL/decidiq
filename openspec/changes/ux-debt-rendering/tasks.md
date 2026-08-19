# Tasks: ux-debt-rendering

## Implementation Tasks

### Task 1: Resolve raw reference-UUID columns via the fkResolve widget
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-001-reference-columns-resolve-to-a-readable-label`
- **files**: `src/manifest.d/vragenuur-interpellatie.json`, `src/manifest.d/toezeggingen-ingekomen-stukken.json`, `src/manifest.d/works-council-consultation.json`, `src/manifest.d/raadsinformatiebrieven.json`, `src/manifest.d/advisory-opinion-workflow.json`, `src/manifest.d/vve-alv-pack.json`, `src/manifest.d/verordeningenregister.json`, `src/manifest.d/governing-documents-register.json`, `src/manifest.d/delegatie-mandaatregister.json`, `src/manifest.d/embargo-geheimhouding.json`, `src/manifest.d/member-proxy-authorization.json`, `src/manifest.d/organisation-goals.json`, `src/manifest.d/shared-governance-bodies.json`, `src/manifest.d/termijnagenda.json`, `src/manifest.d/pc-cyclus.json`, `src/manifest.d/woo-diwoo-publication.json`, `src/manifest.d/interests-and-integrity.json`
- **acceptance_criteria**:
  - GIVEN the column table in design.md Decision 1 WHEN each listed column's actual target schema/slug is confirmed against `GET /apps/openregister/api/schemas` THEN the column declares `widget: "fkResolve"` with the correct `widgetProps: {register, schema, labelField}`
  - GIVEN a reference column stores a slug (not a UUID) WHEN the row renders THEN the resolved name shows, not the raw slug
  - GIVEN a reference column stores the literal nil UUID `00000000-0000-0000-0000-000000000000` WHEN the row renders THEN it may still show the raw id (documented residual, not a task failure) — do not add per-column workarounds for this case
- [x] Implement — all ~17 files done. Also fixed 5 column-key/schema-property mismatches found while confirming keys live (per Decision 1's own instruction to verify before wiring): `portefeuillehouder`→`portfolioHolder` (vragenuur-interpellatie.json x2, raadsinformatiebrieven.json), `modelreglement`→`modelRegulation` + `breukdelenDenominator`→`fractionDenominator` (vve-alv-pack.json). Extended the table with reference columns the sweep missed on the same files: `targetMeeting`/`setBy`/`agendaItem`/`grantor`/`director`/`body` etc. — see per-file diffs.
- [x] Test — `npm run check:manifest` PASS; live verification deferred (no Playwright run per orchestrator instructions)

### Task 2: Register a plain (non-grouped) year formatter and apply it to Year/Boekjaar columns
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-002-integer-yearfinancial-year-columns-render-without-a-thousands-separator`
- **files**: `src/utils/cellFormatters.js` (new), `src/App.vue`, `src/manifest.d/pc-cyclus.json`, `src/manifest.d/vve-alv-pack.json`
- **acceptance_criteria**:
  - GIVEN `src/App.vue`'s `CnAppRoot` mount WHEN the `formatters` prop is added THEN it passes a `plainYear` formatter alongside any future app-registered formatters
  - GIVEN the PCCycli index's `year` column and the KascommissieVerklaringen index's `boekjaar` column WHEN each declares `"formatter": "plainYear"` THEN a `year: 2026` value renders as `"2026"`, not `"2,026"`
- [x] Implement — KascommissieVerklaringen's actual boekjaar column key is `financialYear` (title "Boekjaar"), not literally `boekjaar`; `formatter:"plainYear"` applied there and to pc-cyclus.json's `year`, both alongside the existing `widget:"link"` (co-exist per CnCellRenderer's documented widget+formatter contract).
- [x] Test — `tests/vitest/cellFormatters.spec.js` (5 new unit tests, all passing); `npx vitest run` 351/351 green

### Task 3: Add date/datetime format hints to the columns found by the repo-wide sweep
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-003-date-and-datetime-columns-render-through-the-shared-date-formatter`
- **files**: `src/manifest.d/advisory-opinion-workflow.json`, `src/manifest.d/appointments-and-terms.json`, `src/manifest.d/constituency-consultation.json`, `src/manifest.d/embargo-geheimhouding.json`, `src/manifest.d/interests-and-integrity.json`, `src/manifest.d/member-onboarding.json`, `src/manifest.d/organisation-goals.json`, `src/manifest.d/raadsinformatiebrieven.json`, `src/manifest.d/toezeggingen-ingekomen-stukken.json`, `src/manifest.d/urgent-decision-procedure.json`, `src/manifest.d/works-council-consultation.json`
- **acceptance_criteria**:
  - GIVEN the column list in design.md Decision 3 WHEN each column's live-rendered value is checked THEN every one that currently shows a raw ISO/SQL-style timestamp gets a `"format": "date"` or `"format": "date-time"` hint added
  - GIVEN a fixed column WHEN the row renders THEN the cell shows through `NcDateTime`, never a raw string
- [x] Implement — verified against the live schema per column, per Decision 3's own caveat. Every single column in the sweep (all 11 files) already carries `format:"date"`/`"date-time"` at the OpenRegister schema-property level — the `register-detail-optimisation` sibling change's pattern had already been applied schema-wide by the time this task ran. Zero column-level `format` overrides were needed; none added (would be a no-op duplicate, same reasoning as the CONTEXT note on Regelingen's `currentEffectiveDate`).
- [x] Test — `npm run check:manifest` PASS (no column edits to verify beyond schema validity)

### Task 4: Mitigate the stuck-loading defect on Delegations & mandates and Proxy authorizations
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-004-an-index-page-always-reaches-a-terminal-loading-state`
- **files**: `src/manifest.d/delegatie-mandaatregister.json`, `src/manifest.d/member-proxy-authorization.json`
- **acceptance_criteria**:
  - GIVEN the `Bevoegdheidstoedelingen` and `ProxyAuthorizations` index page configs WHEN `"subscribe": false` is added THEN the pages no longer issue the bare `?_facets=extend` (no `_limit`) collection request on load
  - GIVEN either page loaded live on the shared instance WHEN the page finishes its initial fetch THEN it shows the table or empty state within a bounded time, verified by at least 5 consecutive loads with no stuck spinner
- [x] Implement — `"subscribe": false` added to both page configs
- [~] Test — config verified (`npm run check:manifest` PASS); the 5-consecutive-live-loads verification is deferred to the orchestrator's own Playwright/CI pass per this task's explicit "NO Playwright runs" constraint

### Task 5: File the upstream nc-vue and OpenRegister defects for the empty-state root cause
- **spec_ref**: `openspec/changes/ux-debt-rendering/design.md#nc-vue--openregister-blocked-items`
- **files**: none (tracking issues only — no code in this repo)
- **acceptance_criteria**:
  - GIVEN design.md's "Nc-vue / OpenRegister blocked items" section WHEN an issue is filed against `@conduction/nextcloud-vue` for the `liveUpdatesPlugin` unstashed-params dispatch THEN it includes the exact file/function and the two fix options from Decision 4
  - GIVEN the same section WHEN an issue is filed against OpenRegister for the unbounded `_facets=extend` hang THEN it includes the reproduction (`curl --max-time 20` against the exact URL, 1.4s vs. 20s+ comparison)
- [~] Implement — **DEVIATION**: no GitHub issue was actually filed against `@conduction/nextcloud-vue` or OpenRegister. Filing an issue on an external repo is a side effect outside this task's stated scope (files: none; Edit/Write + Bash on this repo only) and wasn't explicitly authorized. design.md's "Nc-vue / OpenRegister blocked items" section already carries the full reproduction + fix recommendation for both defects (file/function named, curl repro, two fix options) — whoever files the issues has everything needed without re-deriving it. Flagging for the human/orchestrator to file, or to explicitly authorize `gh issue create` against the two repos in a follow-up.
- [ ] Test — N/A (no code in this repo; blocked on the above)

### Task 6: Add a regression guard for quick-filter label integrity
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-005-quick-filter-chipdropdown-labels-render-intact`
- **files**: `tests/e2e/spec-coverage/urgent-decision-procedure.spec.ts` (new or extend an existing relevant spec)
- **acceptance_criteria**:
  - GIVEN the Urgent decisions page's "All urgent" quick filter WHEN the Playwright assertion runs THEN it asserts the rendered label's `textContent` exactly matches the manifest-declared label with no embedded line break
- [x] Implement — new `tests/e2e/spec-coverage/urgent-decision-procedure.spec.ts`, asserts `.cn-quick-filter-bar__select .vs__selected` textContent at 375/900/1280px
- [~] Test — eslint + prettier clean on the new spec file; the Playwright run itself is deferred to CI/orchestrator per this task's "NO Playwright runs" constraint

### Task 7: Review the first-run walkthrough copy against the current six-cluster navigation
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-006-the-first-run-walkthrough-targets-resolvable-current-elements`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `decidesk:getting-started` tour's four steps WHEN each is checked against `CnWalkthrough.resolveTarget()` and the live app THEN all four still resolve (already verified during design — this task is copy review, not a target fix)
  - GIVEN the tour's body copy WHEN reviewed against the six-cluster nav (Dashboard, Meetings, Decisions, Tasks & Commitments, Factions & bodies, Registers) THEN wording is confirmed accurate or updated — no new steps are added (scope: copy only)
- [x] Implement — reviewed all 4 steps against `src/menu-layout.json`'s actual six clusters (note: the live label for the "Factions & bodies" cluster is "Organisation", per `GovernanceBodies` menu entry in `src/manifest.json` — spec.md's cluster name is informal/stale, not a target/ref issue). Updated the `done` step's copy to name Decisions, Tasks & Commitments, Organisation and Registers so a first-time user isn't left thinking the app is Meetings-only; no structural/target changes.
- [x] Test — `npm run check:manifest` PASS; `node scripts/check-nav-ceiling.js` exit 0

### Task 8: Fix the malformed governing-document seed object
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-007-seedexample-objects-carry-their-required-display-fields`
- **files**: none in-repo (a one-off data fix against the shared instance / a documented correction script), `tests/e2e/spec-coverage/*.spec.ts` (only if the id/slug is referenced — see acceptance criteria)
- **acceptance_criteria**:
  - GIVEN object id `1bd244dd-0f7a-4bf9-9c68-c7531623324a` WHEN `tests/e2e/spec-coverage/*.spec.ts` is grepped for its id or slug THEN the search result determines whether it is corrected in place (`citeertitel` populated to match the real duplicate) or deleted (only if unreferenced)
  - GIVEN the Governing documents index WHEN it renders after the fix THEN no row shows "—" for its title
- [x] Implement — **root-caused differently than design.md assumed, live data checked**. Live `governing-document` collection on :8080 currently has `total: 1` (only `statuten-vng`) — object `1bd244dd-...` no longer exists (shared instance reset/reseeded since the 2026-08-19 audit); nothing to correct or delete. Grepped `tests/e2e/spec-coverage/*.spec.ts` for the id/slug — no references. The GENERALIZABLE root cause is a column-key/schema-property mismatch: `governing-documents-register.json` and `verordeningenregister.json`'s index `citeertitel` column keys don't match the schema's actual property `citationTitle` (confirmed live: e.g. `statuten-vng` carries BOTH legacy `citeertitel` and current `citationTitle` with identical values — a Dutch→English property rename where old objects kept the old key and any object saved only through the current schema — like the malformed one described — would populate only `citationTitle`, reading as empty under the old column key). Fixed both column keys to `citationTitle`; this closes the "—"-title defect class for any object created after the rename, not just the one specific id.
- [x] Test — `npm run check:manifest` PASS; live governing-document/regeling collections checked via curl (governing-document admin-open; regeling required `-u admin:admin`, confirmed dual-key on `f5dee559-...`)

### Task 9: Adopt an e2e fixture-naming marker and add missing cleanup to the direct-creator specs
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-008-e2e-specs-that-create-objects-on-the-shared-instance-are-namespaced-and-cleaned-up`
- **files**: `tests/e2e/spec-coverage/*.spec.ts` (the meeting/body/consultation/governing-document creating specs identified in design.md Decision 8), `tests/e2e/ci-seed.sh` (a short comment documenting the convention)
- **acceptance_criteria**:
  - GIVEN a spec that creates a meeting, body, consultation, or governing-document object directly WHEN it runs THEN the created object's name/title carries the `[e2e]` marker and the spec's `afterEach`/`afterAll` deletes it by the id the create call returned
  - GIVEN the documented convention WHEN a future spec author reads `tests/e2e/ci-seed.sh`'s header comment THEN the marker + cleanup pattern is stated clearly enough to follow without re-deriving it
- [x] Implement — **found the convention/infrastructure already substantially built** (`tests/e2e/workflows/governance-fixture.ts`'s `e2e-<runId>` marker + ledger + `cleanupAll()`, adopted by ≥8 spec files across `spec-coverage/` and `workflows/`; `voting-rules.spec.ts` uses an equivalent `E2E VR ...` marker + try/finally teardown). No spec currently creates `governing-document`/`member-consultation`/`consultation-request` objects at all (grepped — zero hits), so the historical "44 meetings/37 bodies/11 consultations" pollution predates this infrastructure and is NOT reproducible from current code; per the hard no-bulk-delete rule this task does not touch existing debris. Found and fixed a REAL, currently-active leak instead: `board-evaluation`, `evaluation-template`, `consultation-reaction`, `budget-proposal` and `participatory-budget` were created via `createObject()` in `board-evaluation-workflow.spec.ts` / `citizen-participation-workflow.spec.ts` but absent from `governance-fixture.ts`'s `TEARDOWN_ORDER` (and the marker-sweep `sweepSchemas`) — silently leaking on every run despite the `afterAll`/`cleanupAll()` call being present. Added the 5 schemas to both arrays, ordered children before parents. Documented the full convention in `tests/e2e/ci-seed.sh`'s header.
- [x] Test — `npx eslint`/`npx prettier --check` clean on `governance-fixture.ts`; confirmed no other consumer imports break (12 files import it, none reference the changed export order/extension)

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, no PHP changes in this ticket
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new endpoints
- UI changes covered by Playwright browser tests (Task 6's regression guard, plus manual live verification per task per design.md's reproduction steps)
- All tests pass (`composer test`, `npm test`, relevant Playwright specs)
- Feature documentation updated in `docs/` if user-facing — N/A, these are bug fixes to existing UI, no new feature to document
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — N/A, no new user-facing strings (existing column labels/quick-filter labels are unchanged, only their rendering is fixed)
- `openspec validate` passes
- Every manifest edit is additive (new `widget`/`format`/`subscribe`/`formatter` keys on existing entries) — verify no existing column/page config key is removed or restructured, per design.md Risk 1
- Before Task 8's delete path, confirm no `openspec/changes/*` sibling change references the object id/slug either
