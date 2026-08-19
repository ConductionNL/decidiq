---
kind: code
---

# Proposal: ux-debt-rendering

## Summary

Fixes eight pre-existing rendering/UX defects on decidesk's index pages, live-observed on the shared :8080 instance on 2026-08-19: raw reference UUIDs shown instead of resolved names on 8+ index pages, years rendered with a thousands separator ("2,026"), raw unformatted datetimes on two more registers, a perpetual-loading defect on two index pages that is traced to a real frontend/backend race (not a manifest bug), a broken filter-chip investigation, a stale first-run walkthrough audit, one malformed seed object, and an e2e-fixture-pollution convention gap. Every item was root-caused against the actual rendering pipeline (`CnCellRenderer`/`CnDataTable`/`CnIndexPage`/`useObjectStore` in `@conduction/nextcloud-vue`) before any fix was planned — six items resolve with decidesk-side manifest/config changes using capabilities that already exist in the pinned library version; one item is a genuine frontend-race + backend-hang defect that gets an immediate decidesk-side mitigation plus a properly-owned follow-up filed against `@conduction/nextcloud-vue` and OpenRegister; one item could not be reproduced on the current build and is left as a regression guard rather than a blind CSS patch.

## Motivation

decidesk ships 15+ index pages built on the manifest system's declarative `type:"index"` pages. A live audit on 2026-08-19 found eight classes of rendering debt that make the app look broken or untrustworthy even though the underlying data is correct — raw UUIDs (including the literal nil UUID `00000000-0000-0000-0000-000000000000`) where a person or governance body name should appear, a "2,026" year, unformatted `2025-03-01 00:00:00` timestamps, and two index pages (Delegations & mandates, Proxy authorizations) that render neither a table nor an empty state, which is indistinguishable from the app being broken.

Root-causing each item against the shared rendering path (rather than patching per page) found:

- **Raw UUIDs (item 1)**: `CnCellRenderer` already ships a built-in `widget:"fkResolve"` (backed by `CnFkResolveCell`, resolving through the shared `useObjectStore`) that resolves a reference id — UUID **or** slug, both confirmed live — to the target object's label. None of the affected columns declare it. This is pure manifest column-config adoption, no library change needed.
- **Years with thousands separators (item 2)**: `formatValue()` in `@conduction/nextcloud-vue`'s `utils/schema.js` calls `num.toLocaleString()` for any schema property typed `integer`/`number` with no override — confirmed by reading the source. A `format:{style:'number'}` column override does not fix this (`Intl.NumberFormat`'s default `useGrouping` still groups a 4-digit value). The fix is a small app-registered cell formatter (decidesk's `App.vue` does not currently pass the `formatters` prop to `CnAppRoot` at all — this is new, additive wiring, not a library gap).
- **Raw datetimes (item 3)**: `register-detail-optimisation` (in-flight sibling change) already root-caused and partially fixed this — `CnDataTable`/`CnCellRenderer` only apply date formatting from a matched `schema.properties` entry; a column with no `format:"date"` schema property (or no explicit column `format` override) falls through to plain-text rendering. A repo-wide sweep found 25 more index columns across 15 manifest fragments with the same gap.
- **Empty-state defect (item 4)**: Reproduced live in a browser session against the shared instance. Delegations & mandates hangs on the loading spinner forever — never shows a table, never shows "No items found" — even though `GET .../objects/decidesk/bevoegdheidstoedeling?_limit=20&_page=1...` returns real data in 1.4s. A second, *separate* network request fires with **only** `?_facets=extend` (no `_limit`, no `_page`) and never resolves (confirmed via `curl --max-time 20`: exit 28, no response). Traced to `@conduction/nextcloud-vue`'s `liveUpdatesPlugin`: its collection-refetch dispatcher falls back to `store.__lastCollectionParams?.get(type) || {}` when no params have been stashed yet, and can fire before the first real fetch completes — a bare `fetchCollection(type, {})` auto-appends `_facets=extend` (the schema has facetable properties) with no `_limit`, and OpenRegister's facets computation hangs on an unbounded query. Because `loading[type]` is a single shared flag per object type, the hung bare request leaves the page stuck loading forever even though the real, correctly-paginated fetch already succeeded. This is a genuine two-repo defect (`@conduction/nextcloud-vue` race + OpenRegister unbounded-facets hang), **not** a decidesk manifest defect — matching what `register-detail-optimisation`'s earlier investigation already concluded ("CnIndexPage structurally sound, no page-declaration defect"). decidesk can mitigate immediately (`config.subscribe: false` on the two affected pages, an existing opt-out already read by the composable) while the proper fix is filed against the two owning repos.
- **Filter chip (item 5)**: Could not reproduce on the current build. The "All urgent" dropdown-mode quick filter renders as one un-split label at every tested viewport (375px–1280px), with `white-space:normal; word-break:normal; overflow-wrap:normal` and ample container width (83px of 156px used). No CSS rule forces a mid-word break. Documented as an unreproduced symptom with a regression-guard task rather than a speculative fix.
- **Walkthrough (item 6)**: All four step targets (`Dashboard` page, `Meetings` nav-item, `index-add` element via `data-walkthrough-id` on `CnActionsBar`'s primary Add button, `Dashboard` page again) were checked against `CnWalkthrough.vue`'s `resolveTarget()` and the current manifest — all four still resolve. The tour is mechanically intact; the residual gap is that it only demos the Meetings cluster and was never updated to acknowledge the other five clusters from the "Back to Six" navigation restructuring.
- **Seed title (item 7)**: Found the exact object — a `governing-document` (`id: 1bd244dd-...`, slug `reglement-van-orde-raad-amsterdam`) missing its `citeertitel`/`title` field entirely, with a stray `"description": "4"` in its `@self` metadata and a `created` timestamp (2026-08-15) five days after the other three seeded governing documents (2026-07-18). This is not a formatting bug — `formatValue()` correctly renders "—" for a genuinely absent value. It is a near-duplicate of the real "Reglement van orde gemeenteraad Amsterdam" object, almost certainly left behind by an e2e test run against the shared instance — the same root cause as item 8.
- **Fixture pollution (item 8)**: `tests/e2e/ci-seed.sh` provisions the register/schema *definitions* (including their illustrative `example` objects) from `lib/Settings/register.d/*.json` — that is the legitimate, versioned seed data. The reported pollution (44 meetings, 37 bodies, 11 consultations, the orphaned governing-document above) comes from `tests/e2e/spec-coverage/*.spec.ts` files creating objects directly against the shared :8080 instance with no teardown: only 4 of 33 spec files have any `afterEach`/`afterAll`/delete-object cleanup at all.

## Affected Projects

- [ ] Project: `decidesk` — manifest column-config edits (~25 columns across ~15 `src/manifest.d/*.json` fragments), one new small `App.vue`/`src/utils/` cell-formatter registration, two `config.subscribe: false` mitigations, one seed-data object correction, one e2e-fixture naming convention, and a walkthrough content review. No PHP changes, no OpenRegister schema changes.

## Scope

### In Scope

1. Add `widget:"fkResolve"` (+ `widgetProps: {register, schema, labelField}`) to every index column that renders a raw reference id today (~25 columns across ~15 manifest fragments — Oral questions submitter/portefeuillehouder, Interpellations requester/portefeuillehouder, Commitments madeBy, WOR bestuurder, RIB portefeuillehouder, Advisory opinions requestingBody/advisoryBody, VvE config body, Kascommissie body, plus Regulations determiningBody, Governing documents governingBody, Delegations delegans, Confidentiality register imposedByBody/ground, and others found by the sweep).
2. Register a small app-level cell formatter (e.g. `plainYear`) via a new `formatters` prop wired into `CnAppRoot` from `App.vue`, and apply it to the two integer year-typed columns that currently show a thousands separator (P&C cycles `year`, Kascommissie `boekjaar`).
3. Add `format:"date"`/`format:{...}` hints to the 25 index columns found by the repo-wide date-property sweep (documented per-file in design.md), completing the pattern `register-detail-optimisation` started.
4. Immediate mitigation: `"subscribe": false` on the Delegations & mandates and Proxy authorizations index page configs, to stop the live-update race from stomping the shared loading flag. The underlying `liveUpdatesPlugin` fallback-params bug and OpenRegister's unbounded-facets hang are filed as follow-ups (see "Nc-vue / OpenRegister blocked items" in design.md) — they are out of scope for a decidesk-only PR.
5. A Playwright regression assertion that the "All urgent" (and other dropdown-mode) quick-filter labels never render as split text — a guard, not a fix, since the symptom did not reproduce.
6. A content review of `src/manifest.json`'s `walkthrough.tours[0]`, confirming/updating copy against the current six-cluster navigation (no target/ref changes needed — all four resolve correctly today).
7. Correct or remove the one identified malformed `governing-document` seed object (`id: 1bd244dd-...`).
8. Document and adopt an e2e-fixture naming convention (a stable marker prefix on titles/names created by `tests/e2e/spec-coverage/*.spec.ts`) plus add missing `afterEach`/`afterAll` cleanup to the specs that create objects without any teardown today. No bulk deletion of existing fixture data — this item is about the convention going forward and a manual, deliberately-scoped, marker-filtered cleanup step an operator can choose to run.

### Out of Scope

- The `liveUpdatesPlugin` fallback-params fix and the OpenRegister unbounded-`_facets=extend`-hangs-with-no-`_limit` fix themselves — both live in other repos (`@conduction/nextcloud-vue`, OpenRegister) and are BLOCKED on those repos' own release cycles (nc-vue publishes only from `main`/`beta`; decidesk installs from npm). Documented as follow-up items, not implemented here.
- A blind CSS fix for the "All u rgent" filter-chip symptom — not reproduced on the current build; a regression guard is added instead of a speculative patch.
- Bulk deletion of any existing e2e-created fixture objects (44 meetings, 37 bodies, 11 consultations) — hard safety rule. Only the one specifically-identified malformed governing-document object is corrected/removed by id.
- A `CnFkResolveCell` enhancement to special-case the literal nil UUID (`00000000-0000-0000-0000-000000000000`) as an empty value rather than a raw fallback id — noted as a nc-vue nice-to-have in design.md, not blocking; the fkResolve widget already fixes every *real* reference, the nil-UUID placeholders are themselves a seed-data gap in the owning registers' own `example` blocks (out of scope — those registers are owned by other, separately in-flight changes).
- Rebuilding the walkthrough tour to demo all six clusters — the current single-cluster (Meetings) tour is mechanically correct; expanding its scope is a product decision left for a future change if wanted.

## Approach

Six of the eight items are pure manifest/config edits using capabilities `@conduction/nextcloud-vue@2.3.0` (the pinned version) already ships: the `fkResolve` cell widget, the declarative `format` column hint, and the `config.subscribe` opt-out. One item (year formatting) needs one small additive Vue/JS change (a `formatters` map + wiring it through `App.vue`'s existing `CnAppRoot` mount — no new dependency). One item (empty-state) gets a same-shape `config.subscribe: false` mitigation plus properly-filed upstream follow-ups. The filter-chip and walkthrough items are investigation-and-guard / content-review tasks, not code changes. The seed-data and e2e-convention items are a one-object data fix plus a documentation-and-test-file convention change. See design.md for the full per-item file list and the "Nc-vue / OpenRegister blocked items" section.

## New Dependencies

None.

## Impact

- ~15 `src/manifest.d/*.json` fragments — column `widget`/`format` additions, two `subscribe: false` additions (see design.md for the full file list).
- `src/App.vue` — add a `:formatters="cellFormatters"` prop to the `CnAppRoot` mount.
- New `src/utils/cellFormatters.js` (or equivalent) — the `plainYear` formatter.
- One seed-data object correction (via an occ/API call or a repair-step patch — see design.md), not a schema change.
- `tests/e2e/spec-coverage/*.spec.ts` — a naming-convention adoption + missing-cleanup additions on the spec files that create objects today without teardown.
- One new Playwright regression assertion for quick-filter label integrity.
- `src/manifest.json` — walkthrough copy review (no structural change expected).

## Cross-Project Dependencies

**Two follow-up items are filed against other repos, out of scope for this PR:**
1. `@conduction/nextcloud-vue` — `liveUpdatesPlugin`'s collection dispatcher (`store/plugins/liveUpdates.js`) should not fire a bare `fetchCollection(type, {})` before the first real fetch has stashed params; it should either skip the refetch or use the composable's real default `_limit`.
2. OpenRegister — `GET .../api/objects/{register}/{schema}?_facets=extend` with no `_limit`/`_page` hangs indefinitely (confirmed: 20s+, no response) instead of either completing against a bounded sample or failing fast. This is the actual trigger of item 4's symptom; the nc-vue fix above only stops decidesk from *hitting* it accidentally, it does not fix the hang itself.

Both are documented with reproduction steps in design.md so whoever picks them up does not have to re-derive the root cause.

## Risks

### Risk 1: The manifest-column sweep (items 1 and 3) touches ~15 files also touched by other in-flight sibling changes (verordeningenregister, governing-documents-register, delegatie-mandaatregister, embargo-geheimhouding, advisory-opinion-workflow, and others)
**Severity:** Medium — **Mitigation:** Every edit in this change is additive (adding a `widget`/`format` key to an existing column entry, or adding a `subscribe: false` key to an existing page config) rather than restructuring. Conflicts, if any, will be clean textual conflicts on adjacent lines, not silent logical clashes — same reasoning `register-detail-optimisation` used for the same file set.

### Risk 2: `config.subscribe: false` removes live-update refresh on the two mitigated pages
**Severity:** Low — **Mitigation:** Users lose "another user's edit appears without a manual reload" on exactly two index pages (Delegations & mandates, Proxy authorizations) until the upstream fix lands; both pages remain fully usable via a manual reload. A perpetually-stuck loading spinner is a worse user experience than a missing live-refresh.

### Risk 3: The identified malformed governing-document object may be referenced by other seeded test data (e.g. an e2e spec asserting on its slug)
**Severity:** Low — **Mitigation:** Task includes checking `tests/e2e/spec-coverage/*.spec.ts` for any reference to the object's id/slug before correcting or removing it; if referenced, the fix corrects the `citeertitel` field in place rather than removing the object.

## Rollback Strategy

Every change is either a manifest JSON edit (revert the specific column/page-config keys), a small additive Vue file (`App.vue`'s `formatters` prop + the new formatter file — delete both), a single-object data correction (re-apply the prior field value), or test-file-only changes (revert the spec files). No schema changes, no migrations, no data loss on rollback.

## Open Questions

- Should the two `subscribe: false` mitigations (item 4) also be applied prophylactically to the other ~13 index pages sharing the same facetable-schema + live-subscription shape, or scoped narrowly to the two pages that actually exhibit the symptom today? (Provisional: scope narrowly — the defect is a timing race, not deterministic per-page, and blanket-disabling live-updates fleet-wide trades away a real feature for a symptom that has only been observed on two pages; see DEFERRED_QUESTIONS.)
- Should the e2e naming-convention adoption (item 8) also retrofit the 29 spec files that currently lack cleanup, or land the convention + fix only the files that most directly caused the observed pollution (meeting/body/consultation/governing-document creators)? (Provisional: fix the direct contributors now, document the convention for the rest as follow-up debt; see DEFERRED_QUESTIONS.)
