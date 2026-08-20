---
kind: code
---

# Proposal: pc-cyclus

## Summary

Add the **Planning & Control cyclus** to decidesk: the recurring annual decision/document cycle every governance body runs — municipal kadernota → programmabegroting → bestuursrapportage(s) → jaarrekening, and the association/corporate analogue jaarplan → begroting → tussenrapportage → jaarstukken + decharge. A `PCCyclus` (one body, one year, one template) generates `CyclusStap` objects from a configurable `CyclusTemplate` (municipal + association built-ins shipped as seeds, following the process-configuration built-in-template pattern). Each step carries an aanlever-deadline, a technische-vragen window, behandeling targets (commissie and/or raad/ALV), a declarative status lifecycle, document slots, and links to the actual AgendaItem/Decision once scheduled. Declarative rappels fire when aanlevering is late or behandeling approaches unscheduled; a year-view timeline per body plus a dashboard KPI (steps past deadline) give the griffie/bestuur oversight; next-year generation shifts all dates forward.

## Motivation

Novelty-verified MISSING (2026-07-17): begroting/kadernota/jaarrekening exist in decidesk only as example seed titles (`lib/Settings/decidesk_register.json` seeds like "Kadernota begroting 2026", "jaarrekening-2024"); `meeting-series` covers recurring *meetings* but no recurring *decision/document cycle* exists anywhere in the app. Market evidence: demand cluster p-c-cycle-management scores 741 in the intelligence DB, and **begrotingsbehandeling is a named GemeenteOplossingen competitor module**. The P&C cyclus is the single most predictable, most deadline-critical workflow a griffie and a bestuur run each year — today they track it in Excel next to decidesk. Without it, the app manages the meetings but not the year: nobody sees that the programmabegroting stukken are a week late, that the technische-vragen window closes Friday, or that the jaarrekening behandeling still has no agenda item. The association/corporate analogue (jaarstukken + decharge, BW Book 2) is the same shape with different step names, so one capability serves all five governance domains.

## Affected Projects

- [ ] Project: `decidesk` — new `PCCyclus`, `CyclusTemplate`, `CyclusStap` schemas (register.d fragment 52), built-in template + example-cycle seed data, step-generation and next-year service, manifest pages + year-view timeline, dashboard KPI, declarative rappels, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, aggregations, FileService attachments are existing capabilities).

## Scope

### In Scope

1. **PCCyclus schema**: cycle year, governance body (GovernanceBody ref), template (CyclusTemplate ref), derived progress counters.
2. **CyclusTemplate schema**: configurable step list (step type, default dates, subject-year offset, document slots, behandeling targets); municipal + association built-in templates shipped as seeds following the process-configuration built-in-template pattern (`builtIn: true`, read-only, duplicable).
3. **CyclusStap objects generated from the template**: step type (kadernota / begroting / berap / jaarrekening / jaarplan / jaarstukken / decharge — extensible, templates may declare custom types), aanlever-deadline (the organisation delivers documents), technische-vragen window, behandeling target(s) (commissie and/or raad/ALV with target dates), status lifecycle `gepland → stukken-ontvangen → in-behandeling → vastgesteld | afgerond` (x-openregister-lifecycle), document slots (files via OR FileService), links to the actual AgendaItem/Decision once scheduled.
4. **Declarative deadline rappels** per step (x-openregister-notifications): aanlevering late (deadline past, still `gepland`), behandeling approaching while unscheduled (no linked agenda item).
5. **Year-view timeline** per body showing all steps + progress, plus a dashboard KPI (steps past aanlever-deadline).
6. **Next-year generation** from the template with date shifting (+1 year), preserving per-step customisations of the source cycle.

Boundary statement: **decharge is a step OUTCOME here** — the resulting besluit lives in the normal Decision model. The sibling change `vve-alv-pack` owns VvE-specific statutory decision TEMPLATES; this change never defines statutory decision content.

### Out of Scope

- Financial content or figures — no bookkeeping, no budget line items; steps carry documents and dates only.
- Begrotingsapp integrations (Pepperflow, LIAS, etc.) — future connector work.
- Amendementen op de begroting — the existing motion-amendment machinery already covers amendments on the begroting decision.
- Creating or scheduling the meetings themselves — behandeling links to AgendaItems of meetings that exist via the normal meeting/meeting-series flow.

## Approach

Pure thin-client extension per ADR-022/ADR-037: three new schemas in `lib/Settings/register.d/52-pc-cyclus.json` (never editing `decidesk_register.json`), workflow declared in OpenRegister dialects — step lifecycle via `x-openregister-lifecycle` (canonical `initial` keyword), rappels via `x-openregister-notifications` scheduled triggers, progress counters and the KPI via declarative aggregations. UI is a `src/manifest.d/pc-cyclus.json` fragment (cycli index, cyclus detail with year-view timeline, step detail) plus one KPI widget edited into the base `src/manifest.json` Dashboard page. The only imperative code is a small `CyclusGenerationService`: instantiate CyclusStap objects from a template for a chosen year (resolving default dates + subject-year offsets) and generate next year by shifting dates. Details in design.md.

## New Dependencies

None. Lifecycle, notifications, aggregations, FileService attachments, manifest-v2 pages, and the built-in-template pattern all exist in OpenRegister, nc-vue, and decidesk.

## Impact

- `lib/Settings/register.d/52-pc-cyclus.json` (new — schemas + dialects + seed data; fragment number 52 is assigned to this change, 40–51 and 53–65 belong to siblings).
- `src/manifest.d/pc-cyclus.json` (new — pages + menu).
- `src/manifest.json` (edit — one Dashboard stat widget; fragments replace same-id pages wholesale, so the Dashboard page cannot be extended from a fragment).
- `lib/Service/CyclusGenerationService.php` (new), controller wiring + `appinfo/routes.php` (edit — generate/next-year actions).
- Year-view timeline component in `src/` (custom detail widget).
- Docs + PHPUnit/e2e per hydra gates.

## Cross-Project Dependencies

- `vve-alv-pack` (sibling decidesk change, boundary only — no build dependency): it owns VvE statutory decision templates; this change only records decharge as a step outcome whose besluit lives in the Decision model. Neither change blocks the other.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: Duplicate scheduling machinery next to meeting-series

**Severity:** Medium — **Mitigation:** a CyclusStap never creates meetings or agenda items; behandeling is a *target date* plus an optional link to an AgendaItem created through the normal agenda flow. Meeting-series keeps owning recurring meetings; this capability owns the recurring document/decision cycle. The spec states this explicitly.

### Risk 2: Lifecycle dialect drift (silently-ignored annotation)

**Severity:** Medium — **Mitigation:** fragment uses the canonical `x-openregister-lifecycle` dialect with the `initial` keyword (never `initialState`/`states`-only/`default`); gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`pc-cyclus`, `cyclus-stap`), never PascalCase.

### Risk 3: Cross-year date semantics (jaarrekening year N behandeld in year N+1)

**Severity:** Medium — **Mitigation:** the cyclus `year` is the *execution* year; each template step carries a `subjectYearOffset` (begroting/kadernota +1, jaarrekening −1, berap 0) from which the step's `betreftJaar` derives. Seed data exercises all three offsets so the semantics are visible on install.

### Risk 4: KPI "past deadline" filter needs a relative-date token

**Severity:** Low — **Mitigation:** same D6 fallback as toezeggingen-ingekomen-stukken: provisional `@now` token in the stat-widget source filter, verified against nc-vue's widget source resolver; documented fallback via a pre-filtered index, never a silently wrong count.

### Risk 5: Scope creep into financial content

**Severity:** Low — **Mitigation:** hard out-of-scope rule; steps carry document slots and dates only; no monetary properties exist on any of the three schemas.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers schemas/pages on next load/build (ADR-037 fragments are additive; no edits to existing schemas). Existing PCCyclus/CyclusStap objects remain soft-retained in OpenRegister; linked AgendaItems/Decisions are untouched (links are one-directional references from the step). No data migration to undo.

## Open Questions

- Exact relative-date token supported by the stat-widget filter DSL (see Risk 4); resolved during implementation against nc-vue's widget source resolver — same open question as the toezeggingen change, resolve once for both.
