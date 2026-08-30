# Design: configurable-types-domain-model

## The one-sentence model

**Every universal entity in decidiq is an instance of a type object, and the
type object is data — owned by an organisation, editable by an administrator,
never a compile-time enum.**

## D1. Why an enum was the wrong escape hatch

ADR-006 forbids parallel entities and offers a `bodyType`-style **discriminator
enum** as the sanctioned alternative. That works exactly as long as the set of
subtypes is (a) small, (b) known to the developer, and (c) the same for every
tenant. Governance satisfies none of the three.

A discriminator enum has three properties that a domain type needs and it
cannot provide:

| A type needs to… | An enum value can… |
|---|---|
| be created by an administrator, per organisation | ❌ only by a developer, for everyone |
| carry configuration (who may hold it, what it admits, how it is decided) | ❌ nothing — it is a bare string |
| be referenced, so instances share one definition that can change | ❌ nothing references it; changing it rewrites history |

So when a tenant needed a sixth meeting kind, the only move left inside ADR-006's
menu was a new schema. **The gate's closed vocabulary became a fix instruction.**
This change adds mechanism 2b — *a referenced type object* — and makes it the
preferred one, ahead of the enum.

> **ADR-007 (proposed, in this change):** Where a subtype distinction is
> open-ended or tenant-configurable, express it as a **type object referenced by
> the instance**, not as an enum value and never as a new schema. An enum
> remains correct only for closed sets fixed by law or by the platform
> (`Vote.value` is `for`/`against`/`abstain` and always will be).

## D2. The five type objects

### `MeetingType`

```jsonc
{
  "slug": "meeting-type",
  "name": "Pub quiz night",              // what a user picks
  "governanceBody": "<uuid>",            // WHICH GREMIUM may hold it
  "allowedAgendaItemTypes": ["<uuid>"],  // what may go on its agenda
  "defaultQuorumRule": "majority",
  "defaultVotingRule": "simple-majority",
  "defaultDurationMinutes": 90,
  "lifecycle": { /* x-openregister-lifecycle */ },
  "isPublic": false,
  "active": true
}
```

`Meeting` gains `type` (a reference). `Meeting.meetingType` (the enum) stays,
deprecated, so every one of the existing seeded meetings keeps rendering while
the type objects are backfilled.

This is the *dossiq case-type pattern* applied to meetings: a case in dossiq
picks its zaaktype and inherits phases, allowed results and required documents
from it; a meeting here picks its meeting type and inherits its agenda
vocabulary, quorum and voting defaults from it.

### `AgendaItemType`

```jsonc
{
  "slug": "agenda-item-type",
  "name": "Oral question",
  "owningBody": "<uuid|null>",   // the body that OWNS items of this type…
  "votable": false,              // …which need not be the meeting's body
  "decisionType": "<uuid|null>", // if votable, what decision it produces
  "fields": [ /* additional typed fields, JSON-schema fragment */ ],
  "lifecycle": { /* submitted -> admitted -> scheduled -> answered */ },
  "submissionWindowHours": 24,
  "supportThreshold": null
}
```

**`owningBody` is the field that makes item 8 of the review work.** An agenda
item's owner is independent of the meeting it sits on. That single decoupling
expresses both halves of the kascommissie example with one model:

- the kascommissie holds **its own meeting** → a `Meeting` whose
  `type.governanceBody` is the kascommissie;
- the kascommissie has **an agenda item on the ALV's meeting** → an
  `AgendaItem` on the ALV's meeting whose `type.owningBody` is the kascommissie.

No second schema, no `KascommissieVerklaring`.

The five collapsed schemas become five seeded `AgendaItemType` objects:

| Retired schema | Seeded `AgendaItemType` | `owningBody` | `votable` |
|---|---|---|---|
| `MondelingeVraag` | Oral question | the asking faction | false |
| `Interpellatieverzoek` | Interpellation | the requesting faction | true (leave to hold) |
| `IngekomenStuk` | Incoming document | the clerk's office | false |
| `Raadsinformatiebrief` | Information letter | the executive | false |
| `KascommissieVerklaring` | Audit-committee report | the audit committee | true (discharge) |

Their distinctive fields (`questionNumber`, `portfolioHolder`,
`steunbetuigingen`, …) move into the type's `fields` fragment, which is how the
type stays configurable without every tenant inheriting Dutch council
vocabulary.

### `DecisionType`

`DecisionTemplate` is promoted, not replaced — it already carries
`stateMachine`, `votingRule`, `quorumRule`, `voteThreshold` and `checklist`. It
gains the two things that make it a *type* rather than a *template*:

```jsonc
{
  "competentBody": "<uuid>",        // WHICH GREMIUM MAY TAKE IT  ← new
  "competentPositionTypes": ["<uuid>"],  // or: only the president may  ← new
  "…": "existing template configuration"
}
```

…and `Decision` gains `type` — a **reference**, so that changing a type's voting
rule affects every future decision of that type, which a copy-once template can
never do.

> **A template is copied at creation. A type is consulted at every decision.**
> That difference is the whole of item 7.

### `PositionType` — configuration *of* the gremium, as its own schema

A position is not a free-floating object; a gremium **declares** which positions
exist on it. dossiq expresses exactly this shape — a type's configuration lives
in **child schemas that carry a back-reference FK to the type**
(`statusType.caseType`, `resultType.caseType`, `roleType.caseType`,
`propertyDefinition.caseType`), never inlined as an array on the type. decidiq
mirrors it:

```jsonc
{
  "slug": "position-type",
  "name": "President",
  "governanceBody": { "$ref": "governance-body" },  // ← the back-FK, dossiq's statusType.caseType
  "key": "president",
  "seats": 1,                       // a board has ONE president
  "order": 1,
  "allowedHoldTypes": ["regular", "interim", "elect", "candidate", "acting"],
  "termDurationMonths": 48,         // ← absorbs TermijnRegeling
  "maxConsecutiveTerms": 2,
  "reappointable": true,
  "votingWeight": 1.0,
  "grantsCompetenceFor": ["<decisionType uuid>"],
  "isRepresentationSeat": false     // true when created by a composition
}
```

Making it a schema rather than an inline array is what lets `PositionHold`
reference a position **by uuid** instead of by a loose string key, which is the
same reason dossiq's `statusRecord` references a `statusType` object rather than
naming a status in a string. An inline array cannot be pointed at.

`allowedHoldTypes` is per position, because Ruben's rule is that the hold types
"are themselves defined by the position's configuration" — a president may have
an *interim*, a treasurer may not.

`TermijnRegeling` (body + role + termDurationMonths + maxConsecutivePeriods) is
absorbed here wholesale. It was a separate object only because there was nowhere
on the body to put it.

### `PositionHold` — the durational, typed hold

```jsonc
{
  "slug": "position-hold",
  "membership": "<uuid>",     // WHO — via their membership, not directly the person
  "position": "<uuid>",       // ← FK to PositionType, x-relation-filter scoped
                              //   to the membership's own governanceBody
  "holdType": "interim",      // ← from that position's allowedHoldTypes
  "startDate": "2026-01-01",
  "endDate": "2026-06-30",    // ← THE SECOND END DATE
  "termNumber": 1,
  "reappointable": true,
  "appointedBy": "<decision uuid>"
}
```

Three consequences fall straight out:

1. **Successive holds are normal.** Two `PositionHold` objects on the same
   `position` with non-overlapping date ranges. There will always be a next
   president, and the previous one stays in the record.
2. **A person carries two independent end dates.** `Membership.endDate` says
   *until when they are a member*; `PositionHold.endDate` says *until when they
   hold the position*. Council member until A, faction leader until B — exactly
   Ruben's case, and inexpressible today.
3. **The hold points at a membership, not a person.** You cannot be president of
   a board you do not sit on. The referential integrity is free.

`Post` is superseded. Its `label`/`role` become the `PositionType` on the body;
its `startDate`/`endDate` — which wrongly meant *the holder's* dates — become the
`PositionHold`'s.

### `GovernanceBodyComposition`

```jsonc
{
  "slug": "governance-body-composition",
  "composite": "<uuid>",        // the Pub quiz
  "component": "<uuid>",        // the Management team
  "compositionType": "direct" | "representation",
  "seats": 2,                   // representation only
  "seatPosition": "<uuid>",     // representation only — THE SEAT IS A POSITION
  "votingWeight": 1.0,
  "accessionDate": "2026-01-01",
  "exitDate": null
}
```

- `direct` — the component's members *are* members of the composite. The Pub
  quiz is the Management team plus the Development team, same people.
- `representation` — the component sends `seats` representatives, and
  **`seatPosition` references a `PositionType` on the composite**. So the delegate's
  tenure is an ordinary `PositionHold` with a duration and a hold type; a
  delegate can be *interim* like any other office-holder. That is item 5's
  "the representation is itself a position", made literal.

`BodyParticipation` (from `shared-governance-bodies`) is the same idea, half
built — it has `sharedBody`/`participant`/`seats` but no `seatPosition`, so its
seats are anonymous. It folds into this schema.

## D3. What is *deleted*, and why that is the point

### `RoosterVanAftreden` and `RoosterRegel` are not entities

`RoosterVanAftreden` carries `generatedOn` and `generatedBy`. That is the
signature of a **materialised query result**, not a domain object. `RoosterRegel`
then re-stores `personName`, `role` and `endTermDate` — three fields whose
authority lives on `Membership` and (now) `PositionHold`. It is a cache with no
invalidation path: rename a person and the rooster keeps the old name forever.

A retirement schedule is:

```
SELECT the membership and position-hold end dates
FROM   one body (and, transitively, its composed bodies)
WHERE  endDate falls in the requested window
ORDER BY endDate
```

Both sources are unioned, which is precisely why the old model could not do it:
`RoosterRegel` has one `endTermDate` column and a person needs two.

It becomes a **derived view** — a filtered index over memberships and holds, and
a PDF export that renders that view. Nothing is stored. `publicationDate` /
`depublicationDate`, the only two fields on `RoosterVanAftreden` that were not
derived, move onto the export artifact where they belong.

### Factions and bodies: one concept, confirmed by measurement

Not a judgement call — checked against the register:

- `GovernanceBody.bodyType` already contains `"faction"`.
- The two seeded factions are `GovernanceBody` objects with `parentBody` set.
- There is **no** `Fractie` schema anywhere in the 95 declarations.

So decidiq does *not* have two concepts; it has one concept and a **misleading
nav label** ("Factions & bodies") that implies two. The fix is the label, plus
one prohibition: the unlanded `fractievoorzitter-fractie-koppeling` change
(47 open tasks, 0 done) plans a first-class `Fractie` schema, and four shipped
fragments already carry forward-references to it. **That schema is cancelled.**
A faction is a `GovernanceBody` with `bodyType: faction`; a faction leader is a
`PositionHold` on a `PositionType` named *faction leader* — which is exactly what that
change wanted, obtained for free from this model.

## D4. Composition vs. hierarchy — two different relations, kept apart

`GovernanceBody.parentBody` already exists and is used for factions under a
council. It is a **hierarchy**: "this body sits under that one". Composition is
different: "this body is *made of* those ones". A gremium can have both — the
Pub quiz has no parent, but is composed of two teams.

Keeping them separate avoids the trap where `parentBody` silently becomes an
overloaded field meaning three things depending on `bodyType`.

## D5. Competence is enforced, not merely declared

decidiq's authorisation history is specific and documented: `isTransitionAllowed()`,
`requiresChairAuthorization()` and `validateQuorum()` all shipped implemented and
**never called** (decidesk#60); four `MinutesController` methods shipped
`#[NoAdminRequired]` with no per-object guard (decidesk#44); and
`DecisionApprovalService::getAuthorizationService()` returned `null` on
`\Throwable` while its caller guarded with `if ($auth !== null)`, so a briefly
unavailable service meant *no check at all* (decidesk#45).

`DecisionType.competentBody` is a field that says who may decide. Shipping it
without a caller would repeat all three defects at once. So:

1. The check lives in **one** place, `DecisionCompetenceGuard::assertCompetent()`,
   called from the decision write path *before* persistence.
2. It **throws**; it does not return `null` or `false`. There is no shape in
   which "could not determine competence" reads as "competent".
3. Its test asserts the *call*, not just the method: deleting the call from the
   write path must turn a test red. A test that only exercises the guard
   directly would pass with the guard orphaned — the exact decidesk#60 failure.
4. A decision whose type has no `competentBody` is **not** implicitly permitted;
   it falls back to the body on the meeting, and if there is none, it is refused.

## D6. Schema-count arithmetic (item 8's "aim at schema reduction")

| | schemas |
|---|---|
| Today | **95** declared across 27 files |
| + this change adds | `MeetingType`, `AgendaItemType`, `PositionHold`, `GovernanceBodyComposition` → **+4** |
| − collapsed into `AgendaItem` + seeded types | `MondelingeVraag`, `Interpellatieverzoek`, `IngekomenStuk`, `Raadsinformatiebrief`, `KascommissieVerklaring` → **−5** |
| − retired as derived | `RoosterVanAftreden`, `RoosterRegel`, `TermijnRegeling` → **−3** |
| − superseded | `Post`, `BodyParticipation`, `VragenuurConfiguratie` → **−3** |
| − moved to humaniq | `OnboardingTraject`, `OffboardingTraject` → **−2** |
| − promoted, not added | `DecisionTemplate` → `DecisionType` (rename) → **0** |
| **After** | **86** |

Net −9 in this change, and — the part that matters more than the number — the
*rate* changes: the next domain that needs a new meeting kind, agenda-item kind
or decision kind costs **zero** schemas instead of one. Nineteen further
concrete schemas are staged as follow-ups against the same pattern.

## D7. Migration — a declared schema with no writer is not a migration target

Three failure modes are specifically guarded, because each has burned this
fleet before:

1. **The importer skips a register whose version is not higher.** `info.version`
   is bumped. Verified with `occ openregister:descriptors:list` showing
   `installed == shipped` at the new number — *and* an object count, because a
   descriptor landing proves the schema landed, not that data moved.
2. **A property added to an existing schema has no physical column until
   something writes.** `occ openregister:tables:reconcile` is run and the column
   asserted present before seeding.
3. **The backfill must be counted, not assumed.** The migration reports
   `n meetings backfilled with a type reference`; a run that reports `0` is a
   failure, not a no-op — because 0 is exactly what a broken slug resolver
   returns.

Deprecated enums are read-through for one release: `Meeting.type` is preferred,
`Meeting.meetingType` is the fallback, and a meeting with neither is invalid.

## D8. UI decisions

- **Calendar view on the meeting index.** `CnIndexPage`'s `viewMode` validator
  accepts `table | cards | list | map` only — there is no `calendar`, and the
  shared library is owned by another agent this wave. So the meeting index
  becomes a decidiq-local page that renders a local toggle between the existing
  `CnIndexPage` table and a local `MeetingCalendarView`. No shared-library edit,
  no fork of the index page.
- **The dashboard widget order.** Measured, not guessed: the four KPI widgets
  sit at `gridY 0`, but `minutes-in-review` (a `stats-block`) sits at `gridY 14`
  and `open-toezeggingen-overdue` (a `stat`) at `gridY 18` — two KPI-shaped
  widgets stranded below three list widgets. They move up into the KPI band.
- **The `RunningProcessesWidget` margin is a defect *class*, not one widget.**
  Measured in the browser: shared-library widgets inset their content by ~17px
  from the card's content box; **every decidiq-local dashboard widget insets by
  0** — `dashboard-list-widget`, `running-processes` and `governance-health`
  alike. Running processes is merely where it *shows*, because it is the only
  one whose first child is a left-aligned heading rather than a centred empty
  state. Fixing only that widget would leave the other two to resurface the
  moment they have content. One shared local rule is applied to all three.

## D9. The OpenRegister dialect, copied from dossiq rather than invented

dossiq already solves "an instance derives its behaviour from its type" in this
exact stack. The mechanics are copied verbatim so decidiq does not grow a second
dialect for the same job.

**A type reference is a uuid string with `$ref` set to the target schema's
slug** — `$ref` sits *beside* `type`/`format`, and is a slug, not a
JSON-Pointer:

```json
"type": { "type": "string", "format": "uuid", "$ref": "meeting-type",
          "title": "Meeting type", "facetable": true }
```

**A dependent picker is narrowed with `x-relation-filter`**, so a hold can only
choose a position that exists on its own body — the same way a dossiq case can
only choose a status belonging to its own case type:

```json
"position": { "type": "string", "format": "uuid", "$ref": "position-type",
              "x-relation-filter": { "governanceBody": "@object.governanceBody" } }
```

**The instance reads its type's configuration declaratively**, with no PHP, via
the instance schema's `configuration` block:

```jsonc
"x-openregister-references": {
  "meetingType":  { "schema": "meeting-type",  "mode": "relatedObject", "field": "type" },
  "decisionType": { "schema": "decision-type", "mode": "relatedObject", "field": "type" }
},
"x-openregister-calculations": {
  "quorumRequired": { "type": "integer", "materialise": true,
    "expression": { "prop": "@ref.meetingType.defaultQuorum" } }
},
"x-openregister-lifecycle": {
  "field":   "lifecycle",
  "initial": { "from": "type", "field": "initialLifecycle" }   // ← seeded FROM the type
}
```

That last block is the one that earns its keep: a meeting's opening state comes
from its meeting type, declaratively, exactly as a dossiq case's opening status
comes from `caseType.initialStatus`. No backfill service, no imperative default.

**`materialise: true` matters for anything filtered server-side.** A calculation
that is not materialised is resolved only at read time via
`?_extend=calculations`, so a list filtered on it silently returns nothing —
which is the "filter on a non-existent property returns 0 rows, HTTP 200" trap.
After editing a type's configuration, `occ openregister:rematerialise-calculations`
must be run, or every existing instance keeps the old derived value.

**The type gets a publish gate**, mirroring `caseType`: `isDraft`, `validFrom`,
`validUntil`, and a `validatePublish()` that refuses to publish a `MeetingType`
with no allowed agenda-item types — the analogue of dossiq refusing a `caseType`
with no final status.

**The frontend gets the type for free.** dossiq's meeting-list equivalent is
solved with a `folderSidebar` sourced from the type register, and that is
precisely the cure for decidiq's flat, undifferentiated meeting list:

```json
"folderSidebar": { "source": "register", "register": "decidiq",
                   "schema": "meeting-type", "idField": "@self.id",
                   "nameField": "name", "filterField": "type",
                   "allLabel": "All meetings" }
```

plus a `formatter: "meetingTypeName"` column resolving the uuid to a label
through the same lazy `lookupRelatedName` idiom.
