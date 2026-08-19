# Design: register-detail-optimisation

## Architecture Overview

Pure frontend addition on top of four already-shipped manifest v2 page skeletons (ADR-036/ADR-037). No PHP, no OpenRegister schema changes, no new routes, no new menu entries. Three new content-catalog detail widgets — `version-timeline`, `delegation-chain`, `confidentiality-status-timeline` — are registered into `@conduction/nextcloud-vue`'s shared, consumer-extensible `dashboardWidgetRegistry` (the exact mechanism the library itself uses for `chart`/`stats-block`/`table`/`related`), scoped to `surfaces: ['detail-page']` so they never appear in the dashboard Add-widget picker. Each manifest fragment's `pages[].config.widgets[]` array gains one new entry of the matching type, driven entirely by a `content` config object (register/schema/field-name mappings) — the widgets themselves carry zero decidesk-specific field names hardcoded, so the same `version-timeline` component serves both `regeling-versie` and `governing-document-versie` from two different `content` blocks.

```
src/manifest.d/verordeningenregister.json          (edit: +version-timeline widget, +format fix, +field foregrounding)
src/manifest.d/governing-documents-register.json   (edit: +version-timeline widget, +index column)
src/manifest.d/delegatie-mandaatregister.json       (edit: +delegation-chain widget)
src/manifest.d/embargo-geheimhouding.json           (edit: +confidentiality-status-timeline widget, +column key fix)

src/components/widgets/
  RegisterVersionTimelineWidget.vue        (new — version-timeline type)
  DelegationChainWidget.vue                (new — delegation-chain type)
  ConfidentialityStatusTimelineWidget.vue  (new — confidentiality-status-timeline type)
  registerDetailWidgets.js                 (new — self-registration module, imported once from main.js)
```

## Goals / Non-Goals

**Goals:** register-optimised detail presentation for the four surfaces named in the proposal; fix the two live-observed, page-declaration-level defects (raw computed-field dates, the `bekrachtigingDeadline`/`ratificationDeadline` key mismatch); keep every one of the four registers on decidesk's own manifest pages (explicit product decision — not OpenRegister's generic object UI).

**Non-Goals:** the "geldig/geldend op" (in-force-on-date) date-picker filter and CSV export named in the sibling changes' own follow-up tasks; any OpenRegister schema change; the Delegations & mandates empty-state defect (see Decisions, D5); new menu entries (explicitly excluded by the requester).

## Decisions

### D1: Version timeline as one reusable content-driven widget, not two schema-specific components

`regeling`/`regeling-versie` and `governing-document`/`governing-document-versie` are structurally the same shape for this purpose: a parent register/schema, a version register/schema referencing it, an effective-date field, a status field, and an optional enacting-Decision reference. `RegisterVersionTimelineWidget.vue` takes a `content` config (`{ versionRegister, versionSchema, parentRefField, effectiveDateField, lapseDateField, versionNumberField, statusField, decisionRefField, extraFields? }`) and resolves the version list via a single filtered OR list query (`versionRegister`/`versionSchema` where `parentRefField == currentObjectId`), sorted client-side by `effectiveDateField`. `extraFields` (an array of `{ field, label }`) covers the one genuine per-schema difference — governing-document-versie's optional notarial-deed metadata (`aktedatum`/`notaris`) — without a second component.

**Alternative considered:** two separate components (`RegelingVersionTimelineWidget`, `GoverningDocumentVersionTimelineWidget`) — rejected: the only structural difference is the optional extra-fields row, which a config array already covers; duplicating the resolve/sort/render logic would drift the two registers' timelines apart over time (exactly the kind of copy-paste the `related`/`data` widgets avoid by being config-driven).

### D2: Delegation chain as a bounded bidirectional walk, resolved client-side from already-fetched data where possible

`DelegationChainWidget.vue` walks `parentAllocation` upward (ancestors) and queries for objects whose `parentAllocation` equals the current id (children) — the same "reverse relation" pattern used elsewhere in decidesk (e.g. `RelatedDecisionsTab`, governance-bodies' `fetchUsed`). The ancestor walk is bounded (matching the sibling `delegatie-mandaatregister` design's server-side guard, which already caps chain depth) and de-duplicates visited ids so a defensive cycle check never infinite-loops even though the save-path guard in the `delegatie-mandaatregister` change is what actually prevents cycles from being created.

**Alternative considered:** a single OR query fetching the whole ondermandaat tree in one call — rejected: no existing OR endpoint supports recursive self-reference resolution, and building one would be new backend surface for a UI-only change; the bounded walk is a handful of small requests against chains that are 1–3 deep in practice (per the sibling design's own observation).

### D3: Confidentiality status timeline models the lifecycle as three fixed stages, not a generic activity feed

`ConfidentialityStatusTimelineWidget.vue` renders exactly three stages (imposed / bekrachtiging / dissolution) because that is the actual legal structure (Gemeentewet art. 87-89) the `embargo-geheimhouding` schema encodes — not an open-ended audit-log feed. A pending stage (fields not yet set) still renders as a placeholder row so the reader sees the full expected sequence and where the record currently sits in it, distinct from the per-geheimhouding view-audit trail (REQ-EMB-007, a separate sidebar tab that logs *who viewed this record*, not the record's own lifecycle).

**Alternative considered:** reusing the generic `related`/`audit` widgets to imply the lifecycle from raw field presence — rejected: the audit-trail widget shows *edit history*, not domain lifecycle stages, and would not distinguish "not yet due" from "overdue" for the bekrachtiging deadline (REQ-EMB-010's overdue-indicator scenario needs a domain-aware component).

### D4: Declarative-vs-imperative decision (ADR-031)

ADR-031 governs backend business logic (lifecycle/aggregations/calculations/notifications/relations) expressed as OpenRegister dialects vs. PHP services — this change has no backend component, so ADR-031's dialect table does not directly apply. The equivalent frontend question is manifest-declarative vs. registry-resolved Vue component, and the same "declarative unless the mechanism cannot express it" bar applies:

| Behaviour | Mechanism | Why |
|---|---|---|
| Field ordering, in-force status + CVDR foregrounding | Manifest `data` widget field `overrides`/ordering (existing mechanism, `CnObjectDataWidget`) | Pure declarative reordering — no new code needed |
| Date formatting on schema-declared fields | Already automatic (`CnObjectDataWidget`/`CnCellRenderer` read `schema.properties[key].format`) | No fix needed — only computed/mismatched fields lack this |
| Date formatting on computed/convenience fields (`currentInwerkingtreding`, current-in-force-date) | Explicit `"format": "date"` on the manifest column definition | `CnDataTable.columnProperty()` already supports this exact escape hatch for synthesized columns — declarative, no code |
| Version timeline (ordered relation list + resolved Decision links) | New `version-timeline` registry widget | The built-in `related` widget groups relations by type into tabs; it does not sort by date, does not resolve a specific reference field into an inline link, and does not render per-item status badges — no existing declarative primitive expresses "this ordered, this annotated" |
| Ondermandaat chain (bidirectional self-reference walk with cycle safety) | New `delegation-chain` registry widget | Graph traversal is not expressible as a manifest widget config; this is presentation-layer graph logic, analogous to why the sibling change's ondermandaat *validation* guard is imperative on the backend |
| Confidentiality status timeline (fixed 3-stage domain lifecycle with pending/overdue states) | New `confidentiality-status-timeline` registry widget | Domain-specific conditional rendering (pending vs. populated vs. overdue) across five different fields is not expressible as a generic widget config |
| Ground / target polymorphic reference resolution | New widget's own resolve calls (small helper inside the two new/related widgets) | A single-object reference resolve by id, already the pattern `CnRelatedObjectsWidget` and `fkResolve` column widgets use internally — reused, not reinvented |

All three new widgets are registered via `registerDashboardWidget(type, { renderer, form: null, defaultContent: {}, displayName, icon, surfaces: ['detail-page'] })` — the library's own documented consumer-extension point (`dashboardWidgetRegistry.js`: "consumer apps may extend or override it"), not a bespoke `type: "custom"` page component. This keeps the manifest fragments' `widgets[]` arrays declarative (`{ id, type: "version-timeline", content: {...} }`) even though the renderer behind that type is a registry-resolved Vue component — the same shape as every other body widget on these detail pages.

### D5: The Delegations & mandates empty-state defect is out of scope, not silently dropped

Investigated by reading `CnIndexPage.vue`'s render logic (`showInitialLoader` → `effectiveObjects.length === 0` → table, in that order) and `effectiveLoading`/`showInitialLoader` computeds: the branching is structurally sound and is the exact code path the three sibling index pages (`Regelingen`, `GoverningDocuments`, `Geheimhoudingen`) use without exhibiting the symptom. The `Bevoegdheidstoedelingen` manifest fragment's `config.register`/`config.schema`/column keys were cross-checked against `lib/Settings/register.d/54-delegatie-mandaatregister.json` and all resolve to real properties — no manifest-level typo explains "neither table nor empty state." The remaining plausible cause is a stuck `loading` state in the object-store list composable (e.g. an unhandled rejection on a register/schema not yet fully provisioned, or a fetch error that never flips `list.loading.value` back to `false`) — a shared renderer/store concern, not a page declaration. Per the task's explicit boundary ("if it's a shared renderer fix in nc-vue or the page-renderer component, document that as a separate finding... rather than pulling a library change into this change"), this is filed as a follow-up finding (see proposal.md Open Questions) instead of attempted here.

## Nextcloud Integration

- **Controllers:** none — no backend change.
- **Services:** none — no backend change.
- **Mappers/Entities:** none — reads exclusively via the existing frontend object store (`useObjectStore`) against OpenRegister's `/apps/openregister/api/objects`, per ADR-022.
- **Events/Hooks:** none.
- **Frontend registration:** `registerDashboardWidget()` calls in the new `src/components/widgets/registerDetailWidgets.js`, imported once (side-effect import) from `src/main.js` alongside the existing `registry.js` import — mirrors how `@conduction/nextcloud-vue`'s own `registerDashboardWidgets.js` self-registers its catalog at library-import time.

## Security Considerations

No security impact. All three new widgets are read-only presentation components resolving object references the current user's session already has read access to via OpenRegister RBAC (ADR-005/ADR-022) — no new endpoints, no new write paths, no elevation of what data is fetchable. The chain widget's bounded/de-duplicated walk (D2) is itself a defensive control against a malformed or maliciously-crafted `parentAllocation` cycle causing a client-side denial-of-service (infinite loop / unbounded fetch fan-out).

## NL Design System

All three widgets use standard NC components (`NcListItem` or `CnDataTable`-style rows for the timeline/chain entries, `NcBadge`/existing badge cell-widget styling for status, `CnIcon` for stage icons) — no hardcoded colours; status/overdue indicators use existing semantic CSS variables (error/warning tokens) plus a non-colour cue (icon or text label) per REQ-EMB-010's WCAG requirement. Matches the existing `data`/`related`/`files` widget chrome (`CnWidgetWrapper` title + icon) already used on these four detail pages (ADR-062).

## File Structure

```
src/
  components/
    widgets/
      RegisterVersionTimelineWidget.vue
      DelegationChainWidget.vue
      ConfidentialityStatusTimelineWidget.vue
      registerDetailWidgets.js
  manifest.d/
    verordeningenregister.json           (edit)
    governing-documents-register.json    (edit)
    delegatie-mandaatregister.json       (edit)
    embargo-geheimhouding.json           (edit)
  main.js                                (edit: +1 side-effect import)
```

## Seed Data

Not applicable — this change introduces no new OpenRegister schema and no new schema property. It renders against the `regeling`/`regeling-versie`, `governing-document`/`governing-document-versie`, `bevoegdheidstoedeling`, and `geheimhouding`/`geheimhouding-grond` objects the four sibling changes already seed (or will seed as part of their own delivery). No `_registers.json` entries are generated by this change.

## Trade-offs

- **Registry-resolved widgets vs. `type: "custom"` page components:** chosen registry-widget approach keeps the manifest fragments' `widgets[]` arrays uniform and declarative-looking (same shape as `data`/`related`/`files`), at the cost of the three new components living in a shared, mutable, cross-app registry object (`dashboardWidgetRegistry`) rather than decidesk's own `registry.js`. Mitigated by scoping `surfaces: ['detail-page']` and using domain-specific type names unlikely to collide (see proposal Risk 2).
- **Client-side ancestor/child walk vs. a backend recursive-resolve endpoint:** chosen client walk avoids new backend surface for a UI-only change, at the cost of N small requests for an N-deep chain — acceptable given chains are 1–3 deep in practice (sibling design's own observation) and this is a detail-page view, not a hot list path.
- **Fixing the two date/naming defects here vs. filing them against the sibling changes' own PRs:** chosen to fix here because both defects are in files this change already edits (adjacent lines in the same manifest fragments), and both are one-line-scale fixes; filing them separately would fragment a two-line fix across three open changes for no benefit.

## Open Questions

See proposal.md Open Questions — the Delegations & mandates empty-state defect (follow-up target) and the ADDED-Requirements-vs-consolidated-capability question are both deferred to the human via `DEFERRED_QUESTIONS`.
