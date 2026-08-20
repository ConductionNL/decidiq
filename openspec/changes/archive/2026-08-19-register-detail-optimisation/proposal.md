---
kind: code
---

# Proposal: register-detail-optimisation

## Summary

Register-optimise the detail pages of decidesk's four legal-register surfaces — Regulations (`regeling`/`regeling-versie`), Governing documents (`governing-document`/`governing-document-versie`), Delegations & mandates (`bevoegdheidstoedeling`), and Confidentiality register (`geheimhouding`) — while keeping them on the manifest system's own index/detail pages (product decision: NOT migrated to OpenRegister's generic UI). Each detail page gains the register-specific presentation its domain needs: a version timeline (versions ordered by date, linked to the amending Decision) for Regulations and Governing documents; an ondermandaat chain widget for Delegations & mandates; a status timeline (imposed → bekrachtiging deadline → dissolution) with ground resolution for the Confidentiality register; and prominent in-force/CVDR presentation throughout. Two pieces of pre-existing, live-observed debt on these same four surfaces are fixed at the manifest/page-declaration level: raw unformatted datetimes on computed/convenience columns, and a genuine key-naming mismatch on the Confidentiality register index.

## Motivation

These four registers (`verordeningenregister`, `governing-documents-register`, `delegatie-mandaatregister`, `embargo-geheimhouding`) already ship declarative index + detail page skeletons (`src/manifest.d/*.json`) built on `type: "data"` / `type: "related"` widgets. Every one of those manifest fragments explicitly documents, in its own `_note`, that the version timeline, the "geldend/geldig op" (in-force-on-date) resolution, the ondermandaat chain display, and the confidentiality status workflow are "wired imperatively by the page/service tasks" or "deferred" — i.e. the sibling changes that introduced these registers deliberately shipped the page skeleton and left the register-specific presentation as follow-up work. That follow-up is this change. Separately, live observation of the running pages surfaced two defects: (1) computed/convenience fields that are not declared in the OpenRegister schema — e.g. `regeling`'s `currentInwerkingtreding`, populated at runtime by `RegelingConsolidationService` — render as raw ISO datetime strings ("2025-03-01 00:00:00") because `CnDataTable` can only apply schema-derived date formatting to fields it finds in `schema.properties`; a column with no matching schema property gets no format hint. (2) The Confidentiality register index declares an index column keyed `bekrachtigingDeadline`, which does not exist anywhere in the `Geheimhouding` schema (the real property is `ratificationDeadline`) — a plain naming-drift bug between the Dutch-language column label and the English-language schema property it should reference. A third live observation — the Delegations & mandates index renders neither a table nor an empty state on zero rows — was investigated and traced to `CnIndexPage`'s loading/empty-state branching (`showInitialLoader` / `effectiveObjects.length === 0`), which is structurally sound and shared by the three sibling index pages that do not exhibit the symptom; no page-declaration defect explains it, so it is documented as a separate finding rather than folded into this change (see Out of Scope).

## Affected Projects

- [ ] Project: `decidesk` — three new content-catalog detail widgets (`version-timeline`, `delegation-chain`, `confidentiality-status-timeline`) registered into `@conduction/nextcloud-vue`'s shared `dashboardWidgetRegistry` with `surfaces: ['detail-page']`; edits to the four existing manifest fragments (`src/manifest.d/{verordeningenregister,governing-documents-register,delegatie-mandaatregister,embargo-geheimhouding}.json`) to place the new widgets, foreground in-force status + CVDR identifier, and fix the two date/naming defects. No PHP, no OpenRegister schema changes, no menu changes.

## Scope

### In Scope

- A `version-timeline` detail widget: renders a schema's version objects ordered by their effective date, each row showing version number, effective/lapse dates, a status badge, and a resolved link to the enacting Decision (`determinedBy`/`vastgesteldDoor`). Used on `RegelingDetail` (regeling-versie) and `GoverningDocumentDetail` (governing-document-versie).
- A `delegation-chain` detail widget: renders the `bevoegdheidstoedeling` ondermandaat chain — ancestor breadcrumb walked up via `parentAllocation`, direct-child ondermandaten walked down — plus the source `decision` link and resolved delegans/delegataris display. Used on `BevoegdheidstoedelingDetail`.
- A `confidentiality-status-timeline` detail widget: renders the `geheimhouding` lifecycle as an ordered timeline (imposed → bekrachtiging deadline/decision → dissolution), the resolved `ground` (`GeheimhoudingGrond`, including legacy citation), and resolved target links (document/agendaItem/decision — whichever is set). Used on `GeheimhoudingDetail`.
- Prominent in-force status and CVDR identifier presentation on `RegelingDetail` (foregrounded field order / a compact status widget), matching in-force presentation on `GoverningDocumentDetail`.
- Fix: `regeling`'s index column `currentInwerkingtreding` (and the analogous current-in-force-date column added to the `GoverningDocuments` index) declare an explicit `"format": "date"` hint so the runtime-computed field renders through the same date formatter as schema-declared fields.
- Fix: `embargo-geheimhouding`'s `Geheimhoudingen` index column keyed `bekrachtigingDeadline` is renamed to `ratificationDeadline` to match the actual `Geheimhouding` schema property (which already declares `format: "date"` — the rename alone restores correct formatting and correct data).
- Every new widget renders dates through the shared `formatDate`/`formatDateTime` utilities — no raw `Date` stringification.
- WCAG 2.2 AA: all three new widgets are keyboard-navigable (chain links, timeline entries, resolved-reference links are real focusable elements with accessible names) per ADR-010/company-wide WCAG coverage matrix.

### Out of Scope

- The Delegations & mandates index "no table, no empty state on zero rows" defect. Investigated (see Motivation) and traced to `CnIndexPage`'s shared loading/empty-state logic or the underlying object-store list-loading composable, not to this change's manifest declarations. Filed as a separate finding (see Open Questions) rather than pulled into this change, per the "no library changes riding along with a manifest change" boundary.
- Any change to the OpenRegister schemas backing these four registers (`lib/Settings/register.d/53/54/55/65-*.json`) — all fields the new widgets read already exist.
- The "geldig op" (in-force-on-date) date-picker filter, CSV export, and the RegelingConsolidationService/RegelingExportService imperative slices named in the sibling changes' own follow-up tasks — those belong to their originating changes, not this one.
- New menu entries (explicitly excluded by the requester).
- Migrating any of the four registers onto OpenRegister's generic object UI (explicit product decision: these stay on decidesk's own manifest-driven pages).

## Approach

Add three small, content-config-driven Vue components under `src/components/widgets/`, each self-registering into `@conduction/nextcloud-vue`'s `dashboardWidgetRegistry` (the same extension point the library itself uses for `chart`/`stats-block`/`table`) with `surfaces: ['detail-page']` and `form: null` (renderer-only — never offered in the dashboard Add-widget picker). Wire each into the relevant manifest fragment's `pages[].config.widgets[]` as a `type: "<new-type>"` entry alongside the existing `data`/`files`/`related` widgets, driven entirely by a `content` config object (register/schema/field-name mappings) so no per-register component variants are needed. See design.md for the full declarative-vs-imperative rationale and the exact `content` shape per widget.

## New Dependencies

None.

## Impact

- `src/manifest.d/verordeningenregister.json` — `RegelingDetail` gains a `version-timeline` widget bound to `regeling-versie` and reordered/foregrounded in-force + CVDR fields; `Regelingen` index column fix.
- `src/manifest.d/governing-documents-register.json` — `GoverningDocumentDetail` gains a `version-timeline` widget bound to `governing-document-versie`; `GoverningDocuments` index gains the promised current-in-force-date column.
- `src/manifest.d/delegatie-mandaatregister.json` — `BevoegdheidstoedelingDetail` gains a `delegation-chain` widget.
- `src/manifest.d/embargo-geheimhouding.json` — `GeheimhoudingDetail` gains a `confidentiality-status-timeline` widget; `Geheimhoudingen` index column key fix.
- `src/components/widgets/RegisterVersionTimelineWidget.vue`, `DelegationChainWidget.vue`, `ConfidentialityStatusTimelineWidget.vue` (new) + a small registration module wired from `src/main.js` (or `src/registry.js`, see design.md).
- No route changes, no new menu entries, no PHP changes, no schema changes.

## Cross-Project Dependencies

None. Builds entirely on register/schema/manifest work already merged to `decidesk` by the (separately in-flight) `verordeningenregister`, `governing-documents-register`, `delegatie-mandaatregister`, and `embargo-geheimhouding` changes — their OpenRegister schemas (`lib/Settings/register.d/53/54/55/65-*.json`) and base manifest fragments already exist in this repo at time of writing; this change only adds presentation on top.

## Risks

### Risk 1: Sibling changes still in flight may alter the base manifest fragments underneath this change
**Severity:** Medium — **Mitigation:** This change touches the same four files as (separately in-progress) sibling work. It edits narrowly (adds widget entries + fixes two field keys) rather than rewriting the fragments, minimising merge-conflict surface; if a conflict does occur it will be a clean textual conflict, not a silent logical clash, because both sides are additive.

### Risk 2: A custom widget "type" self-registered into the shared `dashboardWidgetRegistry` collides with a future library-shipped type of the same name
**Severity:** Low — **Mitigation:** `registerDashboardWidget()` is last-registration-wins and warns on override in development. The three new type keys (`version-timeline`, `delegation-chain`, `confidentiality-status-timeline`) are domain-specific and unlikely to collide with a generic library type; the console warning makes any future collision immediately visible in dev.

## Rollback Strategy

Revert the manifest fragment edits and drop the three new widget components + their registration import. No data migration, no schema change, no server-side state — a pure frontend revert with no follow-up cleanup.

## Open Questions

- Should the Delegations & mandates "no table, no empty state" defect be opened as its own follow-up change against the shared `CnIndexPage` / object-store list-loading path in `@conduction/nextcloud-vue`, or investigated further first with a live browser reproduction before filing? (Provisional: file as a follow-up finding; see DEFERRED_QUESTIONS.)
- Should the three new widget capabilities be captured as `ADDED Requirements` against the four sibling capability names (`verordeningenregister`, `governing-documents-register`, `delegatie-mandaatregister`, `embargo-geheimhouding`) as this change does, or consolidated into one new `register-detail-optimisation` capability spec? (Provisional: per-capability ADDED Requirements, to keep traceability to the REQ-VOR/-GDR/-DMR/-EMB numbering the sibling changes already established; see DEFERRED_QUESTIONS.)
