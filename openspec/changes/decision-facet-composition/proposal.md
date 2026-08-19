---
kind: config
---

# Proposal: decision-facet-composition

## Summary

Turn the Decision Detail page into the hub for everything that informs a decision (ADR-004 Rule 3 + the Back to Six plan): eight new declarative `object-list` widgets on the existing `DecisionDetail` page in `src/manifest.json`, each a reverse-lookup filtered on this decision's id against an already-shipped OpenRegister schema — public consultations, member consultations, works-council (WOR) consultation requests, advisory-opinion requests, zienswijzerondes, zienswijzen, commitments (toezeggingen), and a read-only confidentiality (geheimhouding) status. No new schema, no new Vue component, no new route — every target schema already exists in `lib/Settings/register.d/` from six sibling changes that shipped their own index/detail pages but stopped short of wiring the reverse-lookup back onto the universal Decision.

## Motivation

Decidesk models the individual consultation/advice/confidentiality/commitment trajectories as first-class objects (each with its own lifecycle, deadlines, and detail page), and six already-shipped changes — `works-council-consultation`, `advisory-opinion-workflow`, `shared-governance-bodies`, `embargo-geheimhouding`, `toezeggingen-ingekomen-stukken`, `constituency-consultation` — each gave their schema a `relatedDecision`/`decision`/`targetDecision` reference field back to the universal `Decision`. But the reference is one-directional and nothing renders the reverse edge: opening a Decision today shows nothing about the consultations that informed it, the advisory opinions requested on it, the zienswijzen it triggered, the commitments it produced, or whether it currently sits under geheimhouding. A griffier has to already know which WOR traject or adviesaanvraag references a given besluit and navigate there directly — the Decision Detail page, meant to be the governance dossier, is blind to its own inputs.

This gap was called out and explicitly deferred by name in the fragment that shipped `MemberConsultation`: `src/manifest.d/constituency-consultation.json`'s own note says "the linked-item 'Raadpleging (niet-bindend)' reverse-lookup section ... requires imperative/component work beyond this declarative core and are added when the services/controller land." This change shows that assumption was wrong for the general case — the existing `object-list` widget type (already used for `GovernanceBodyDetail`'s meetings list and `ConsultationDetail`'s reactions list) needs no imperative work at all; it just needed to be pointed at `DecisionDetail`.

## Affected Projects

- [x] Project: `decidesk` — adds 8 widgets + layout entries to `DecisionDetail` in `src/manifest.json`. No other project is touched.

## Scope

### In Scope

- Eight new `object-list` (and one read-only status) widgets added directly to the `DecisionDetail` page's `config.widgets`/`config.layout` in `src/manifest.json`:
  1. Public consultations (`public-consultation`, filter `decision`)
  2. Member consultations (`member-consultation`, filter `decision`) — closes the gap `constituency-consultation` deferred
  3. Works-council consultation requests / WOR (`consultation-request`, filter `relatedDecision`)
  4. Advisory-opinion requests (`adviceRequest`, filter `relatedDecision`)
  5. Zienswijzerondes (`zienswijzeronde`, filter `decision`)
  6. Zienswijzen (`zienswijze`, filter `decision`)
  7. Commitments / toezeggingen (`toezegging`, filter `relatedMotion`)
  8. Confidentiality status (`geheimhouding`, filter `targetDecision`) — read-only, shows ground + bekrachtiging deadline
- Grid layout placement below the existing 9 widgets on `DecisionDetail`, grouped in three rows by domain.
- A short coordination note in this change's design.md pointing at the deferred note in `constituency-consultation`'s manifest fragment (informational only — that fragment is not edited by this change).

### Out of Scope

- The `Advies` child records of an `Adviesaanvraag` (two-hop from Decision — `Advies.adviceRequest` → `Adviesaanvraag.relatedDecision` → `Decision`; no single-schema `object-list` filter can express that join). They stay reachable via the existing `adviesaanvraag-related` widget on `AdviesaanvraagDetail` one click away.
- Reverse-lookup widgets for `Geheimhouding.ratificationDecision` / `Geheimhouding.dissolutionDecision` (this decision acting as *another* record's bekrachtigingsbesluit or opheffingsbesluit) — a rare secondary case; only the primary `targetDecision` (this decision's own confidentiality status) gets a widget. Flagged as a deferred question below.
- Any change to `Decision`'s own schema (no `goal` reference is added — Decision has none today and none of the studied schemas or specs establish a need for one).
- Editing any sibling change's own manifest fragment (`src/manifest.d/constituency-consultation.json` etc.) or openspec artifacts — this change only touches the base `src/manifest.json`.
- Any registry.js / Vue component work — every widget is declarative (`type: "object-list"`), matching the app's ADR-031 "declarative over imperative" default and the existing `body-meetings` / `consult-reactions` precedents.

## Approach

Add eight widget definitions + matching `layout` grid entries to the existing `DecisionDetail` page object inside `src/manifest.json` (not a `manifest.d/` fragment — `mergePages` in `@conduction/nextcloud-vue`'s `buildManifest` replaces a same-id page wholesale, so a fragment overriding `DecisionDetail` would have to reproduce all 9 existing widgets verbatim and would drift the moment the base page changes; every sibling fragment that referenced `DecisionDetail` navigation left the base page itself untouched, confirming direct edit is the established pattern for extending a page the base file owns). Each new widget is `type: "object-list"` with `content: { register: "decidesk", schema: "<slug>", filter: { <field>: "@objectId" }, columns: [...], rowRoute: "<ExistingDetailRoute>" }` — the same shape as the already-shipped `GovernanceBodyDetail` → `body-meetings` widget. No PHP, no new Vue component, no new route.

## New Dependencies

None.

## Impact

- `src/manifest.json` — `DecisionDetail` page gains 8 widget entries + 8 layout entries. No other page is touched.
- No backend, schema, or route changes. No new OpenRegister schema slugs, no `lib/Settings/register.d/` edits — every referenced schema (`public-consultation`, `member-consultation`, `consultation-request`, `adviceRequest`, `zienswijzeronde`, `zienswijze`, `toezegging`, `geheimhouding`) already ships in the repo today.

## Cross-Project Dependencies

None — decidesk-only, and every referenced schema already exists in this repo (verified directly in `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json`, `47-works-council-consultation.json`, `48-constituency-consultation.json`, `56-shared-governance-bodies.json`, `60-advisory-opinion-workflow.json`, `65-embargo-geheimhouding.json`, and `PublicConsultation` in the base `decidesk_register.json`). No `depends_on` chain is needed (ADR-032) because nothing this change relies on is still pending.

## Risks

### Risk 1: DecisionDetail becomes a very long page
**Severity:** Medium — **Mitigation:** New widgets are grouped in 3 compact rows (3+2+2+... widgets per row, `gridHeight` 5 each) placed below the existing content, so the primary Content/Governance/Lifecycle widgets stay above the fold. Each widget's `limit` is capped at 10 rows with a `viewAllRoute` to the schema's own index page for the full list, keeping each card small even when a decision has many referencing records.

### Risk 2: Filter field-name drift if a sibling schema is edited later
**Severity:** Low — **Mitigation:** All eight filter field names (`decision`, `relatedDecision`, `relatedMotion`, `targetDecision`) were read directly from the shipped `lib/Settings/register.d/*.json` fragments during this proposal's research, not assumed from prose. `openspec-explore`/spec-coverage gates on the sibling schemas would catch a future rename.

## Rollback Strategy

Revert the `src/manifest.json` diff. No data, schema, or route changes to unwind — the widgets are pure read projections over existing objects.

## Open Questions

- Should `Geheimhouding.ratificationDecision` / `dissolutionDecision` (this decision confirming or lifting *another* record's confidentiality) also get their own widget(s), or is the primary `targetDecision` case (this decision's own confidentiality) sufficient for the hub? Provisionally: out of scope (see DEFERRED_QUESTIONS).
- Should this change's design.md note prompt an update to `constituency-consultation/tasks.md` Task 7 (marking its deferred reverse-lookup as satisfied), or is that left for a human to reconcile separately? Provisionally: leave `constituency-consultation`'s own artifacts untouched; note the overlap only in this change's design.md.
