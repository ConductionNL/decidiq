# Design: appointment-decision-type-schema

## Architecture Overview

```
            BEFORE                                  AFTER
  ┌────────────────────────┐              ┌──────────────────────────────┐
  │  Decision               │              │  Decision                    │
  │  decisionType ∈ {…,     │              │  decisionType ∈ {…,          │
  │   appointment, …}       │   ───────►   │   appointment, …}            │
  │  (unused — no folded    │              │  + targetBody / targetPosts /│
  │   fields, generic seed) │              │    targetRole / candidates / │
  └────────────────────────┘              │    nominatingParty /         │
  ┌────────────────────────┐              │    appointedMemberships      │
  │  Voordracht (register.d/│              └──────────────┬───────────────┘
  │   61) — parallel schema,│   (retired)          route  │  Membership
  │  own 5-state lifecycle, │                             │  (materialized by the
  │  agendaItem/votingRound │                             │   dependent change,
  │  fields reinventing     │                             │   appointment-decision-
  │  scheduling              │                             │   type-membership)
  └────────────────────────┘              ┌──────┐┌──────┐┌┴────────────┐
                                           │ Post ││ Body ││DecisionStage│
                                           └──────┘└──────┘└─────────────┘
```

`Decision` already provides the generic scheduling/route machinery
(`route` → `DecisionStage`, `DecisionStage.decisionStage` → `VotingRound`) that
`Voordracht` reinvented with its own `agendaItem`/`votingRound` fields. Folding
removes that duplication as a side effect, not just the vocabulary duplication
ADR-005/ADR-006 target directly.

## Goals / Non-Goals

**Goals**
- Give `decisionType=appointment` real folded fields, matching the pattern
  already established for motion/amendment/resolution.
- Retire the parallel `Voordracht` schema; re-seed its 3 demo objects as typed
  decisions.
- Keep `TermijnRegeling`/`RoosterVanAftreden`/`RoosterRegel` untouched — they
  depend on `Membership`, not `Voordracht`.

**Non-Goals**
- Membership materialization on adoption (imperative service) — dependent change.
- Decision-form Vue UI for the new fields — dependent change (this change is
  config-only: schema register + manifest JSON, no Vue/PHP).
- Adding a "Nominations" nav entry — owned by `ia-six-clusters`.

## Decisions

### D1: Fold Voordracht into Decision, not "workflow record behind the route"

Two options were evaluated, per the product decision's explicit instruction to
choose between them:

**Option A — Voordracht becomes the workflow record behind the appointment
decision's route.** `Voordracht` would stay as a schema, gain a `decision`
reference to the `Decision` it feeds, and sit "in front of" the decision the
way an agenda item sits in front of a meeting: candidates are collected and
narrowed in the `Voordracht`, and a `Decision` (decisionType=appointment) is
created only once a candidate is settled.

**Option B — Voordracht is folded into Decision (chosen).** `candidates`,
`targetRole`, `targetBody`, `targetPosts`, `nominatingParty` become folded
fields on `Decision` itself, revealed by `decisionType=appointment`. The
existing `Decision` lifecycle (draft→…→decided→enacted) carries the nomination
through to adoption; no separate pre-decision object exists.

**Chosen: Option B.** Reasons:
1. **ADR-006 is explicit**: "A new schema that duplicates an existing concept
   for a different audience... requires an ADR amendment demonstrating the
   concept is genuinely distinct, not a relabelling." A nomination-that-gets-
   decided-upon is not genuinely distinct from a motion-that-gets-decided-upon
   or a resolution-that-gets-decided-upon — both already fold into `Decision`.
   Option A would need exactly the ADR amendment ADR-006 requires and demonstrating
   distinctness fails: `Voordracht.lifecycle` (`submitted→handled→appointed|
   not-appointed|withdrawn`) is a strict subset of `Decision.lifecycle`'s states
   under the same D2 mapping already used for motion/resolution.
2. **Direct precedent**: `unify-decision-supertype` already folded three
   candidate-bearing, decided-upon concepts (motion, amendment, resolution)
   into `Decision` using exactly this discriminator + progressive-disclosure
   pattern. `appointment` was reserved in the same enum at the same time,
   for the same reason — this change simply finishes that work.
3. **Option A duplicates machinery Decision already has for free.**
   `Voordracht.agendaItem` and `Voordracht.votingRound` reinvent what
   `Decision.route` → `DecisionStage` → `VotingRound` already provides to
   every decision type generically (including the `decision-methods`
   capability's vote/chair-register/advice/signature methods). Keeping
   `Voordracht` separate would mean appointment decisions get scheduling
   twice, through two different mechanisms, permanently out of sync.
4. **A "workflow record behind the route" is a real pattern elsewhere in this
   register** (e.g. `DecisionStage` genuinely is a per-decision, per-stage
   record distinct from `Decision` — it has its own status/outcome per stage,
   not folded fields). The distinguishing test is: does the candidate record
   need to exist independently of a `Decision` (many decisions per record, or
   a record with no eventual decision)? For `Voordracht`, no — every
   `voordracht` in the current seed data exists to produce exactly one
   appointment decision. That 1:1, decided-upon-once shape is precisely what
   `decisionType` was built to discriminate.

### D2: Field mapping, Voordracht → Decision

| `Voordracht` field | `Decision` (decisionType=appointment) field | Note |
|---|---|---|
| `body` (required) | `targetBody` (required, form-enforced) | renamed to avoid confusion with `Decision`-as-a-whole not otherwise having a "body of text" field named `body` |
| `post` (optional) | `targetPosts` (optional array) | pluralized — product decision requires "ONE OR MORE Posts" |
| `targetRole` (required) | `targetRole` (required, form-enforced) | unchanged, same enum (pinned to `person-and-membership` Membership role enum) |
| `kandidaten` (required, minItems 1) | `candidates` (required, minItems 1, form-enforced) | property key translated to English (the retired schema's Dutch key was pre-existing debt this fold removes rather than propagates) |
| `nominatingParty` | `nominatingParty` | unchanged shape (`type`/`name`/`reference`) |
| `rationale` | *(dropped — use `Decision.text`)* | `Decision.text` (required on every decision) already carries the decision's substance/motivation; a second free-text field would duplicate it |
| `lifecycle` (bespoke 5-state) | `Decision.lifecycle` (shared 7-state) | see D3 |
| `agendaItem` | *(dropped — use `Decision.route`)* | `Decision` schedules generically via `route`/`DecisionStage`; no bespoke field needed |
| `votingRound` | *(dropped — use `DecisionStage.decisionStage`)* | same reason |
| `decision` (self-ref to the outcome decision) | *(dropped)* | no longer needed — the Voordracht row IS the decision now |
| `membership` (single) | `appointedMemberships` (array, nullable, server-set) | pluralized to match "ONE OR MULTIPLE Persons"; populated by the dependent change's service, not by this change |

### D3: Lifecycle mapping (reuse D2 of unify-decision-supertype)

| `Voordracht.lifecycle` | `Decision.lifecycle` + `outcome` |
|---|---|
| `submitted` | `proposed` |
| `handled` | `deliberating` (or `voting`, if a `VotingRound` is in progress via the stage route) |
| `appointed` | `decided` (`outcome=adopted`), typically followed by `enacted` once the Membership is materialized |
| `not-appointed` | `decided` (`outcome=rejected`) |
| `withdrawn` | `withdrawn` |

No changes to `Decision.x-openregister-lifecycle` itself — the existing
declarative transition map already supports every state above; this is a
value-mapping exercise for the 3 re-seeded objects, not a schema change to the
lifecycle block.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Classification | Where it lives |
|---|---|---|
| Appointment folded fields (`targetBody`, `targetPosts`, `targetRole`, `candidates`, `nominatingParty`) | Declarative | Plain schema properties on `Decision`, revealed via progressive disclosure — same as every other folded `decisionType` field group. No new dialect needed. |
| `appointedMemberships` as a data field | Declarative | Plain nullable array property, `$ref Membership` — same shape as `Decision.amends`/`Decision.offer`. |
| **Materializing new `Membership` objects on adoption** | **Imperative (ADR-031 exception: lifecycle guard)** | NOT declared in this change. OpenRegister's declarative dialects (`x-openregister-lifecycle`, `-aggregations`, `-calculations`, `-notifications`) have no primitive for "create a new object of a different schema as a side effect of a state transition" — `x-openregister-calculations` only derives scalar/date values via `CalculationEvaluator` (see `Decision`'s own `x-decidesk-effectiveStatus-note` for the same limitation already documented on this schema), and `x-openregister-relations` (used elsewhere in this register) links existing objects, it does not create them. This mirrors the ORIGINAL `Voordracht` design's own conclusion (`register.d/61` `_note`: "Assistive Membership creation on benoeming... is imperative"). The service ships in the dependent change `appointment-decision-type-membership`, guarding the transition into `decided`/`enacted` for `decisionType=appointment` decisions with `outcome=adopted` — a lifecycle guard per the ADR-031 exception list, not a general-purpose service class. |

This change declares the field (`appointedMemberships`) the service will
populate, but ships no service code — keeping this change `kind: config` per
ADR-032.

## Security Considerations

No new endpoints, no new auth surface — same `Decision` object permissions
apply to appointment-typed decisions as to every other type. `candidates[].person`
resolves through the existing `Person` RBAC; an external (not-yet-registered)
candidate is a free-text `externalName`, never a credential or contact detail,
so no new PII surface is introduced beyond what `Voordracht` already carried
(and which is removed, not duplicated, by this fold).

## NL Design System

No frontend code in this change (config-only). The dependent change's
progressive-disclosure form fields will follow the existing pattern
(`NcSelect` with `inputLabel` for `targetRole`; conditional fieldset for the
appointment field group) — noted here for continuity, not implemented here.

## File Structure

```
lib/Settings/decidesk_register.json              # Decision: folded fields (0.7.0 → 0.8.0) + re-seeded appointment decisions
lib/Settings/register.d/61-appointments-and-terms.json  # Voordracht schema + its seedData block removed; other 3 schemas untouched
src/manifest.d/appointments-and-terms.json        # Voordrachten/VoordrachtDetail menu+pages removed; other 6 pages/2 menu entries untouched
```

## Seed Data

Re-author the 3 existing `voordracht` seeds as `Decision` seeds with
`decisionType=appointment`, using the field mapping in D2 and lifecycle mapping
in D3. Objects added under `x-openregister.seedData.objects.decision` in
`lib/Settings/decidesk_register.json` (the existing Decision seed array,
alongside the current 8 decisionType seeds already there — including the
generic `besluit-benoeming-penningmeester` seed, which is left as-is: it
predates this change and stays a `meeting-outcome`-shaped example of an
association appointment made by acclamation, distinct from the 3 formal
voordracht-derived seeds below which exercise the full candidate/nomination
field group).

### Schema: `decision` (new `decisionType=appointment` seeds)

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `benoeming-lid-auditcommissie` | `benoeming-lid-rvc-acme-van-duin` | `benoeming-voorzitter-auditcommissie-ingetrokken` |
| decisionType | `appointment` | `appointment` | `appointment` |
| title | Benoeming lid auditcommissie | Benoeming lid Raad van Commissarissen ACME B.V. | Benoeming voorzitter auditcommissie (ingetrokken) |
| text | Voordracht van mevrouw M. Janssen als lid van de auditcommissie, vanwege gewenste financiële expertise. | Voordracht van mevrouw J. van Duin (externe werving) als lid van de Raad van Commissarissen, conform profielschets — herbenoeming van de huidige commissaris was niet mogelijk op grond van het rooster van aftreden. | Voordracht van de heer K. Bakker als voorzitter van de auditcommissie; kandidaat heeft zich teruggetrokken voor behandeling. |
| lifecycle | `proposed` | `enacted` | `withdrawn` |
| outcome | *(absent — in flight)* | `adopted` | *(absent — withdrawn, never decided)* |
| targetBody | `auditcommissie-provincie-nh` | `raad-van-commissarissen-acme-bv` | `auditcommissie-provincie-nh` |
| targetPosts | `[]` | `[]` | `[]` |
| targetRole | `member` | `member` | `chair` |
| candidates | `[{ "person": "marie-janssen", "notes": "Financiële expertise gewenst in de auditcommissie." }]` | `[{ "externalName": "Mw. J. van Duin", "notes": "Externe werving; nog geen Person-registratie — wordt na benoeming aangemaakt." }]` | `[{ "externalName": "K. Bakker", "notes": "Kandidaat trekt zich terug voor behandeling." }]` |
| nominatingParty | `{ "type": "politicalGroup", "name": "D66" }` | `{ "type": "body", "name": "Raad van Commissarissen (coöptatie-voordracht)" }` | `{ "type": "politicalGroup", "name": "GroenLinks" }` |
| appointedMemberships | `[]` (nullable — no service ships in this change) | `[]` (nullable — no service ships in this change) | `[]` |
| isPublished | `internal` | `internal` | `internal` |

**Related items per object:** none beyond the `candidates[].person`/`targetBody`
references already declared — matching the retired `Voordracht` seeds, which
carried no file/note/task/contact attachments of their own.

## Trade-offs

- **[Lost `agendaItem`/`votingRound` linkage from the 2 seeds that had them]**
  → Both original seeds referenced the nil-UUID placeholder for `agendaItem`
  (`voordracht-rvc-vanduin`) — i.e. no real seeded agenda item existed to link
  to anyway. Dropping the field loses nothing on re-seed; a real appointment
  decision post-migration links via `Decision.route` like any other decision.
- **[`appointedMemberships` sits empty until the dependent change ships]** →
  documented explicitly in the spec (Requirement: "Adopted appointments record
  their materialized Memberships") so a reviewer or the Hydra pipeline reading
  this change alone isn't surprised the field is inert; the dependent change
  is what makes it live.
- **[Dutch `kandidaten` → English `candidates` rename loses byte-identical
  compatibility with any external consumer of the old field name]** → none
  exists (verified: `voordracht`/`Voordracht` appears only in the 2 files this
  change edits, repo-wide); this is the cheapest point to fix pre-existing
  Dutch-key debt, since the schema is being retired anyway.
