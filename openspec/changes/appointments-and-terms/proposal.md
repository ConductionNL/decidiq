---
kind: code
---

# Proposal: appointments-and-terms

## Summary

Add an appointments & terms register to decidesk: a `Voordracht` (nomination) schema carrying candidates, the nominating party, motivation, and a declarative status lifecycle (`ingediend → behandeld → benoemd | niet-benoemd | ingetrokken`) linked to the deciding agenda item and (secret) voting round; a benoemingsbesluit linkage so every accepted voordracht produces a Decision reference AND an assistively created Membership prefilled from the voordracht (every Membership traceable to its appointing decision); a `TermijnRegeling` (term-rule) model per body-role (term length, max consecutive terms) from which each Membership's term number and end-of-term date are derived; a regeneratable, CSV-exportable, optionally publicly published **rooster van aftreden** (rotation schedule) per body; declarative herbenoemingsrappels (e.g. 6/3 months ahead of term end) to the secretary; and a vacancy flow that opens the Post on term end or resignation and suggests a voordracht — plus pages, menu, and a terms-expiring-within-N-months KPI. All delivered as register fragment `61-appointments-and-terms.json` (ADR-037) + manifest fragments, reusing the existing Person/Membership/Post, GovernanceBody, and ballot capabilities.

## Motivation

Demand cluster `nomination-management` scores **740** in the 2026-07-16 market deep-dive — standard iBabs/board-portal territory (RvC/RvT rotation schedules and reappointment tracking are table stakes in corporate board portals; municipal committee and commissie benoemingen run through voordrachten every raadsperiode). Novelty verification against this worktree (2026-07-17) confirms decidesk covers only the substrate, verdict **PARTIAL**:

- `person-and-membership` gives Membership `startDate`/`endDate` (REQ-PMB-002/011) and Post as a vacancy-capable formal position (REQ-PMB-012) — the data model of holding a seat, with no appointment process on top.
- `governance-body-crud` gives GovernanceBody `termStart`/`termEnd` — the body's period, not its members' terms.
- `preferential-ballot` + `secret-ballot` + `voting-system` cover the election **mechanics** (ranked-choice rounds, secret rounds, VotingRound resolving a DecisionStage) — but nothing to elect anyone *into*.

**Voordracht/nomination, benoemingsbesluit→Membership linkage, zittingstermijn limits, rooster van aftreden, and herbenoemingsrappels are all zero-hit** in specs and changes. Today a griffie or bestuurssecretaris keeps the rooster van aftreden in Excel next to the RIS, reappointments are missed until a term has silently lapsed, and no Membership records *which decision* appointed the person.

## Affected Projects

- [ ] Project: `decidesk` — new `Voordracht`, `TermijnRegeling`, `RoosterVanAftreden`, `RoosterRegel` schemas (`lib/Settings/register.d/61-appointments-and-terms.json`), benoeming/rooster services + CSV export endpoint, manifest.d pages + menu, dashboard KPI widgets, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, RBAC published-predicate, relations, widget aggregations are existing capabilities).

## Scope

### In Scope

1. **Voordracht schema** (register fragment 61): target Post and/or body+role, one or more candidates (Person references or external name for not-yet-registered candidates), voordragende partij (fractie, body, or person), motivering, declarative status lifecycle `ingediend → behandeld → benoemd | niet-benoemd | ingetrokken` (`x-openregister-lifecycle`, ADR-031), link to the deciding agenda item and to the (secret) `VotingRound` — **referencing** the existing preferential-ballot/secret-ballot/voting-system mechanics, never adding new vote mechanics.
2. **Benoemingsbesluit linkage**: a voordracht reaching `benoemd` carries a Decision reference (the benoemingsbesluit) and produces/links the resulting Membership via assistive creation prefilled from the voordracht (startDate, role, body, person) — so every Membership traces to its appointing decision through the voordracht.
3. **Zittingstermijn model**: `TermijnRegeling` per body-role with term length and max consecutive terms (configurable); each Membership gains a **derived** term number and end-of-term date (computed from the regeling + the person's consecutive membership history — the Membership schema itself is owned by `person-and-membership` and is not modified).
4. **Rooster van aftreden**: per-body rotation schedule (who retires when, ordered by end-of-term date), regeneratable from live memberships, exportable as CSV, with a public publication option (publication predicate on the live rooster object — RvC roosters are commonly published).
5. **Herbenoemingsrappels**: declarative notifications ahead of term end (configurable windows, default 6 and 3 months) to the secretary/griffie (`x-openregister-notifications`, ADR-031).
6. **Vacancy flow**: term end or resignation opens the Post (existing Post vacancy semantics — a Post is vacant when no active Membership fills it) and suggests a prefilled voordracht; suggestions are griffie-confirmed, never automatic.
7. **Pages + KPI**: voordrachten index/detail, per-body rooster van aftreden page, termijn-regeling configuration, and a "terms expiring within N months" dashboard KPI.

### Out of Scope

- **Election mechanics** — ranked-choice, secret ballots, tallying are owned by `preferential-ballot`, `secret-ballot`, and `voting-system`; the voordracht only references a VotingRound.
- **Onboarding after appointment** — the `member-onboarding` sibling owns the OnboardingTraject; a benoeming ends this change's responsibility at the created Membership + a reference-only handoff suggestion (state boundary).
- **Wethouder-specific legal checks** (verklaring omtrent gedrag, integriteitstoets, woonplaatsvereiste) — recorded as free-text motivering/notes at most; no legal-validation logic.
- **Modifying the Membership/Post schemas** — owned by `person-and-membership`; term data is derived, never duplicated onto those schemas.
- **HR/remuneration of appointees** — hrmq domain.

## Approach

Four new OpenRegister schemas in fragment `61-appointments-and-terms.json` with declarative lifecycle and notifications. Rooster entries (`RoosterRegel`) are first-class objects — not an array property — because the per-entry end-of-term dates must drive declarative scheduled rappels and the KPI aggregation (see design D3). A small imperative `BenoemingService` for the assistive Membership creation (cross-schema write with prefill), a `RoosterService` for term derivation + (re)generation + CSV export, and a vacancy-suggestion computation; everything else (lifecycle, rappels, pages, KPI, publication predicate) is declarative. Details in design.md.

## New Dependencies

None. All capabilities used (OpenRegister lifecycle/notifications/relations/RBAC published-predicate, manifest v2, widget aggregations) already exist.

## Impact

- `lib/Settings/register.d/` — new fragment 61 (additive; no existing schema modified; 40–60 and 62–65 belong to sibling changes).
- `lib/Service/` + `lib/Controller/` — new benoeming/rooster services, CSV export + regenerate + suggestion endpoints in `appinfo/routes.php`.
- `src/manifest.d/` — new pages/menu fragment; `src/manifest.json` — KPI widgets.
- Reads (never writes schemas of): `person-and-membership` (Person/Membership/Post), `governance-body-crud` (GovernanceBody), `voting-system`/`secret-ballot`/`preferential-ballot` (VotingRound), decision management (Decision), agenda (AgendaItem).

## Cross-Project Dependencies

None outside decidesk. Within decidesk this change **references** sibling wave changes without overlapping ownership: `member-onboarding` (post-benoeming handoff), `fractievoorzitter-fractie-koppeling` (Raadslid/fractie vocabulary for the voordragende partij), and follows the rappel dialect of `toezeggingen-ingekomen-stukken` (scheduled `x-openregister-notifications`, gate-18).

## Risks

### Risk 1: Term derivation across messy membership history
**Severity:** Medium — **Mitigation:** consecutive-term counting is pinned by spec scenarios (gap resets the count; role change starts a new term series); derivation is pure and unit-tested; the rooster stores the derived values on regeneration so a wrong derivation is visible and regeneratable, never silently propagated into Membership.

### Risk 2: Public publication of member data
**Severity:** Medium — **Mitigation:** the rooster is an allow-list artifact (name, role, term dates, herbenoembaar only — no contact details, NC UIDs, or vote data), published via OR's RBAC published-predicate surface exactly like `public-publication`; publishing is opt-in per rooster and withdrawable by clearing the predicate.

### Risk 3: Sibling vocabulary drift (parallel wave)
**Severity:** Low — **Mitigation:** reuse pinned enums/field names from `person-and-membership` (Membership role enum) and the fractievoorzitter vocabulary; deferred review question raised for the wave integrator.

## Rollback Strategy

Additive only: revert the PR (fragment 61, manifest fragment, services/routes). Existing voordracht/rooster objects stay inert in the register and can be pruned via register admin. No existing schema or data is modified, so no data migration or restore is needed.

## Open Questions

- Should max-consecutive-terms be enforced (block a voordracht for an ineligible candidate) or advisory (warn but allow — common practice: bodies deviate by explicit decision)? Proposal assumes **advisory warning**, deviation recorded in motivering.
- Default rappel windows 6/3 months — confirm with griffie/bestuurssecretaris practice (statutory RvC practice often starts 9–12 months ahead).
