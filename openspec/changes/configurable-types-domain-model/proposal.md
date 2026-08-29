---
kind: code
---

# Proposal: configurable-types-domain-model

## Summary

decidiq's domain model is **too concrete**. Variability that belongs to an
organisation's *configuration* is instead frozen into hardcoded enums and, when
an enum could not stretch, into a brand-new schema. The register has grown to
**95 schema declarations across 27 files** (39 in `decidesk_register.json`, 56
more added by `lib/Settings/register.d/*.json`), and the navigation to **44
entries**, because 24 consecutive OpenSpec changes each answered "this domain
needs X" by adding a schema for X.

This change introduces **type objects**: for each of decidiq's five universal
entities, the variability moves out of a compile-time enum and into a
configurable, per-organisation type object that the instance *references*.

| Instance (exists) | Type object (new) | What moves into the type |
|---|---|---|
| `Meeting` | **`MeetingType`** | which gremium may hold it, which agenda-item types it admits, default quorum/voting rule |
| `AgendaItem` | **`AgendaItemType`** | what an item of this kind carries, its lifecycle, whether it is votable, which body owns it |
| `Decision` | **`DecisionType`** | **which gremium is competent to take it**, voting rule, threshold, state machine |
| `Post` (position) | **`PositionType`** *(on the gremium's configuration)* | which positions exist on this gremium; which hold types are valid |
| `Membership` | **`PositionHold`** | a membership holding a position, for a duration, with a hold type |

Plus two structural corrections:

- **`GovernanceBodyComposition`** — a gremium may be composed of other gremia,
  either `direct` (the same people) or `representation` (n seats, and each seat
  **is a position**, so the representative's tenure is an ordinary
  `PositionHold`).
- **`RoosterVanAftreden` / `RoosterRegel` are retired as entities.** A
  retirement schedule is a *query* over term ends, not a stored object.

## Motivation

### This is not a new principle — it is two accepted ADRs, applied one level up

The repo already decided this, twice:

- **ADR-005** (accepted 2026-06-14) — `Decision` is the single universal
  supertype; `motion`/`resolution` are *values of a discriminator*, not schemas.
- **ADR-006** (accepted 2026-06-14) — "Domain differences are expressed by mode
  adaptation, **never** by parallel entities. There is exactly one schema per
  concept." It explicitly forbids "a new schema that duplicates an existing
  concept *for a different audience*".

ADR-006 offered three mechanisms, in order of preference: label adaptation, a
**type discriminator enum**, and progressive disclosure. Mechanism 2 is where
the model broke. A discriminator enum is a *closed, compile-time* list. The
moment a tenant needs a sixth meeting kind, or a fourth kind of agenda item, an
enum cannot answer — and the only remaining move under ADR-006's own menu is a
new schema. So the ADR that forbade schema proliferation **caused** it, by
offering a closed vocabulary as the escape hatch.

The evidence is in the register. Every one of these is a concept that a
configurable type would have absorbed:

| Shipped schema | Should have been |
|---|---|
| `MondelingeVraag` | `AgendaItem` of type *oral question* |
| `Interpellatieverzoek` | `AgendaItem` of type *interpellation* |
| `IngekomenStuk` | `AgendaItem` of type *incoming document* |
| `Raadsinformatiebrief` | `AgendaItem` of type *information letter* |
| `KascommissieVerklaring` | `AgendaItem` of type *audit-committee report* |
| `Toezegging` | `AgendaItem`/`ActionItem` of type *commitment* |
| `RoosterVanAftreden` + `RoosterRegel` | a query over `Membership.endDate` / `PositionHold.endDate` |

Each also bought a top-level nav entry, which is why `menu-layout.json` exists
at all: a 44-entry flat nav had to be *relocated* back under six clusters by a
separate change (`ia-six-clusters`). The nav sprawl is a symptom; the model is
the disease.

### The five defects, in Ruben's terms

1. **Meeting types are hardcoded.** `Meeting.meetingType` is a five-value enum
   (`regular`, `extraordinary`, `committee`, `public hearing`,
   `general_assembly`) with no relation to the body holding the meeting. A
   gremium cannot define its own meeting kinds. The flat, undifferentiated
   meeting list in the UI is this missing abstraction, surfacing.
2. **Positions are not part of the gremium's configuration.** `Post` exists
   (`label`, `role`, `governanceBody`, `startDate`, `endDate`) but its `role` is
   the same hardcoded seven-value enum as `Membership.role`, duplicated. A board
   has a president; nothing lets a board *declare* that.
3. **A hold has no duration and no type.** `Membership.post` is a bare string
   reference. There is no object for "X held the presidency, as interim, from A
   to B". `Post.startDate`/`endDate` conflate *when the position exists* with
   *who held it when* — so a position cannot outlive its holder, and successive
   holds are inexpressible. There will always be a next president.
4. **The retirement schedule is a stored entity.** `RoosterVanAftreden` carries
   `generatedOn`/`generatedBy` — it is a materialised snapshot — and
   `RoosterRegel` re-stores `personName`, `role` and `endTermDate`, data that
   already exists on `Membership`. It is a cache with no invalidation. Worse, it
   can only carry *one* end date per person, while the real world has two: a
   council member until date A, faction leader until date B.
5. **Decisions are concrete.** `Decision.decisionType` is a ten-value enum on a
   ~50-property flat bag mixing motion, appointment and contract fields.
   `DecisionTemplate` exists and is *almost* the right object — it already
   carries `stateMachine`, `votingRule`, `quorumRule`, `checklist` — but
   **`Decision` has no property referencing it**. It is a copy-once seed, not a
   live type. And neither carries the one thing that matters for authorisation:
   **which gremium is competent to take this decision.**

### Factions and bodies are already one concept

Checked, not assumed: `GovernanceBody.bodyType` already includes `faction`, and
the two seeded factions (`groenlinks-fractie-amsterdam`,
`d66-fractie-amsterdam`) are ordinary `GovernanceBody` objects with
`parentBody` pointing at the council. **There is no second schema.** The
duplication is only in the *label*: the navigation reads "Factions & bodies",
which invites the reader to believe there are two kinds of thing.

The real risk is forward, not backward: the unimplemented change
`fractievoorzitter-fractie-koppeling` (47 open tasks, 0 done) plans a
first-class **`Fractie` schema**, and four shipped fragments already carry
forward-references to it. That would re-introduce the duplication ADR-006
retired. This change **cancels that schema** before it lands and keeps the
faction as a `GovernanceBody`.

## Affected Projects

- [x] `decidiq` — this change. Type schemas, composition, position holds, the
  retirement-schedule retirement, nav collapse, seed data, UI.
- [ ] `humaniq` — a **handoff, not implemented here**. `OnboardingTraject`,
  `OffboardingTraject`, `RoosterVanAftreden`, `RoosterRegel` and
  `TermijnRegeling` are employee-lifecycle concepts that do not belong in a
  decision-making app. See `humaniq-handoff.md` in this change directory.

## Scope

### In Scope

1. **`MeetingType`** — a configurable meeting kind owned by a gremium.
2. **`AgendaItemType`** — a configurable agenda-item kind, admitted by a meeting
   type, optionally owned by a *different* body than the meeting's.
3. **`DecisionType`** — promotes `DecisionTemplate` to a live type by giving it
   `competentBody` and giving `Decision` a `decisionType` **reference**
   (the existing enum is retained, deprecated, as `decisionCategory`).
4. **`PositionType`** on the gremium configuration, and **`PositionHold`** as
   the durational, typed link between a `Membership` and a position.
5. **`GovernanceBodyComposition`** — direct and representation composition,
   where a representation seat *is* a position.
6. **Retire `RoosterVanAftreden` / `RoosterRegel`** as entities; the retirement
   schedule becomes a derived view over `Membership.endDate` and
   `PositionHold.endDate`, so one person can hold two different end dates.
7. **Collapse five concrete schemas into `AgendaItem` + seeded types**:
   `MondelingeVraag`, `Interpellatieverzoek`, `IngekomenStuk`,
   `Raadsinformatiebrief`, `KascommissieVerklaring`.
8. **Nav**: "Factions & bodies" → "Organisation"; the five collapsed leaves stop
   being top-level menu entries and become filtered views of Agenda items.
9. **Competence enforcement** — `DecisionType.competentBody` is *checked at the
   write path*, not merely declared. decidiq has a documented history of
   authorisation defects (orphaned auth methods, `#[NoAdminRequired]` without a
   per-object guard, fail-open `catch (\Throwable) { return null; }` resolvers).
   A schema change that says who may decide what is worthless if nothing calls
   it.
10. **Acceptance scenario** (Ruben's, built end to end): gremia *Management
    team* and *Development team*; a gremium *Pub quiz* composed of both; a pub
    quiz meeting with questions that can be voted on.
11. **UI fixes carried alongside** — the `RunningProcessesWidget` top-left
    margin, dashboard widget order, and a calendar view on the meeting index.

### Out of Scope

- **Implementing anything in `humaniq`.** This change produces a proposal for
  that app's own backlog; it does not touch `hrmq`.
- **Editing `@conduction/nextcloud-vue`.** The shared library is owned by
  another agent this wave. The meeting calendar view is therefore built as a
  decidiq-local view component, not as a new `CnIndexPage` view mode.
- **Migrating the other 19 concrete schemas.** This change establishes the
  pattern and migrates the five agenda-item ones. The rest are staged as
  follow-ups so a single PR stays reviewable.
- **Deleting the deprecated enums.** `Meeting.meetingType` and
  `Decision.decisionType` stay readable through a deprecation window; removing
  them is a follow-up once no object still carries only the enum value.

## Risks

| Risk | Mitigation |
|---|---|
| A declared schema with no writer is not a migration target — new schemas land in the descriptor but no object ever moves | Bump `info.version` on the register (the importer skips a version that is not higher), and verify with `occ openregister:descriptors:list` **and** an object count, not a schema count |
| Adding properties to an existing OR schema without a subsequent write leaves the physical column missing | Run `occ openregister:tables:reconcile` and assert the column exists before seeding |
| A competence check that is defined but never invoked (gate-`orphan-auth`, observed on decidesk#60) | Every `competentBody` check ships with a caller and a test that fails when the call is removed |
| The nav collapse orphans live routes (gate-53/ADR-044) | Pages stay routable for deep links; only the *menu leaf* is removed, exactly as `menu-layout.json` already documents |
