# Design: ux-debt-rendering

## Architecture Overview

decidesk's index pages are declared in `src/manifest.d/*.json` fragments (merged onto `src/manifest.json` by `main.js::mergeManifestFragments`, ADR-037) and rendered by `@conduction/nextcloud-vue`'s `CnIndexPage` → `CnDataTable` → `CnCellRenderer` chain. `CnCellRenderer` resolves each cell through, in order: an explicit `widget` (consumer-registered, or the built-ins `badge`/`fkResolve`/`link`), an explicit `formatter` (resolved against the injected `cnFormatters` registry — `BUILT_IN_FORMATTERS` merged with whatever `App.vue` passes as the `formatters` prop to `CnAppRoot`), a declarative `format` spec (`currency`/`number`/`percent`/`duration`/`swatch`), then type-aware rendering driven by the matched `schema.properties[key]` entry (dates via `NcDateTime`, enums via `CnStatusBadge`, etc.), finally falling back to `formatValue()` in `utils/schema.js`.

Every item in this change is a fix at one of these three layers:
- **Manifest column config** (items 1, 2 formatter-application, 3, 4-mitigation) — no library change.
- **App-level formatter registration** (item 2) — one small additive file + one prop wire, no library change.
- **Investigation-only / no code change needed there** (item 5 — could not reproduce; item 6 — targets already resolve).
- **Data fix** (item 7) and **test-file convention** (item 8).

The one item that is NOT a decidesk-layer fix (item 4's root cause) is documented in its own section below rather than worked around with a manifest hack that hides the real defect.

## Goals / Non-Goals

**Goals:** fix the eight live-observed rendering defects at the correct layer; leave a clean paper trail (this design.md) for the two items that are blocked on other repos, so nobody re-derives the investigation; add regression coverage where a fix was made and a guard where a symptom could not be reproduced.

**Non-Goals:** modifying `@conduction/nextcloud-vue` or OpenRegister source (out of repo); rebuilding the walkthrough to cover all six clusters (product decision, not a bug fix); bulk-cleaning existing e2e fixture pollution (hard safety rule — only the one identified malformed object is touched); making `fkResolve` handle the literal nil-UUID placeholder specially (nc-vue nice-to-have, not blocking, noted in "Nc-vue / OpenRegister blocked items").

## Decisions

### Decision 1: Fix raw UUIDs via existing `fkResolve` widget, not a new component

**Chosen:** Add `widget: "fkResolve"` + `widgetProps: {register: "decidesk", schema: "<target-schema-slug>", labelField: "name"}` (or the schema's actual title field, e.g. `title` for `decision`) to every affected column.

**Alternative considered:** A new decidesk-side Vue cell-widget component mirroring `CnFkResolveCell`. Rejected — `fkResolve` already exists in the pinned `@conduction/nextcloud-vue@2.3.0`, already resolves both UUID and slug-keyed values (confirmed live: `GET .../objects/decidesk/governance-body/gemeenteraad-amsterdam` → 200; the object store's `fetchObject` hits OpenRegister's single-object endpoint, which resolves by slug OR id), and already has per-schema caching + in-flight dedup via `useObjectStore`. Building a parallel component would duplicate all of that for no benefit.

**Target schema/labelField per column** (verify each against `GET /apps/openregister/api/schemas` at implementation time — schema slugs in this repo are inconsistently PascalCase-vs-kebab-vs-camelCase across register.d files, so do not hardcode from this table without checking):

| Manifest fragment | Page | Column key | Target schema (PascalCase name in register.d) | Suggested `labelField` |
|---|---|---|---|---|
| `vragenuur-interpellatie.json` | MondelingeVragen | `submitter` | Person | `name` |
| `vragenuur-interpellatie.json` | MondelingeVragen | `portefeuillehouder` | Person | `name` |
| `vragenuur-interpellatie.json` | Interpellaties | `requester` | Person | `name` |
| `vragenuur-interpellatie.json` | Interpellaties | `portefeuillehouder` | Person | `name` |
| `toezeggingen-ingekomen-stukken.json` | Toezeggingen | `madeBy` | Person | `name` |
| `toezeggingen-ingekomen-stukken.json` | Toezeggingen | `directedTo` | GovernanceBody | `name` |
| `works-council-consultation.json` | WorTrajecten | `bestuurder` (verify exact key) | GovernanceBody or Person | `name` |
| `raadsinformatiebrieven.json` | Raadsinformatiebrieven | `portefeuillehouder` | Person | `name` |
| `advisory-opinion-workflow.json` | Adviesaanvragen | `requestingBody` | GovernanceBody | `name` |
| `advisory-opinion-workflow.json` | Adviesaanvragen | `advisoryBody` | GovernanceBody | `name` |
| `vve-alv-pack.json` | VveConfigurations | `body` (verify exact key) | GovernanceBody | `name` |
| `vve-alv-pack.json` | KascommissieVerklaringen | `governanceBody` | GovernanceBody | `name` |
| `verordeningenregister.json` | Regelingen | `determiningBody` | GovernanceBody | `name` |
| `governing-documents-register.json` | GoverningDocuments | `governingBody` | GovernanceBody | `name` |
| `delegatie-mandaatregister.json` | Bevoegdheidstoedelingen | `delegans` | GovernanceBody | `name` |
| `embargo-geheimhouding.json` | Geheimhoudingen | `imposedByBody` | GovernanceBody | `name` |
| `embargo-geheimhouding.json` | Geheimhoudingen | `ground` | GeheimhoudingGrond | `citation`/`name` |
| `member-proxy-authorization.json` | ProxyAuthorizations | `holder` | Participant | `name` |
| `member-proxy-authorization.json` | ProxyAuthorizations | `meeting` | Meeting | `title` |
| `organisation-goals.json` | Goals | `body` | GovernanceBody | `name` |
| `shared-governance-bodies.json` | Zienswijzerondes | `sharedBody` | GovernanceBody | `name` |
| `shared-governance-bodies.json` | Zienswijzen | `participant` | GovernanceBody | `name` |
| `termijnagenda.json` | Termijnagenda | `governanceBody` | GovernanceBody | `name` |
| `pc-cyclus.json` | PCCycli | `governanceBody` | GovernanceBody | `name` |
| `woo-diwoo-publication.json` | WooBestuursorganen | `governingBody` | GovernanceBody | `name` |
| `interests-and-integrity.json` | MyDeclarations | `governanceBody` | GovernanceBody | `name` |
| `interests-and-integrity.json` | Nevenfuncties | `governanceBody` | GovernanceBody | `name` |

This table was produced by a static sweep cross-referencing every index column key against its schema's `properties[key].format === 'uuid'` / `$ref` / name-hints (see proposal.md Motivation). It is a starting point, not a guarantee — some rows (`bestuurder`, VvE `body`, `member-onboarding` `targetBody`/`body`, `appointments-and-terms` roster fields) had schema slugs the sweep script could not resolve (kebab-case manifest slug vs PascalCase register.d name); confirm the exact column key and target schema live before wiring `widgetProps`, and extend this table with any additional reference column the sweep missed.

### Decision 2: Year formatting via a new app-registered formatter, not a nc-vue change

**Chosen:** Add `formatters: { plainYear: (value) => (Number.isFinite(Number(value)) ? String(Math.trunc(Number(value))) : String(value)) }` in a new `src/utils/cellFormatters.js`, import it into `App.vue`, and pass `:formatters="cellFormatters"` on the `CnAppRoot` mount (currently absent — `App.vue` passes `manifest`, `registry`, `pageTypes`, `translate`, `permissions`, but no `formatters`). Apply `"formatter": "plainYear"` to the `year` column in `pc-cyclus.json`'s PCCycli index and the `boekjaar` column in `vve-alv-pack.json`'s Kascommissie index.

**Alternative considered:** A column-level `format: {style: 'number', decimals: 0}` override. Rejected after reading `CnCellRenderer.applyBuiltinFormat()` — it always routes numeric styles through `Intl.NumberFormat`, whose default `useGrouping` still groups a 4-digit value (`new Intl.NumberFormat(undefined, {minimumFractionDigits:0,maximumFractionDigits:0}).format(2026)` → `"2,026"`). There is no `useGrouping:false` escape hatch in the current `format` spec, so this path cannot produce a plain year without a library change.

**Alternative considered:** Retype the schema property from `integer` to `string`. Rejected — these are genuinely numeric fields (used in comparisons/sorting/filtering elsewhere), and retyping is a schema change outside this change's scope (and would ripple into the owning registers' own in-flight changes).

### Decision 3: Date-format sweep applies column-level `format` hints, matching `register-detail-optimisation`'s established pattern

**Chosen:** For every column identified by the repo-wide sweep (script output below), add `"format": "date"` (or `"date-time"` for datetime-typed fields) to the column entry. This is the exact mechanism `register-detail-optimisation` already used for `regeling`'s `currentInwerkingtreding` — no new pattern introduced.

**Full sweep output** (file, page id, column key — verify each against the live schema's actual `format` before assuming it needs a hint; some may already carry `format: "date-time"` at the schema level and only need the CELL to be double-checked, not necessarily a column override):

```
advisory-opinion-workflow.json   Adviesaanvragen        sentDate, requestedByDate
appointments-and-terms.json      Roosters                generatedOn, publicationDate
appointments-and-terms.json      Roosterregels           endTermDate
constituency-consultation.json   Raadplegingen           closesAt
embargo-geheimhouding.json       Geheimhoudingen         imposedAt
interests-and-integrity.json     MyDeclarations          reviewedAt
member-onboarding.json           OffboardingTrajecten    endDate
organisation-goals.json          Goals                   deadline
raadsinformatiebrieven.json      Raadsinformatiebrieven  sentAt
toezeggingen-ingekomen-stukken.json  Toezeggingen        deadline
toezeggingen-ingekomen-stukken.json  IngekomenStukken    receivedAt
urgent-decision-procedure.json   UrgentDecisions         urgencyDeclaredAt
works-council-consultation.json  WorTrajecten            receivedDate, requestedResponseDate
```

(This list intersects with, but is not identical to, Decision 1's reference-column table — `delegatie-mandaatregister.json`'s `delegateRole` and a handful of other name-hinted-but-not-actually-date fields were excluded after manual review; verify each entry against its live schema rather than blindly adding a `format` key.)

### Decision 4: Item 4 (empty-state defect) gets a decidesk-side mitigation, not a decidesk-side fix

**Reproduction (live, 2026-08-19):**
1. Navigated to `/apps/decidesk/bevoegdheidstoedelingen` (Delegations & mandates). Page shows the loading spinner and never transitions.
2. Network trace shows two requests to the same collection endpoint:
   - `GET .../objects/decidesk/bevoegdheidstoedeling?_limit=20&_page=1&_order=...&_facets=extend` — completes in 1.4s (confirmed via direct `curl`), real data.
   - `GET .../objects/decidesk/bevoegdheidstoedeling?_facets=extend` (no `_limit`, no `_page`) — never completes. Direct `curl --max-time 20` against the exact same URL returns exit 28 (timeout), zero bytes.
3. Traced to `store/plugins/liveUpdates.js` in `@conduction/nextcloud-vue`: the collection-subscription dispatcher is `const lastParams = store.__lastCollectionParams?.get(type) || {}; store.fetchCollection(type, lastParams)`. When this dispatch fires before the first real (paginated) fetch has stashed its params — plausible at mount, since `useObjectSubscription` subscribes independently of the list fetch's own lifecycle — it calls `fetchCollection(type, {})`. `useObjectStore.fetchCollection()` auto-appends `_facets=extend` whenever the schema has any `facetable` property and no `_facets` was passed, with no `_limit` guard.
4. `fetchCollection` sets `this.loading = {...this.loading, [type]: true}` unconditionally at the top of the call, and this `loading[type]` flag is the SAME flag `CnIndexPage`'s `effectiveLoading`/`showInitialLoader` reads for every consumer of that object type. The hung bare request never reaches its `finally`-equivalent (there isn't one — the function only clears loading on the success/catch paths, both unreached while the fetch is in flight), so `loading[type]` stays `true` forever even though the real list fetch already completed and populated data.
5. This is consistent with `register-detail-optimisation`'s earlier finding that `CnIndexPage`'s loading/empty-state branching is "structurally sound" — the defect is not in that branching logic at all, it is in what feeds the `loading` flag.

**Chosen mitigation (this change):** Set `"subscribe": false` in the `config` of `delegatie-mandaatregister.json`'s `Bevoegdheidstoedelingen` page and `member-proxy-authorization.json`'s `ProxyAuthorizations` page — `useSelfFetchList.js` already reads this exact opt-out (`useObjectSubscription(objectStore, objectType, null, { enabled: () => props.subscribe !== false })`), so this requires no library change. This stops the race from firing on these two pages; the pages lose "another user's edit appears without reload" until the upstream fix lands, which is a strictly better trade than a page that never loads.

**Not chosen:** Applying `subscribe: false` fleet-wide to every index page with a facetable schema. The defect is a timing race (not reproduced on the Urgent decisions page in the same session, which shares the same shape), so blanket-disabling live-updates would trade away a real feature everywhere to guard against a symptom only confirmed on two pages. Scoped narrowly per the proposal's Open Questions; revisit if more pages are found to exhibit it.

### Decision 5: Item 5 (filter-chip) gets a regression guard, not a speculative fix

Live-tested the "All urgent" quick-filter (dropdown mode) at 1280px, 900px, and 375px viewports. In every case: `getComputedStyle()` on `.vs__selected` shows `white-space: normal; word-break: normal; overflow-wrap: normal`, text width 83px inside a 156px container — no CSS forces a break, and there is no width pressure. The symptom described in the audit ("All u rgent") did not reproduce. Per the working-style rule against fabricating fixes for unmeasured defects, this change adds a Playwright assertion (quick-filter label `textContent` matches its manifest-declared label exactly, no embedded newline/split) as a regression guard, and leaves a note in tasks.md to re-open this as a live-reproduction investigation if it recurs (possible causes not ruled out: a transient render during initial hydration, a font/zoom-dependent browser difference, or the symptom having already been fixed by an unrelated change since the audit).

### Decision 6: Item 6 (walkthrough) — content review only, no target changes

`CnWalkthrough.resolveTarget()` resolves `kind:"page"` via route match, `kind:"nav-item"` via the same, and `kind:"element"` via `[data-walkthrough-id="…"]`/`[data-testid="…"]`. Checked all four `decidesk:getting-started` steps: `Dashboard` (page, resolves), `Meetings` (nav-item, confirmed present in the current 8-entry top-level menu), `index-add` (element — confirmed present as `data-walkthrough-id="index-add"` on `CnActionsBar`'s primary Add button), `Dashboard` again. All four resolve against the current build; no target/ref edit is needed. The task for this item is a copy review only (does the tour's language still make sense next to the other five clusters — Decisions, Tasks & Commitments, Factions & bodies, Registers — introduced by the "Back to Six" navigation restructuring), not a structural rebuild.

### Decision 7: Item 7 (seed title) — correct in place, don't delete, unless proven safe to delete

Identified object: `governing-document` id `1bd244dd-0f7a-4bf9-9c68-c7531623324a`, slug `reglement-van-orde-raad-amsterdam`, missing `citeertitel`/`title`, `@self.description: "4"` (stray), created 2026-08-15 (five days after the other three seed objects, all created 2026-07-18). This is a near-duplicate of the legitimately-seeded object `66cc5c1e-0c96-40fb-af1a-32cd62cff97b` (slug `reglement-van-orde-gemeenteraad-amsterdam`, same governing body, same content, real `citeertitel`). Task: grep `tests/e2e/spec-coverage/*.spec.ts` for any reference to either id/slug; if unreferenced, delete the duplicate (single, specifically-identified object — not a bulk operation); if referenced, patch its `citeertitel`/`title` field to match the real object instead of deleting it, and note that the referencing spec should also be fixed to use the marker convention from Decision 8 instead of creating an untracked duplicate.

### Decision 8: Item 8 (fixture pollution) — naming convention + targeted cleanup-hook additions, no bulk delete

`tests/e2e/ci-seed.sh` provisions the LEGITIMATE seed data (register/schema `example` blocks from `lib/Settings/register.d/*.json`) — that is versioned, reproducible, and not the problem. The reported pollution (44 meetings, 37 bodies, 11 consultations) comes from `tests/e2e/spec-coverage/*.spec.ts` creating objects directly against the shared instance; only 4 of 33 spec files have any cleanup hook today.

**Chosen convention:** every object a spec creates directly (not via `ci-seed.sh`) gets its name/title prefixed with a stable marker, e.g. `[e2e]` (grep-able, visually distinct in the UI so a human browsing the shared instance can immediately tell a row is test debris). Specs that create objects add an `afterEach`/`afterAll` hook deleting anything they created (tracked via the response id, not a query-by-marker delete — avoids ever deleting something the spec didn't create). This is a per-spec-file change, not a global sweep; per the proposal's Open Questions, this change fixes the direct contributors to the observed pollution (the meeting/body/consultation/governing-document creating specs) and documents the convention (in this design.md + a short comment block at the top of `tests/e2e/ci-seed.sh` or a new `tests/e2e/README.md` fixture-conventions note) for the remaining spec files as follow-up debt.

**Not chosen:** a scheduled/automatic bulk cleanup job that deletes anything matching the marker. Hard safety rule against bulk deletion of test fixtures — even marker-scoped, an automatic job is a standing risk if the marker convention is ever violated by a future spec. A manual, marker-filtered cleanup remains something an operator can run deliberately, documented but not automated by this change.

## Nc-vue / OpenRegister blocked items

These are NOT implemented by this change — they live in other repos and are collected here so the investigation is not lost. `@conduction/nextcloud-vue` publishes only from `main`/`beta`, and decidesk's app CI installs from npm — an unreleased lib fix would fail decidesk's CI and cannot be worked around app-side, per the flow-editor-consolidation lesson (2026-08-19 memory).

1. **`@conduction/nextcloud-vue` — `liveUpdatesPlugin` collection dispatcher fires with unstashed params.** `store/plugins/liveUpdates.js`, the `dispatch` closure for a collection subscription: `const lastParams = store.__lastCollectionParams?.get(type) || {}`. Recommended fix: either skip the dispatch entirely until `__lastCollectionParams` has a real entry for `type` (the first real fetch hasn't happened yet, so there's nothing to "re"-fetch), or seed the fallback with the composable's actual default page size instead of `{}`. Either fix independently prevents the bare, unbounded `fetchCollection(type, {})` call that item 4 traces to.
2. **OpenRegister — `_facets=extend` with no `_limit`/`_page` hangs indefinitely.** `GET /apps/openregister/api/objects/{register}/{schema}?_facets=extend` (no other params) does not return within 20s (confirmed via direct `curl --max-time 20`, exit 28, zero bytes) against a schema with real data and a working paginated equivalent (`?_limit=20&_page=1&_facets=extend` returns in 1.4s). This is the actual trigger — the nc-vue fix above stops decidesk from hitting it by accident, but does not fix the hang itself, and any other caller of `fetchCollection(type, {})` (there are several documented call sites: `CnLogsPage`, `CnAppRoot`'s admin sync check) would hit the same wall. Recommend OpenRegister either bound facet computation to a sample/default limit regardless of the caller's `_limit`, or fail fast with a timeout instead of hanging the HTTP connection.
3. **`CnFkResolveCell` nice-to-have — special-case the nil UUID.** `00000000-0000-0000-0000-000000000000` is used as an "unset" placeholder in several registers' `example` seed blocks (`besluit`, `decision` cross-references authored before the target objects existed). `CnFkResolveCell.fetchOne()` correctly attempts to resolve it, gets a 404, and falls back to the raw id — so after this change's Decision 1 fixes ship, these specific cells will still show the literal nil UUID (not a UUID that fails to resolve, and not blank). A small nc-vue enhancement (treat the well-known nil UUID as equivalent to an absent value, render "—") would clean this up without needing every reference-holding register to backfill its placeholder examples with real ids. Non-blocking — the fkResolve widget still fixes the overwhelming majority of raw-UUID cells without this.

## Security Considerations

No security impact — this change touches manifest column rendering config, a client-side formatter, one seed-data object correction, and test-file conventions. No new endpoints, no auth changes, no new data exposure (fkResolve reads objects the user's existing RBAC already permits, through the same `useObjectStore` every other index page already uses).

## NL Design System

No new components. `fkResolve`, date formatting, and quick-filter rendering all already use Nextcloud CSS custom properties (verified in `CnCellRenderer`'s `<style scoped>` block — `var(--color-text-maxcontrast)` etc.); no hardcoded colors are introduced.

## File Structure

```
src/
  App.vue                          # add :formatters="cellFormatters" prop
  utils/
    cellFormatters.js              # NEW — plainYear formatter
  manifest.json                    # walkthrough copy review only
  manifest.d/
    vragenuur-interpellatie.json           # fkResolve columns
    toezeggingen-ingekomen-stukken.json    # fkResolve columns + date format
    works-council-consultation.json        # fkResolve column + date format
    raadsinformatiebrieven.json            # fkResolve column + date format
    advisory-opinion-workflow.json         # fkResolve columns + date format
    vve-alv-pack.json                      # fkResolve columns + plainYear (boekjaar)
    verordeningenregister.json             # fkResolve column
    governing-documents-register.json      # fkResolve column
    delegatie-mandaatregister.json         # fkResolve column + subscribe:false
    embargo-geheimhouding.json             # fkResolve columns + date format
    member-proxy-authorization.json        # fkResolve columns + subscribe:false
    organisation-goals.json                # fkResolve column + date format
    shared-governance-bodies.json          # fkResolve columns
    termijnagenda.json                     # fkResolve column
    pc-cyclus.json                         # fkResolve column + plainYear (year)
    woo-diwoo-publication.json             # fkResolve column
    interests-and-integrity.json           # fkResolve columns + date format
    appointments-and-terms.json            # date format
    constituency-consultation.json         # date format
    member-onboarding.json                 # date format
    urgent-decision-procedure.json         # date format
tests/e2e/
  spec-coverage/                   # marker convention + cleanup hooks on direct-creator specs
```

## Seed Data

No new schemas or entities are introduced by this change — the ADR-001 seed-data requirement does not apply. The one seed-data action here is a correction, not new seed content: see Decision 7 (fix or remove the malformed `governing-document` object `1bd244dd-0f7a-4bf9-9c68-c7531623324a`).

## Declarative-vs-imperative decision (ADR-031)

Not applicable — this change introduces no lifecycle/state-machine, aggregation, derived/calculated field, notification, or declarative-relation behavior. It is purely presentation-layer (manifest column config + one client-side formatter).

## Trade-offs

- **Manifest-only fix for items 1–3 vs. a shared nc-vue enhancement:** a library-level "auto-resolve any uuid/reference-typed column" default would fix this class of defect fleet-wide with zero per-column config. Not pursued here because it's a bigger, cross-app design decision (default behavior change affects every app pinning this lib version) and would still be blocked on nc-vue's release cadence; the manifest-column approach ships immediately and is the documented, existing pattern (`register-detail-optimisation` already used it for dates).
- **`subscribe: false` vs. waiting for the upstream fix:** trades live-refresh for correctness-of-loading-state on exactly two pages. Chosen because a page that never loads is a worse defect than a page that needs a manual reload to see another user's edit.
- **Targeted e2e cleanup vs. full retrofit:** fixing all 33 spec files' cleanup hooks in one PR would be a much larger, higher-risk change touching test infrastructure broadly. Scoped to the direct contributors per the proposal's Open Questions; the convention is documented so the remaining retrofit is well-defined follow-up work, not rediscovered debt.
