---
kind: code
depends_on: [toezeggingen-ingekomen-stukken]
---

# Proposal: raadsinformatiebrieven

## Summary

Add a **raadsinformatiebrieven register** (RIB / collegebrieven) to decidiq: the college's formal outbound information letters to the council — a classic raadsinformatiesysteem artifact that is explicitly distinct from ingekomen stukken (inbound external mail). A `Raadsinformatiebrief` schema (number `RIB-{jaar}-{volgnummer}`, onderwerp, portefeuillehouder, configurable category, sent date, letter document + bijlagen, links to dossier/decision/motie and — crucially — an optional toezegging-afdoening link) ships as a `lib/Settings/register.d/51-raadsinformatiebrieven.json` fragment with a declarative lifecycle `verzonden → geagendeerd → betrokken-bij-behandeling`. A companion `TechnischeVraag` schema carries the short Q&A thread per RIB (question by a council member/fractie, answer by the organisation) that griffies run alongside RIBs, explicitly bounded away from formal art. 33 schriftelijke vragen. RIBs and answered technische vragen publish via the existing OR published-predicate machinery, WOO-aware; list/detail pages follow manifest conventions; council members get a declarative notification on every new RIB.

## Motivation

Novelty verified against the worktree (2026-07-17): `collegebrief` appears only as an example attachment string in the motie change (`UitvoeringsUpdate.attachments`), and `raadsinformatiebrief` only as an expected-type *string* on the termijnagenda — there is no RIB register anywhere. The ingekomen-stukken register (`toezeggingen-ingekomen-stukken`) models **inbound** mail: its `category: collegestuk` could file a *received* college document, but it has none of the RIB semantics (college-issued numbering, portefeuillehouder, toezegging-afdoening, technische-vragen thread, ter-kennisname agendering). `technische vragen` has zero coverage anywhere in the repo.

In Dutch municipal practice the RIB is the college's primary instrument for its actieve informatieplicht (Gemeentewet art. 169): every RIS (iBabs, GO, NotuBiz) ships a RIB list with per-brief technische vragen. A griffie that cannot register, agender, publish, and question RIBs in decidiq keeps a parallel system — and the toezeggingenlijst stays incomplete, because a large share of toezeggingen are afgedaan *by* a RIB ("u ontvangt vóór 1 maart een raadsbrief").

## Affected Projects

- [ ] Project: `decidiq` — new `Raadsinformatiebrief` + `TechnischeVraag` schemas (register.d fragment 51), manifest pages + menu (manifest.d fragment), toezegging-afdoening evidence surfacing, declarative notification, publication predicates, seed data, docs, tests. One new capability spec: `raadsinformatiebrieven-register`.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, RBAC published-predicate are existing capabilities).

## Scope

### In Scope

1. **Raadsinformatiebrief schema** (register.d fragment 51): number `RIB-{jaar}-{volgnummer}`, onderwerp, portefeuillehouder (Person ref), category (configurable option list, open string — not a hard enum), verzonden datum, letter document + bijlagen via the Files leaf (`FileService`), directedTo (GovernanceBody ref), optional links to dossier / decision / motie, and an optional **afgedaneToezegging** link (a RIB frequently afdoet a toezegging).
2. **Toezegging-afdoening linkage**: when `afgedaneToezegging` is set, the RIB is surfaced as afdoening evidence on the Toezegging detail per the toezeggingen-register model (`afdoeningsBewijs`); the toezegging lifecycle is never duplicated or auto-transitioned.
3. **Declarative status lifecycle** `verzonden → geagendeerd → betrokken-bij-behandeling` (x-openregister-lifecycle), with optional placement ter kennisname on an agenda/LIS item at `geagendeerd`.
4. **Technische vragen thread per RIB** (`TechnischeVraag` schema): short Q&A entries — question by member/fractie, answer by the organisation — explicitly distinct from formal art. 33 schriftelijke vragen (boundary stated as a requirement; the `SchriftelijkeVraag` planned in `changes/fractievoorzitter-fractie-koppeling` owns that instrument).
5. **Public publication** of RIBs and *answered* technische vragen via the existing OR published-predicate machinery, WOO-aware (both schemas are publishable by construction — no citizen PII, no internal-only fields).
6. **List/detail pages** per manifest-v2 conventions (manifest.d fragment; schema refs by slug), index search + quick filters, CSV export, technische-vragen thread on the RIB detail.
7. **Declarative notification** to council members of the directed-to body on every new RIB (x-openregister-notifications `created` trigger, nl/en subjects).
8. **Seed data** for both schemas (ADR-016).

### Out of Scope

- College-internal drafting/parafering workflow for RIBs — procest domain; decidiq registers the *sent* letter.
- Inbound mail — `ingekomen-stukken-register` (change `toezeggingen-ingekomen-stukken`) owns that; a RIB is never registered as an IngekomenStuk.
- Formal art. 33 schriftelijke vragen (question deadlines, college answer workflow) — `SchriftelijkeVraag` in `changes/fractievoorzitter-fractie-koppeling`; escalating a technische vraag is a manual re-filing there.
- Embargo/geheimhouding handling for confidential RIBs — a RIB simply stays unpublished; classified-material machinery is the `embargo-geheimhouding` change's concern.
- Adding the new schemas to the global-search schema list (would MODIFY `global-search` REQ-SRC-001; deferred — index search covers the need).
- Dashboard KPI for unanswered technische vragen — deferred to a later analytics pass.

## Approach

Pure thin-client extension per ADR-022/ADR-037: two new schemas in `lib/Settings/register.d/51-raadsinformatiebrieven.json` (number **51 is assigned to this change; 40–50 and 52–65 belong to sibling changes**; the base `decidesk_register.json` is never edited). All behaviour is declarative (ADR-031): lifecycle via `x-openregister-lifecycle` (canonical `initial` keyword), the new-RIB notification via `x-openregister-notifications`, public access via `authorization.read` published-predicates on the live objects (the toezeggingen-register D4 pattern — both schemas carry only publishable fields by construction, and the public RIB list must reflect status live). UI is a `src/manifest.d/raadsinformatiebrieven.json` fragment (index + detail pages + menu; the technische-vragen thread is a related-objects section on the RIB detail). The only candidate imperative surface is RIB auto-numbering; design.md resolves it declaratively-first with a documented fallback. Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, published-predicate RBAC, Files leaf, ExportService, manifest-v2 pages) already exist in OpenRegister, nc-vue, and decidiq.

## Impact

- `lib/Settings/register.d/51-raadsinformatiebrieven.json` (new — 2 schemas + dialects + predicates + seed).
- `src/manifest.d/raadsinformatiebrieven.json` (new — pages + menu).
- Toezegging detail surfacing of linked RIBs as afdoening evidence (reverse-relation display; no schema change to Toezegging).
- Docs + PHPUnit/Playwright/Newman per hydra gates.
- No controller/service edits expected (no derived payloads: predicate-on-live-object publication).

## Cross-Project Dependencies

- `toezeggingen-ingekomen-stukken` (decidiq change, declared in `depends_on`): the `afgedaneToezegging` link and the "surface as afdoeningsBewijs, never duplicate the lifecycle" rule assume that change's `Toezegging` model, and the inbound/outbound boundary is defined against its `IngekomenStuk`. The runtime dependency is soft (`afgedaneToezegging` is nullable), but the spec text references its schemas, so it must land first or concurrently.
- `fractievoorzitter-fractie-koppeling` (referenced only): the technische-vragen boundary names its planned `SchriftelijkeVraag`; nothing here blocks on it landing.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: Technische vragen drift into a shadow schriftelijke-vragen instrument

**Severity:** Medium — **Mitigation:** hard boundary requirement: TechnischeVraag carries no answer deadlines, no college workflow, no fractie-quorum machinery; the spec names `SchriftelijkeVraag` (fractievoorzitter-fractie-koppeling) as the formal instrument and makes escalation an explicit manual re-filing.

### Risk 2: Duplicate afdoening lifecycle between RIB and Toezegging

**Severity:** Medium — **Mitigation:** the RIB only *references* the toezegging; afdoening (lifecycle → `afgedaan`, `afdoeningsBewijs` → the RIB) remains a griffie action on the Toezegging per toezeggingen-register REQ-002; the RIB schema carries no afdoening state of its own.

### Risk 3: Unanswered technische vragen leak to the public surface

**Severity:** Medium — **Mitigation:** the public read predicate on TechnischeVraag requires both `publicatiedatum <= $now` AND lifecycle `beantwoord`, so a prematurely set predicate never exposes an unanswered question; Newman negative test asserts it.

### Risk 4: RIB numbering collides under concurrent creation

**Severity:** Low — **Mitigation:** number format is schema-validated (`pattern`), the creation flow pre-fills the next free volgnummer per year, and design.md documents the declarative-first mechanism with a safe fallback; a duplicate is caught by validation/review, never silently renumbered.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers schemas/pages on next load/build (ADR-037 fragments are additive; no edits to existing schemas or services). Already-created Raadsinformatiebrief/TechnischeVraag objects remain soft-retained in OpenRegister; published objects are withdrawn by clearing the predicate (`depublicatiedatum`) via the normal staff flow. No data migration to undo.

## Open Questions

- Exact declarative mechanism for the `RIB-{jaar}-{volgnummer}` auto-number (OR default/computed support) — resolved during implementation; fallback documented in design.md D5.
