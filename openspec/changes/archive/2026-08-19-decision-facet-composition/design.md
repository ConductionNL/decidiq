# Design: decision-facet-composition

## Architecture Overview

Pure manifest-v2 composition — no backend, no schema, no new Vue component. `DecisionDetail` in `src/manifest.json` gains 8 new `type: "object-list"` widgets (7 list widgets + 1 read-only status list), each a reverse-lookup query against an OpenRegister schema that already ships in this repo and already carries a reference field back to `Decision`:

| Widget id | Schema (slug) | Filter field → `@objectId` | Row route |
|---|---|---|---|
| `decision-public-consultations` | `public-consultation` | `decision` | `ConsultationDetail` |
| `decision-member-consultations` | `member-consultation` | `decision` | `RaadplegingDetail` |
| `decision-wor-consultations` | `consultation-request` | `relatedDecision` | `WorTrajectDetail` |
| `decision-advisory-opinions` | `adviceRequest` | `relatedDecision` | `AdviesaanvraagDetail` |
| `decision-zienswijzerondes` | `zienswijzeronde` | `decision` | `ZienswijzerondeDetail` |
| `decision-zienswijzen` | `zienswijze` | `decision` | `ZienswijzerondeDetail` (no standalone zienswijze route — matches `shared-governance-bodies`'s own index) |
| `decision-toezeggingen` | `toezegging` | `relatedMotion` | `ToezeggingDetail` |
| `decision-geheimhouding` | `geheimhouding` | `targetDecision` | `GeheimhoudingDetail` |

Each `filter`/`schema`/route pairing was read directly out of the shipped `lib/Settings/register.d/*.json` fragments and the corresponding `src/manifest.d/*.json` fragments during this proposal's research — not assumed from the sibling changes' prose, which in two cases (embargo-geheimhouding, works-council-consultation) turned out to differ from the actual shipped field names. See the field-by-field citations in "Decisions" below.

This closes the gap `constituency-consultation`'s own manifest fragment left open: its `_note` on `MemberConsultation`'s `RaadplegingDetail` page states "the linked-item 'Raadpleging (niet-bindend)' reverse-lookup section ... requires imperative/component work beyond this declarative core and are added when the services/controller land." That assumption does not hold for `DecisionDetail` — `object-list` needs no controller, no service, and no new component; the pattern is proven twice already (`GovernanceBodyDetail` → `body-meetings`, `ConsultationDetail` → `consult-reactions`).

## Goals / Non-Goals

**Goals**
- Every already-shipped schema that references `Decision` gets a reverse-lookup widget on `DecisionDetail`, so opening a decision shows everything that informed it, without navigating away first.
- Zero new imperative code — the whole change is a `src/manifest.json` diff.

**Non-Goals**
- Surfacing `Advies` records directly on `DecisionDetail` (two-hop join: `Advies.adviceRequest` → `Adviesaanvraag.relatedDecision` → `Decision`; `object-list`'s `filter` is a single-schema, single-hop query and cannot express this). `Advies` stays one click away via `AdviesaanvraagDetail`'s own `adviesaanvraag-related` widget.
- Surfacing `Geheimhouding.ratificationDecision` / `Geheimhouding.dissolutionDecision` backlinks (this decision acting as *another* record's confirming or lifting besluit). Only the primary `targetDecision` case (this decision's own confidentiality) gets a widget — see "Decisions" below for the reasoning and the deferred question this leaves open.
- Adding a `goal` reference to `Decision`. Checked directly against the schema (`Decision` has 48 properties, none goal-related) and against every schema studied for this change (only `Toezegging.goal` exists, unrelated to Decision) — there is no existing field to surface and no requirement in this change's scope that would justify adding one.
- Editing any sibling change's own manifest fragment (`src/manifest.d/*.json`) or its openspec artifacts.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path chosen | Rationale |
|---|---|---|
| 8 reverse-lookup widgets on `DecisionDetail` | **Declarative** — `type: "object-list"` widgets in `src/manifest.json` | Standard OpenRegister list query (`register` + `schema` + `filter` + `columns`), identical shape to the already-shipped `body-meetings` (`GovernanceBodyDetail`) and `consult-reactions` (`ConsultationDetail`) widgets. No aggregation, no derived field, no lifecycle, no notification — this is a plain filtered read, the textbook case for the declarative default. |

No imperative exception applies (no external integration, no document generation, no NLP, no domain-rule selector, no lifecycle guard, no scheduled bulk work) — this table is complete for the whole change.

## Decisions

### D1: Direct edit to `src/manifest.json`, not a `manifest.d/` fragment

`buildManifest()` (`@conduction/nextcloud-vue`'s shared merge pipeline, `src/utils/buildManifest.js`) calls `mergePages(target, incoming)`, which does `target[idx] = page` on an id match — **a fragment declaring `DecisionDetail` would replace the base page wholesale**, not merge into it. To add 8 widgets via a fragment I would have to reproduce all 9 existing widgets + their layout entries verbatim inside the fragment, and that copy would silently go stale the next time `DecisionDetail`'s base definition changes (widget added/removed/reordered upstream). Multiple sibling fragments call this out explicitly as a known limitation for the *base Dashboard page* (`toezeggingen-ingekomen-stukken.json`, `constituency-consultation.json`, `urgent-decision-procedure.json` all defer a Dashboard KPI widget for exactly this reason) and none of them attempt to override `DecisionDetail` this way — `urgent-decision-procedure.json` explicitly says "Detail navigation reuses the existing DecisionDetail page" and leaves it untouched. Editing the base file directly is the established pattern for extending a page the base file itself owns.

**Alternative considered:** a `manifest.d/decision-facet-composition.json` fragment reproducing the full `DecisionDetail` page. Rejected — drift risk on every future edit to the 9 existing widgets, and no precedent in this codebase for that shape.

### D2: Direct field-name filters, not `_relations.<slug>` dot-paths

`object-list` widgets in this codebase use two different filter idioms: a literal field name (`{"governanceBody": "@objectId"}`, `body-meetings`) or an OpenRegister relations-index dot-path (`{"_relations.public-consultation": "@objectId"}`, `consult-reactions`/`budget-proposals` in `citizen-participation.json`). `citizen-participation.json`'s own note explains the trade-off: the dot-path form is understood by the object-list search endpoint but *not* by the aggregation/stats endpoint, which needs the literal field name. Since none of this change's 8 widgets pair with a `stats-block` aggregation on the same field, either form works; this design uses **literal field names** (`decision`, `relatedDecision`, `relatedMotion`, `targetDecision`) for readability and because every field name was independently confirmed against the shipped schema (see table below) — no ambiguity about which relation-index slug would resolve.

### D3: Field names, verified against shipped schemas, not sibling-change prose

Sibling changes' `design.md`/`proposal.md` prose (still open, not yet archived) in two cases named the field shape differently from what actually shipped:
- `embargo-geheimhouding/design.md` describes "one-directional relations... target ↔ besluit references" without naming `targetDecision` explicitly; the shipped `lib/Settings/register.d/65-embargo-geheimhouding.json` confirms `Geheimhouding.targetDecision` / `ratificationDecision` / `dissolutionDecision` exist exactly as named in this change's product brief.
- `works-council-consultation`'s schema title is `ConsultationRequest` (slug `consultation-request`) with field `relatedDecision` — confirmed directly in `lib/Settings/register.d/47-works-council-consultation.json`.
- `advisory-opinion-workflow`'s schema title is `Adviesaanvraag`, but its **slug is `adviceRequest`** (camelCase, an exception to the kebab-case slug convention used everywhere else in this register) — confirmed in `lib/Settings/register.d/60-advisory-opinion-workflow.json`. This change's widget `content.schema` uses `adviceRequest` to match the actual registered slug.

All eight filter field names in the Architecture Overview table were read from the shipped fragment JSON, not inferred.

### D4: Geheimhouding widget covers only `targetDecision`

The product brief names all three Geheimhouding→Decision reference fields (`targetDecision`, `ratificationDecision`, `dissolutionDecision`). This design ships a widget for `targetDecision` only (the common case: "is this decision itself confidential") and leaves the other two — a decision serving as *another* geheimhouding's confirming or lifting besluit — without a dedicated widget on `DecisionDetail`.

**Alternative considered:** three separate Geheimhouding widgets (one per field). Rejected for this pass — `ratificationDecision`/`dissolutionDecision` backlinks are a secondary, low-frequency case (a griffie decision *about* someone else's confidentiality, not about itself), and three near-identical geheimhouding list widgets on one page reads as clutter for a case that is already reachable from the geheimhoudingenregister overview. Recorded as an open question rather than silently dropped.

## Risks / Trade-offs

- [`DecisionDetail` grows to 17 widgets, page-length risk] → New widgets are grouped in 3 rows below the existing 9, each capped at `limit: 10` with a `viewAllRoute` to the schema's own index for the full list — cards stay small even for a decision with many referencing records.
- [Filter field-name drift if a sibling schema is renamed later] → Field names were verified against the shipped register fragments (see D3), not assumed; `hydra-gate-spec-coverage` and the sibling specs' own gates catch a future rename that isn't mirrored here.
- [Advies not directly visible] → Documented Non-Goal; one click away via `AdviesaanvraagDetail`.

## Migration Plan

None — `src/manifest.json` diff only, no data or schema change. Deploy = merge; rollback = revert the diff.

## Seed Data

Not applicable — this change introduces no new OpenRegister schema and modifies no existing schema. Every object rendered by the new widgets is created by its owning change's own seed data (`45-toezeggingen-ingekomen-stukken.json`, `47-works-council-consultation.json`, `48-constituency-consultation.json`, `56-shared-governance-bodies.json`, `60-advisory-opinion-workflow.json`, `65-embargo-geheimhouding.json`, and the base register's `PublicConsultation` seeds), all of which already carry a `decision`/`relatedDecision`/`relatedMotion`/`targetDecision` reference in their existing seed rows where the source design docs describe one.

## Open Questions

- Should `Geheimhouding.ratificationDecision` / `dissolutionDecision` also get widgets (D4)? Provisionally out of scope — see DEFERRED_QUESTIONS in the change summary.
- Should `constituency-consultation/tasks.md` Task 7 be updated to reflect that its deferred reverse-lookup is now satisfied by this change? Provisionally left untouched — that file belongs to a different, still-open change.

## Trade-offs

Considered a single generic `type: "related"` widget on `DecisionDetail` (the auto-discovery pattern used on `ParticipantDetail`/`ActionItemDetail`/every sibling schema's own detail page) instead of 8 purpose-built `object-list` widgets. Rejected: the product brief asks for curated, distinctly-labelled sections per facet (consultations split three ways, advisory opinions, zienswijzen, commitments, confidentiality) matching how `DecisionDetail` already splits Content/Governance rather than one flat list (ADR-062 rule 3); a single `related` widget would also not support the `allowCreate: false` read-only posture this design requires for the confidentiality widget.
