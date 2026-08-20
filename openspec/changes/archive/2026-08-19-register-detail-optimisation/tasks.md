# Tasks: register-detail-optimisation

## Implementation Tasks

### Task 1: Build the shared version-timeline widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail`
- **files**: `src/components/widgets/RegisterVersionTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN a `content` config `{ versionRegister, versionSchema, parentRefField, effectiveDateField, versionNumberField, statusField, decisionRefField, lapseDateField?, extraFields? }` WHEN the widget mounts on a detail page THEN it queries `versionRegister`/`versionSchema` filtered on `parentRefField == currentObjectId` and renders the results sorted ascending by `effectiveDateField`
  - GIVEN a version row with `decisionRefField` set WHEN rendered THEN it shows a working link to that Decision's detail page; GIVEN it is unset THEN no dead link renders
  - GIVEN zero matching versions WHEN rendered THEN an explicit empty-state message renders (never a stuck loading state)
  - GIVEN `extraFields` entries (e.g. `aktedatum`/`notaris`) WHEN present on a row THEN they render on that row's entry
  - All dates render through the shared `formatDate`/`formatDateTime` utilities, never raw `Date` stringification
- [x] Implement
- [x] Test

### Task 2: Build the ondermandaat delegation-chain widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **files**: `src/components/widgets/DelegationChainWidget.vue`
- **acceptance_criteria**:
  - GIVEN a toedeling with an ancestor chain via `parentAllocation` WHEN the widget mounts THEN it walks the chain upward and renders an ordered breadcrumb (root → ... → current), each entry a working link
  - GIVEN toedelingen whose `parentAllocation` equals the current object's id WHEN the widget mounts THEN it lists them as direct children with working links
  - GIVEN a toedeling with no parent and no children WHEN rendered THEN the widget shows just that toedeling, no error, no empty grid gap
  - GIVEN a defensive cycle in `parentAllocation` references (never producible via normal flows) WHEN the ancestor walk runs THEN it de-duplicates visited ids and terminates without an infinite loop or duplicate re-fetch
  - The source `decision` link and resolved delegans/delegataris render alongside the chain
- [x] Implement
- [x] Test

### Task 3: Build the confidentiality status-timeline widget component
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail`
- **files**: `src/components/widgets/ConfidentialityStatusTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN a `geheimhouding` object WHEN the widget mounts THEN it renders exactly three stages — imposed, bekrachtiging, dissolution — each populated when its fields are set and shown as a pending placeholder otherwise
  - GIVEN `ratificationDeadline` in the past and `lifecycle: "imposed"` WHEN rendered THEN the bekrachtiging stage shows an overdue indicator conveyed via icon/text, not colour alone
  - GIVEN a set `ratificationDecision`/`dissolutionDecision` WHEN rendered THEN each shows a working link to that Decision
  - GIVEN the record's `ground` reference WHEN resolved THEN both `citation` and `legacyCitation` (when set) render
  - GIVEN whichever of `targetDocument`/`targetAgendaItem`/`targetDecision` is set WHEN resolved THEN a labelled link to that object's own detail page renders (never a bare UUID)
- [x] Implement
- [x] Test
- **DEVIATION**: `targetDocument` (scope `document`, `$ref: DigitalDocument`) resolves to a labelled name (never a bare UUID — the resolved `name` renders) but NOT a working link: decidesk ships no detail route/page for a standalone `digital-document` object (confirmed: no `DigitalDocumentDetail` page/route exists anywhere in `src/manifest.json` or any `manifest.d/*.json` fragment), and this change's file-edit scope forbids adding routes/pages. `targetAgendaItem` and `targetDecision` both resolve to real working links (`AgendaItemDetail` / `DecisionDetail`, which do exist). This is a pre-existing product gap (no document-detail surface), not a defect introduced here — flag as a follow-up if a document-scoped geheimhouding's target needs to be clickable.

### Task 4: Register the three widgets and wire the import
- **spec_ref**: `openspec/changes/register-detail-optimisation/design.md#d4-declarative-vs-imperative-decision-adr-031`
- **files**: `src/components/widgets/registerDetailWidgets.js`, `src/main.js`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN `registerDetailWidgets.js` runs THEN it calls `registerDashboardWidget()` for `version-timeline`, `delegation-chain`, and `confidentiality-status-timeline`, each with `form: null` and `surfaces: ['detail-page']`
  - GIVEN the dashboard Add-widget picker WHEN opened THEN none of the three new types appear in it (renderer-only, detail-page scoped)
  - GIVEN a manifest detail page declares `{ "type": "version-timeline", ... }` WHEN `CnDetailPage` resolves the widget THEN it renders `RegisterVersionTimelineWidget` (and analogously for the other two types)
- [x] Implement
- [x] Test
- **DEVIATION from design.md's literal wording** ("self-registration module... imported once, for its side effect"): `registerDetailWidgets.js` exports an **async function** `registerDetailWidgets()` — called once, awaited, from `src/main.js`'s boot IIFE (before `app.mount`) — rather than registering via a bare top-level side-effect import. Reason: `@conduction/nextcloud-vue`'s bundle re-exports `@nextcloud/vue`, whose `package.json` declares no root `"exports"` entry; a **static top-level** `import` of either package (or of any `.vue` file, which transitively imports `@nextcloud/vue`) makes the whole module unloadable under this repo's `vitest.config.js` (plain Vite, no `@vitejs/plugin-vue` registered — confirmed empirically, see Verification below). `registerDetailWidgets()` defers those imports to **dynamic** `import()` calls inside the function body, which Vite/vitest never evaluates unless the function is actually called — so the module's pure helper functions (`sortVersionsByEffectiveDate`, `walkAncestors`, `findChildren`, `buildConfidentialityStages`, `resolveObjectLabel`) stay importable and unit-tested (`tests/vitest/registerDetailWidgets.spec.js`, 26 passing tests) without ever triggering the unresolvable import. Webpack (the app's real build) code-splits a dynamic `import()` exactly like a static one, so runtime behaviour is unchanged — only the *timing* (async, gated before mount) differs from a bare side-effect import.

### Task 5: Wire the version-timeline widget and fix date formatting on the Regulations register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-010-in-force-status-and-cvdr-identifier-are-foregrounded-on-regelingdetail`
- **files**: `src/manifest.d/verordeningenregister.json`
- **acceptance_criteria**:
  - `RegelingDetail`'s widget layout gains a `version-timeline` entry bound to `regeling-versie` via `regeling`'s id, with `decisionRefField` mapped to `vastgesteldDoor`
  - The `regeling-data` widget's field order/overrides foreground `status` and `cvdrIdentifier`
  - The `Regelingen` index's `currentInwerkingtreding` column declares `"format": "date"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [x] Implement
- [~] Test — l10n strings NOT added (see Task 4/8 quality-checklist note below; out of this change's file-edit scope)
- **DEVIATION**: the actual `RegelingVersie` schema property that traces the enacting Decision is named `determinedBy`, not `vastgesteldDoor` (the spec's own prose uses the Dutch working name, but `lib/Settings/register.d/53-verordeningenregister.json` declares the field as `determinedBy` — confirmed by reading the schema directly). The manifest's `decisionRefField` is set to `determinedBy` to match the real field; using the literal string `vastgesteldDoor` from the acceptance text would silently resolve nothing (the exact "a field OR value rename is invisible" failure mode this whole change exists to fix elsewhere).

### Task 6: Wire the version-timeline widget and the current-in-force-date column on the Governing documents register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-010-current-in-force-date-column-on-the-governingdocuments-index`
- **files**: `src/manifest.d/governing-documents-register.json`
- **acceptance_criteria**:
  - `GoverningDocumentDetail`'s widget layout gains a `version-timeline` entry bound to `governing-document-versie`, with `extraFields` covering `aktedatum`/`notaris`
  - The `GoverningDocuments` index gains a current-in-force-date column resolved as a single list-level query (no per-row lookup), with `"format": "date"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [x] Implement (version-timeline widget only — see NOT IMPLEMENTED below)
- [ ] Test
- **NOT IMPLEMENTED — current-in-force-date index column**: unlike `Regeling` (which has a `currentEffectiveDate` convenience property, REQ-VOR-011's actual fix target), the `GoverningDocument` schema (`lib/Settings/register.d/55-governing-documents-register.json`) declares **no computed current-in-force-date property at all**. Adding one requires either (a) an OpenRegister schema change (explicitly out of scope — "no OpenRegister schema changes" is a hard constraint on this task), or (b) a new cross-schema declarative column mechanism (none exists in the manifest-v2 schema/CnDataTable today — confirmed by reading `tests/schemas/app-manifest-v2.schema.json` and `CnDataTable`'s column pipeline), or (c) a bespoke imperative page component wired through `registry.js` (also out of this task's file-edit scope). All three paths are blocked by this task's own file-edit boundary, so the column is **not added**. This is a genuine follow-up requiring either a schema change (own OpenRegister ticket) or a declarative cross-schema column primitive added to the shared library — flag to the orchestrator/human rather than fake a column key pointing at nothing (the exact anti-pattern REQ-VOR-011 exists to fix elsewhere in this same change).
- Field-name note (same as Task 5): `determinedBy` (not `vastgesteldDoor`) is the real `GoverningDocumentVersie` decision-reference field; `document` (not e.g. `governingDocument`) is the real parent-ref field; `deedDate`/`notary` (not `aktedatum`/`notaris`) are the real notarial-deed fields — the manifest uses the real (English) schema property names throughout; `extraFields` labels render the Dutch-language display labels ("Deed date"/"Notary" — English per ADR-005, the app is English-only code with Dutch domain vocabulary only in seed/display data).

### Task 7: Wire the delegation-chain widget on the Delegations & mandates register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail`
- **files**: `src/manifest.d/delegatie-mandaatregister.json`
- **acceptance_criteria**:
  - `BevoegdheidstoedelingDetail`'s widget layout gains a `delegation-chain` entry bound to `bevoegdheidstoedeling` with `parentRefField: "parentAllocation"`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [x] Implement
- [~] Test — l10n strings not added (see below)

### Task 8: Wire the confidentiality status-timeline widget and fix the index column key on the Confidentiality register
- **spec_ref**: `openspec/changes/register-detail-optimisation/specs/embargo-geheimhouding/spec.md#req-emb-013-geheimhoudingen-index-bekrachtiging-deadline-column-references-the-correct-schema-field`
- **files**: `src/manifest.d/embargo-geheimhouding.json`
- **acceptance_criteria**:
  - `GeheimhoudingDetail`'s widget layout gains a `confidentiality-status-timeline` entry bound to `geheimhouding`
  - The `Geheimhoudingen` index's bekrachtiging-deadline column key changes from `bekrachtigingDeadline` to `ratificationDeadline`
  - Every new/changed user-facing label has `nl_NL` and `en_US` translation strings (ADR-007)
- [x] Implement
- [~] Test — l10n strings not added (see below)

### Task 9: Cross-page regression and accessibility verification
- **spec_ref**: `openspec/changes/register-detail-optimisation/test-plan.md#tc-18-no-console-errors-or-dead-requests-introduced-across-the-four-detail-pages`
- **files**: none (verification task — browser-driven, no new source files)
- **acceptance_criteria**:
  - GIVEN seeded data across all four registers WHEN each of `RegelingDetail`, `RegelingVersieDetail`, `GoverningDocumentDetail`, `BevoegdheidstoedelingDetail`, `GeheimhoudingDetail` is visited THEN no new console errors or failed requests appear and the existing `data`/`files`/`related` widgets on those pages still render correctly
  - GIVEN keyboard-only + screen-reader navigation WHEN traversing the three new widgets THEN every interactive element (timeline links, chain breadcrumb/children, resolved reference links) is reachable with a correct accessible name (WCAG 2.2 AA)
  - Key flows worth ongoing regression coverage (version-timeline navigation, ondermandaat chain traversal, confidentiality status timeline) are promoted to reusable test scenarios via `/test-scenario-create`
- [ ] Implement — **NOT DONE**: this is a live-browser verification task; the implementing agent had no `npm run build`/deploy step available (explicitly withheld — orchestrator rebuilds). See "Live verification checklist" below for exactly what to check once the app is rebuilt and deployed.
- [ ] Test

## Live verification checklist (run after the orchestrator's rebuild/deploy)

For each page below: open it, confirm no new console errors, confirm the new widget renders (not blank, not a stuck spinner), and exercise the one interaction noted.

1. **`RegelingDetail`** (`/regelingen/:id`) — open `afvalstoffenverordening-amsterdam` (seeded, has 2 versions: `afvalstoffen-v1` 2024-01-01 replaced, `afvalstoffen-v2` 2025-06-01 in-effect). Expect: "Version timeline" widget below "Related", both versions listed ascending, `status`/`cvdrIdentifier` foregrounded at the top of the data widget. The version rows' Decision link points at the nil-UUID placeholder decision (`determinedBy: "00000000-0000-0000-0000-000000000000"`, per the fragment's own seed-data note) — expect either a broken/blank resolve (degrades to the raw id, per `resolveObjectLabel`'s contract) or a 404 on click, NOT a JS error. This is a seed-data limitation of the sibling `verordeningenregister` change, not a defect here.
2. **`RegelingVersieDetail`** (`/regeling-versies/:id`) — open any `regeling-versie` object; confirm the existing `data`/`files`/`related` widgets still render (this page did not gain a new widget — only `RegelingDetail` did).
3. **`GoverningDocumentDetail`** (`/governing-documents/:id`) — open `statuten-vng` (seeded, 2 versions: v1 constitutive/no decision, v2 traced to `besluit-statutenwijziging-vng-2021` with `deedDate`/`notary` set). Expect: "Version timeline" widget shows both versions, v1 has no Decision link (no dead link) and shows no deed metadata, v2 shows the deed date + notary and a working link to "Besluit statutenwijziging VNG 2021".
4. **`GoverningDocuments` index** (`/governing-documents`) — confirm it still renders (no current-in-force-date column was added — see Task 6's NOT IMPLEMENTED note).
5. **`BevoegdheidstoedelingDetail`** (`/bevoegdheidstoedelingen/:id`) — open `ondermandaat-subsidies-samenleving` (seeded, `parentAllocation: mandaat-subsidies-secretaris`). Expect: breadcrumb shows the parent ("mandaat-subsidies-secretaris"'s subject text) → current, a working link on the ancestor, subject/delegans("gemeentesecretaris" description text)/delegataris("afdelingshoofd Samenleving") shown. Then open `mandaat-subsidies-secretaris` itself: expect its child list to show `ondermandaat-subsidies-samenleving`.
6. **`GeheimhoudingDetail`** (`/geheimhoudingen/:id`) — open `geheimhouding-raadsnota-grondexploitatie` (seeded: `lifecycle: imposed`, `imposedAt` set, `ratificationDeadline: 2026-09-30`, no ratification/dissolution fields). Expect: imposed stage populated, ratification stage pending (overdue only if "today" in the live environment is past 2026-09-30), dissolution stage pending, ground resolves to "Geheimhouding raadsstukken — Gemeentewet art. 87-89" with "Formerly: voorheen art. 25", target resolves to the placeholder document id (nil UUID — degrades to raw id, not a working link per the Task 3 deviation note above).
7. **`Geheimhoudingen` index** (`/geheimhoudingen`) — confirm the "Bekrachtiging deadline" column now shows real dates (previously blank/broken under the `bekrachtigingDeadline` key mismatch).
8. **Keyboard/screen-reader pass**: Tab through each of the three new widgets on the pages above; confirm every button (decision links, breadcrumb ancestors, child links, target link) is reachable and its visible text IS its accessible name (no widget in this change uses a mismatched `aria-label` — deliberately, per the "explicit aria-labels replace accessible names" instruction).

## Quality checklist

- [x] All new Vue components covered by component/unit tests (Vitest) for the resolve/sort/walk logic (version ordering, ancestor/child chain walk with cycle safety, three-stage timeline branching) — `tests/vitest/registerDetailWidgets.spec.js`, 26 tests, all passing. **Scope note**: the pure algorithms are tested directly; the `.vue` components themselves cannot be imported/mounted by this repo's Vitest config (no `@vitejs/plugin-vue` registered — confirmed empirically), so this is logic-level coverage, not a mounted-component smoke test. See Task 4's deviation note for why the algorithms live in `registerDetailWidgets.js` rather than inline in each SFC.
- Newman/Postman: N/A — no new or changed API endpoints (pure frontend change reading existing OpenRegister endpoints unchanged)
- [ ] UI changes covered by Playwright browser tests (test-plan.md TC-1 through TC-18) — NOT DONE, no build/deploy available to this agent; see Live verification checklist above
- [x] All local tests pass (`npm run test:unit` → 322/322 passing incl. the 26 new; `npx eslint src` → 0 errors, 240 pre-existing warnings, exit 0; `npx prettier --check` on every touched file → clean)
- [ ] Feature documentation updated in `docs/` for the four register detail pages' new presentation (ADR-010) — NOT DONE; `docs/` is outside this task's file-edit scope
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added (orchestrator, 2026-08-19: `check-l10n.js --write` extracted 34 keys into en.json; 34 Dutch translations added to nl.json preserving its sort/indent convention; `npm run test:l10n` OK). Original blocked-by-scope note: `l10n/en.json` / `l10n/nl.json` are not in this change's allowed edit list. Every user-facing string in the three widgets uses `t('decidesk', '...')` (the standard pattern — see any string in `RegisterVersionTimelineWidget.vue`/`DelegationChainWidget.vue`/`ConfidentialityStatusTimelineWidget.vue`), so `node tests/l10n/check-l10n.js` WILL currently fail (missing keys). Run `node tests/l10n/check-l10n.js --write` to extract every new key into `l10n/en.json`, then translate them into `l10n/nl.json` (existing Dutch translations already ship for `l10n/nl.json`'s other ~1000+ keys, so the project's normal translation workflow applies) before shipping to production.
- [x] `openspec validate` — run by the orchestrator 2026-08-19, strict, valid.

## Summary of files changed by this implementation pass

- `src/components/widgets/RegisterVersionTimelineWidget.vue` (new)
- `src/components/widgets/DelegationChainWidget.vue` (new)
- `src/components/widgets/ConfidentialityStatusTimelineWidget.vue` (new)
- `src/components/widgets/registerDetailWidgets.js` (new)
- `src/main.js` (edit: `registerDetailWidgets()` async call wired into the boot IIFE, awaited before mount)
- `src/manifest.d/verordeningenregister.json` (edit: `+version-timeline` widget on `RegelingDetail`, `+overrides` foregrounding `status`/`cvdrIdentifier` on `regeling-data`, `+format:"date"` on the `Regelingen` index's `currentInwerkingtreding` column)
- `src/manifest.d/governing-documents-register.json` (edit: `+version-timeline` widget on `GoverningDocumentDetail` with `extraFields` for `deedDate`/`notary`; `GoverningDocuments` index current-in-force-date column NOT added, see Task 6)
- `src/manifest.d/delegatie-mandaatregister.json` (edit: `+delegation-chain` widget on `BevoegdheidstoedelingDetail`)
- `src/manifest.d/embargo-geheimhouding.json` (edit: `+confidentiality-status-timeline` widget on `GeheimhoudingDetail`; `Geheimhoudingen` index column key `bekrachtigingDeadline` → `ratificationDeadline` with `format:"date"`)
- `tests/vitest/registerDetailWidgets.spec.js` (new — 26 tests covering the pure logic)
- `openspec/changes/register-detail-optimisation/tasks.md` (this file)

## Static verification note (post-hoc, non-implementing agent, 2026-08-19)

**Task 5 acceptance criterion "declares `format: date`" is technically met but REQ-VOR-011 is NOT actually fixed.** `src/manifest.d/verordeningenregister.json`'s `Regelingen` index column uses `"key": "currentInwerkingtreding"` (pre-existing since commit `bceed39b`, predating this change — this change only added `"format": "date"` to that pre-existing key, per `git log -p`). But no object ever carries a `currentInwerkingtreding` property: the actual `Regeling` schema property (declared in `lib/Settings/register.d/53-verordeningenregister.json:221-229`, itself already `"format": "date"` at the schema level) and the seed data (`lib/Settings/register.d/53-verordeningenregister.json:19,32`) both use `currentEffectiveDate`. `CnDataTable.getCellValue(row, key)` (`node_modules/@conduction/nextcloud-vue/src/components/CnDataTable/CnDataTable.vue:902-914`) reads `row[key]` directly (dot-path, no aliasing) — so `row.currentInwerkingtreding` is `undefined` for every row and the column renders **blank**, not a formatted date. This is the exact "a field OR value rename is invisible" failure mode Task 5's own `determinedBy`/`vastgesteldDoor` deviation note explicitly guards against, just missed here because the mismatched key was pre-existing rather than introduced by this pass. Not a regression introduced by `register-detail-optimisation`, but the column-key fix that REQ-VOR-011's scenario actually requires ("renders a locale-formatted date... not the raw ISO/SQL string") was never made — recommend a follow-up one-line fix (`key: "currentEffectiveDate"`) before relying on this column.

**Minor:** the widgets' `formatDate`/`formatDateTime` are not a genuinely *shared* utility as the Task 1 acceptance text says — each of the three `.vue` files defines its own identical local `methods.formatDate`/`formatDateTime` (`new Date(value).toLocaleDateString()`/`.toLocaleString()`), not an import from one common module (a pre-existing `formatDate` already lives at `src/components/userSettings/userPreferences.js:156` but is not the one used here). Functionally harmless (no raw `Date` stringification reaches the DOM in any of the three widgets — confirmed by reading all three `formatDate`/`formatDateTime` methods), just not literally "shared."
