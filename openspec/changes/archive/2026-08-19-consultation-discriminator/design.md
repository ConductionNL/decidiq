# Design: consultation-discriminator

## Context

ADR-006 ("Mode Adaptation Over Parallel Entities") forbids new schemas that duplicate an existing concept for a different audience — the failure mode observed in the board-portal incident, where `board`, `board-meeting`, `resolution`, etc. were parallel relabellings of `governance-body`, `meeting`, `decision`. Its escape clause: a parallel schema is allowed only when "an ADR amendment demonstrat[es] the concept is genuinely distinct, not a relabelling."

Decidesk currently has three schemas whose names all contain "consultation":

| Schema | Slug | Properties | `x-schema-org` | Authorization |
|---|---|---|---|---|
| `PublicConsultation` | `public-consultation` | 28 | `schema:Event` | `public` group read when `publicationDate <= $now`, else `authenticated` |
| `MemberConsultation` | `member-consultation` | 15 | `schema:AskAction` | none declared (standard OR RBAC — authenticated/staff only) |
| `ConsultationRequest` | `consultation-request` | 20 | `schema:Action` | none declared (standard OR RBAC — authenticated/staff only; description states "no public publication surface is introduced") |

`PublicConsultation` is already the correct ADR-006 application for its own family: one schema, `consultationType` discriminator (`citizen-participation | market-consultation | tender | idea-box | participatory-budget`), progressive disclosure of type-specific fields (the manifest's own note confirms "28 fields across 5 sub-types"). The open question this change resolves is whether `MemberConsultation` and `ConsultationRequest` should fold into that same schema as two more `consultationType` values, per the "one consultation concept" framing this change was requested under.

## Goals / Non-Goals

**Goals**
- Measure, don't assume: quantify field overlap between all three schemas before concluding fold or no-fold.
- Produce the ADR-006-required evidence record (the addendum) regardless of which way the evidence points.
- Leave the three schemas' actual shape (fields, lifecycle, authorization) untouched — this change only decides and records, it does not implement a fold.

**Non-Goals**
- Implementing a fold (would be a separate `code`+`config` chain per ADR-032 if the evidence had supported it).
- Changing the three existing UI widgets/pages — confirmed unaffected regardless of outcome, see "UI impact" below.
- Re-evaluating `PublicConsultation`'s existing 5-value discriminator — out of scope, already correct.

## Decisions

### Decision 1: Measure field-name overlap pairwise

**Method**: exact property-key intersection between each pair of schemas' `properties` objects (from `lib/Settings/decidesk_register.json` and `lib/Settings/register.d/{47,48}-*.json`), expressed as a share of each side's own field count.

| Pair | Shared field names | Share of smaller-numerator side |
|---|---|---|
| `PublicConsultation` (28) ∩ `MemberConsultation` (15) | `description`, `decision` (2) | 2/28 = 7% of PC · **2/15 = 13% of MC** |
| `PublicConsultation` (28) ∩ `ConsultationRequest` (20) | `governanceBody`, `relatedDecision` (2) | 2/28 = 7% of PC · **2/20 = 10% of CR** |
| `MemberConsultation` (15) ∩ `ConsultationRequest` (20) | `agendaItem`, `lifecycle` (2) | 2/15 = 13% of MC · 2/20 = 10% of CR |

Even the highest ratio (13%, MemberConsultation's overlap with PublicConsultation) is far below what would suggest "the same concept, different labels" — for comparison, the board-portal parallel entities ADR-006 retired shared the *entire* field set 1:1 (a straight rename), not a 13% intersection. Every pairing here is dominated by fields that are *unique* to that schema: `PublicConsultation` carries 8 fields that exist for exactly one `consultationType` value each (`marketScope`, `referenceNumber`, `estimatedValue`, `currency`, `awardCriteria`, `questionDeadline`, `awardedTo`, `procestProcessRef` for `market-consultation`/`tender` alone; `votingEnabled`, `budgetCeiling`, `votingMethod`, `proposalDeadline`, `votingDeadline` for `participatory-budget` alone) — the kind of progressive-disclosure spread ADR-006 Rule 3 describes. `MemberConsultation` and `ConsultationRequest` show no comparable "12 shared + a few type-specific" shape; their fields are almost entirely their own.

### Decision 2: Weigh the qualitative signals, not just the count

Four signals beyond field-name overlap, checked because a low field count alone is not conclusive (two genuinely-the-same concepts with different naming conventions could still show low exact-match overlap):

1. **Authorization model.** `PublicConsultation` is the only one of the three with a declared `authorization.read` block granting the `public` group anonymous access once `publicationDate` has passed (WOO/DIWOO publication). Both `MemberConsultation` and `ConsultationRequest` declare none — internal/staff-only by the OR RBAC default. `MemberConsultation`'s own description states this explicitly as a defining boundary: "NOT citizen input (citizen-participation / PublicConsultation: no public-group read rule, no anonymous-public intake)." Folding either into `PublicConsultation` means every row of the merged schema either (a) inherits the public-read cascade unless every write path is disciplined to scope it per-row — the OR authorization dialect has no per-value conditional composition (no `allOf`/`anyOf`/`oneOf`, the same constraint `ConsultationRequest.suspensionTo`'s own description calls out for its own conditional derivation) — or (b) requires inventing that composition capability first. Given this codebase's own documented pattern of "an unconfigured authorization cascade is open, not closed," treating this as a non-issue would be the least defensible part of a fold.
2. **`x-schema-org` type.** `Event` (PC) vs. `AskAction` (MC) vs. `Action` (CR) — three different domain shapes, not one type with cosmetic relabelling.
3. **Structural cross-reference.** `ConsultationRequest.constituencyConsultation` is a live, already-shipped optional reference *to* `MemberConsultation` (an OR `works-council-consultation` traject may point at an achterbanraadpleging as one of its steps). This is a composition relationship — like `Meeting` referencing `AgendaItem` — not two names for one thing. It also means `MemberConsultation` is already a reusable, standalone "internal poll" primitive independent of the WOR domain (it seeds both a fractie poll and an OR achterban poll). Folding it into `PublicConsultation` would make `ConsultationRequest` reference "a `PublicConsultation` row with `consultationType=member-poll`" for a step whose entire point is that it is *not* public — a naming/RBAC mismatch baked into a formal statutory record.
4. **Lifecycle shape.** PC's `status` field is a 12-value union across its 5 existing subtypes (declared via `x-openregister-lifecycle`). MC's `lifecycle` is a 4-state internal poll (`draft → open → closed → processed`). CR's `lifecycle` is a 9-state bilateral statutory procedure with a derived one-month suspension date (`suspensionTo`, WOR art. 25 lid 6) unique to CR. None of these lifecycles reduce to "one more branch of PC's existing union" without inventing new cross-cutting transition semantics PC's declarative lifecycle dialect does not have today.

### Decision 3: Outcome — no fold, both schemas exempted

Both `MemberConsultation` and `ConsultationRequest` are exempted under ADR-006's escape clause. The evidence is symmetric, not asymmetric: neither schema is meaningfully closer to `PublicConsultation` than the other (13% vs. 10% field overlap is not a material difference), and the strongest qualitative signal — the missing public-authorization block — applies equally to both. This diverges from the framing this change was requested under ("fold member-consultation, keep consultation-request distinct"); see the DEFERRED_QUESTIONS entry in tasks.md / the proposal's Open Questions for explicit human confirmation of this divergence.

`PublicConsultation` remains the single ADR-006-compliant discriminated concept for the public/market-consultation family — already correctly implemented, no change to its shape.

**Alternatives considered:**
- *(a) Full fold* (all three → `PublicConsultation.consultationType`): rejected — introduces the authorization-cascade risk in Decision 2.1 for both non-public schemas, and the CR→MC structural reference becomes incoherent (a formal record referencing "itself, typed differently").
- *(b) Fold `MemberConsultation` only, keep `ConsultationRequest` distinct* (the framing this change was requested under): rejected — the measured evidence does not support an asymmetric split; `MemberConsultation`'s overlap ratio and authorization gap are not smaller than `ConsultationRequest`'s.
- *(c) No fold, both exempted* (chosen): matches the measured evidence in both directions; formalizes the exemption via ADR-006's own required mechanism (an ADR amendment) so it does not need re-deriving.

## Declarative-vs-imperative decision (ADR-031)

Not applicable — this change modifies no `x-openregister-lifecycle`, `x-openregister-notifications`, `x-openregister-aggregations`, `x-openregister-calculations`, `x-openregister-relations`, or dashboard-widget behavior on any schema. Only the human-readable `description` string and the semver `version` number change; no behavior is introduced or altered.

## UI impact

No change. The three widgets (`decision-public-consultations`, `decision-member-consultations`, `decision-wor-consultations` in `src/manifest.json`) and their three index pages (`Consultations` / `/consultations`, `Raadplegingen` / `/raadplegingen`, `WorTrajecten` / `/wor-trajecten`) already point at three distinct schema slugs and three distinct detail routes (`ConsultationDetail`, `RaadplegingDetail`, `WorTrajectDetail`). Since no schema folds, no slug retires, no route needs to move or be removed — the "no orphaned routes" ADR-044 concern this change was scoped to respect is satisfied by not touching routes at all. All three routes already nest under the single `Decisions` nav-ceiling cluster in `src/menu-layout.json` (`Raadplegingen`, `Consultations`, `WorTrajecten` all map to `Decisions`) — confirmed by grep, no new nav-ceiling pressure.

## Seed Data

Not applicable — this change introduces no new schema and modifies no property, so no new seed objects are required. All three schemas' existing seed data remains valid unchanged:
- `PublicConsultation`: seeded via existing base register seed data (multiple consultationType examples already present).
- `MemberConsultation` (3 objects) + `MemberConsultationResponse` (3 objects): seeded in `lib/Settings/register.d/48-constituency-consultation.json` — `raadpleging-fractie-parkeervisie` (open), `ledenraadpleging-alv-contributie` (draft), `achterban-or-thuiswerkregeling` (processed, with results).
- `ConsultationRequest` (3 objects): seeded in `lib/Settings/register.d/47-works-council-consultation.json` — `adviesaanvraag-reorganisatie-logistiek`, `instemmingsverzoek-werktijdenregeling`, `adviesaanvraag-outsourcing-ict` (the last exercising the full opschortingstermijn flow).

The description/version edits in this change do not touch the `seedData` blocks in either register.d file.

## Risks / Trade-offs

- [Risk] Editing `register.d/47` and `register.d/48` — files owned by two still-open sibling OpenSpec changes — could collide with in-flight edits to the same version field. → Mitigation: description+version-only diff, re-check `git diff` immediately before commit (see proposal Risk 1).
- [Risk] The "no fold" conclusion may be revisited later if a future consultationType genuinely does share PublicConsultation's public/RBAC shape (e.g., a future "open enquête" type). → Mitigation: the ADR-006 addendum documents the *method* (measure field overlap + check the authorization/schema.org/structural signals), not just the one-time conclusion, so a future re-evaluation has a repeatable process rather than starting from zero.
- [Trade-off] This change produces no functional improvement — it is a documentation/governance change. → Accepted: its value is preventing a future, more expensive mis-fold (data migration + RBAC regression) driven by surface-level name similarity, which is exactly the ADR-006 escape-clause mechanism's intended use.

## Migration Plan

No data migration. No schema shape (fields, lifecycle, authorization, relations) changes on any of the three schemas — only `description` text and a semver patch bump (`0.3.0→0.3.1` for `PublicConsultation`, `0.1.0→0.1.1` for `MemberConsultation` and `ConsultationRequest`). Deploy is a normal app release (register JSON is re-imported by the existing `<install>`/`<repair>` OpenRegister hook per this app's own conventions). Rollback is a plain revert of the four-file diff — no live objects are touched, so there is no rollback ordering concern.

## Open Questions

- Should the ADR-006 addendum be additionally cross-referenced from the `works-council-consultation` and `constituency-consultation` change design docs before they archive, so the exemption reasoning travels with those changes' own historical record too? Deferred to human judgment — see DEFERRED_QUESTIONS.
