# configurable-types-domain-model Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [configurable-types-domain-model](../../changes/configurable-types-domain-model/)

## Purpose

Replace decidiq's hardcoded subtype enums with **configurable type objects** that
instances reference, so an organisation can define its own meeting kinds, agenda
item kinds, decision kinds and positions without a developer adding a schema.
Retire the entities that only existed because an enum could not stretch, and
retire the retirement schedule, which was never an entity at all.

**Standards**: Schema.org `Event` / `Organization` / `Role` / `VoteAction`,
Popolo (`Post`, `Membership`), ADR-005, ADR-006, and ADR-007 (proposed here).
**ORI note**: OpenRaadsinformatie models meeting kinds as a free `classification`
string, which a referenced type object satisfies strictly better than a closed
enum.

## ADDED Requirements

### Requirement: REQ-CTM-001 A gremium defines its own meeting types

The system SHALL provide a `MeetingType` schema whose objects are owned by a
`GovernanceBody` through a `governanceBody` reference, carrying that body's
meeting-kind configuration: which agenda-item types are admitted, the default
quorum rule, the default voting rule, the default duration and the initial
lifecycle state.

`Meeting` SHALL gain a `type` property referencing a `MeetingType`. The existing
`Meeting.meetingType` enum SHALL be retained and marked deprecated for one
release so that meetings created before this change keep rendering.

A `MeetingType` SHALL be creatable by an administrator at runtime. No code change
SHALL be required to add a meeting kind.

#### Scenario: A gremium defines a meeting kind that no enum contains

- GIVEN a `GovernanceBody` "Pub quiz"
- WHEN an administrator creates a `MeetingType` "Pub quiz night" on that body
- THEN a `Meeting` can be created referencing it
- AND no value was added to any enum, and no schema was added

#### Scenario: A meeting type belongs to exactly one body

- GIVEN a `MeetingType` whose `governanceBody` is the Management team
- WHEN the meeting-type picker is opened on a Development team meeting
- THEN the Management team's meeting type is not offered

#### Scenario: An existing meeting without a type still renders

- GIVEN a `Meeting` seeded before this change, carrying only `meetingType: "regular"`
- WHEN the meeting index is rendered
- THEN the meeting appears, labelled from the deprecated enum
- AND a meeting carrying NEITHER `type` nor `meetingType` is invalid

### Requirement: REQ-CTM-002 Agenda items are typed, and an item's owner is not the meeting's body

The system SHALL provide an `AgendaItemType` schema carrying `name`,
`owningBody` (optional), `votable`, an optional `decisionType` reference, a
lifecycle, and a `fields` fragment for type-specific properties.

`AgendaItem` SHALL gain a `type` property referencing an `AgendaItemType`.

`AgendaItemType.owningBody` SHALL be independent of the body holding the meeting
the item appears on. A body SHALL therefore be able to own an agenda item on
another body's meeting without holding that meeting.

#### Scenario: The kascommissie, expressed twice by one model

- GIVEN a `GovernanceBody` "Kascommissie" and a `GovernanceBody` "ALV"
- WHEN the kascommissie holds its own meeting
- THEN that `Meeting`'s type has `governanceBody` = Kascommissie
- AND WHEN the kascommissie reports to the ALV
- THEN an `AgendaItem` on the ALV's meeting carries a type whose `owningBody` is
  Kascommissie
- AND no `KascommissieVerklaring` schema is involved in either case

#### Scenario: An oral question is an agenda item, not a meeting

- GIVEN a seeded `AgendaItemType` "Oral question"
- WHEN a member submits an oral question
- THEN an `AgendaItem` of that type is created on the target meeting
- AND "Oral questions" is NOT a top-level navigation entry
- AND the deep-link route for oral questions still resolves, so no existing link
  breaks

### Requirement: REQ-CTM-003 A decision type names the gremium competent to take it

The system SHALL promote `DecisionTemplate` to `DecisionType` by adding
`competentBody` (a `GovernanceBody` reference) and `competentPositionTypes` (an
array of `PositionType` references).

`Decision` SHALL gain a `type` property **referencing** a `DecisionType`. The
reference SHALL be resolved at decision time, not copied at creation, so that a
change to the type's voting rule governs every subsequent decision of that type.

#### Scenario: The type is consulted, not copied

- GIVEN a `DecisionType` with `voteThreshold: simple-majority`
- AND a `Decision` referencing it, still in `draft`
- WHEN an administrator changes the type's threshold to `qualified-majority-two-thirds`
- THEN the draft decision is subsequently decided under the two-thirds threshold
- AND a copy-once template would have kept the simple majority, which is the
  defect this replaces

### Requirement: REQ-CTM-004 Competence is enforced at the write path, not merely declared

The system SHALL enforce `DecisionType.competentBody` in a single guard,
`DecisionCompetenceGuard::assertCompetent()`, invoked from the decision write
path before persistence.

The guard SHALL throw on refusal. It SHALL NOT return `null`, `false` or any
value a caller could treat as "check skipped". A `catch (\Throwable) { return
null; }` resolver in this path is forbidden.

A `Decision` whose type declares no `competentBody` SHALL fall back to the
governance body of the meeting the decision belongs to, and SHALL be refused if
there is none. Absence of a declared competence SHALL NOT read as universal
competence.

#### Scenario: An incompetent body cannot take the decision

- GIVEN a `DecisionType` whose `competentBody` is the Management team
- WHEN a user attempts to record a decision of that type on a Development team meeting
- THEN the write is refused
- AND the refusal happens before persistence, so no partial object exists

#### Scenario: Removing the call turns a test red

- GIVEN the competence guard and its caller in the decision write path
- WHEN the call to `assertCompetent()` is deleted from the write path
- THEN at least one test fails
- AND a test suite that only exercised the guard directly would still pass,
  which is why that suite alone is insufficient (decidesk#60)

#### Scenario: An unavailable authorization service refuses, it does not permit

- GIVEN the competence guard's dependency throws
- WHEN a decision write is attempted
- THEN the write is refused
- AND the exception is not swallowed into a nullable return

### Requirement: REQ-CTM-005 Positions belong to a gremium's configuration

The system SHALL provide a `PositionType` schema carrying a `governanceBody`
back-reference, a `name`, `seats`, `order`, `allowedHoldTypes`,
`termDurationMonths`, `maxConsecutiveTerms`, `reappointable` and `votingWeight`.

`allowedHoldTypes` SHALL be configured per position, so that the set of valid
hold types is defined by the position, not globally.

`TermijnRegeling` SHALL be retired; its `termDurationMonths` and
`maxConsecutivePeriods` are absorbed by `PositionType`.

#### Scenario: A board declares that it has a president

- GIVEN a `GovernanceBody` of `bodyType: executive-board`
- WHEN an administrator adds a `PositionType` "President" with `seats: 1`
- THEN the position exists on that body only
- AND a second body has no president unless it declares one

#### Scenario: Hold types are per position

- GIVEN a `PositionType` "President" with `allowedHoldTypes: [regular, interim]`
- AND a `PositionType` "Treasurer" with `allowedHoldTypes: [regular]`
- WHEN a hold of type `interim` is created on the treasurer position
- THEN it is refused

### Requirement: REQ-CTM-006 A membership may hold a position, for a duration, with a type

The system SHALL provide a `PositionHold` schema linking a `Membership` to a
`PositionType` with `holdType`, `startDate`, `endDate`, `termNumber` and an
optional `appointedBy` decision reference.

`PositionHold.position` SHALL be constrained to positions on the same
`GovernanceBody` as the referenced membership.

Multiple successive `PositionHold` objects on one position SHALL be valid. The
system SHALL NOT treat a new hold as replacing the record of the previous one.

`Post` SHALL be superseded: its `label`/`role` become a `PositionType`, and its
`startDate`/`endDate` — which incorrectly described the holder rather than the
position — become the `PositionHold`'s.

#### Scenario: There is always a next president

- GIVEN a `PositionHold` on "President" ending 2026-06-30
- WHEN a second hold on "President" is created starting 2026-07-01
- THEN both holds exist
- AND the earlier hold is still readable as history

#### Scenario: You cannot hold a position on a body you do not sit on

- GIVEN a `Membership` on the Management team
- WHEN a `PositionHold` is created referencing a position on the Development team
- THEN it is refused

### Requirement: REQ-CTM-007 One person carries two independent end terms

Both `Membership` and `PositionHold` SHALL carry an `endDate`, and the two SHALL
be independent.

#### Scenario: Council member until A, faction leader until B

- GIVEN a `Membership` on the council with `endDate` 2030-03-01
- AND a `PositionHold` on the faction-leader position with `endDate` 2027-09-01
- WHEN the person's terms are read
- THEN both dates are returned, distinctly
- AND neither overwrites the other

### Requirement: REQ-CTM-008 The retirement schedule is derived, never stored

The system SHALL retire `RoosterVanAftreden` and `RoosterRegel` as schemas.

A retirement schedule SHALL be computed as a query over `Membership.endDate` and
`PositionHold.endDate` for a body, unioned, filtered to a window and ordered by
date.

No object SHALL store a materialised copy of a retirement schedule. Publication
dates SHALL live on the exported artifact, not on a stored schedule object.

#### Scenario: Renaming a person updates the schedule

- GIVEN a retirement schedule rendered for a body
- WHEN the person's name is changed on the `Person` object
- THEN the schedule shows the new name on the next render
- AND under the retired `RoosterRegel.personName` it would have shown the old
  name indefinitely, which is the defect this removes

#### Scenario: Both end dates appear as separate rows

- GIVEN a person with a membership end and a differing position-hold end
- WHEN the schedule is generated
- THEN two rows appear, one per end date, each naming what is ending

### Requirement: REQ-CTM-009 A gremium may be composed of other gremia

The system SHALL provide a `GovernanceBodyComposition` schema with `composite`,
`component`, `compositionType` (`direct` | `representation`), `seats`,
`seatPosition`, `votingWeight`, `accessionDate` and `exitDate`.

For `compositionType: direct`, the component's active members SHALL be resolved
as members of the composite.

For `compositionType: representation`, `seatPosition` SHALL reference a
`PositionType` on the composite, so that a representative's tenure is an ordinary
`PositionHold` carrying a duration and a hold type.

Composition SHALL be distinct from `GovernanceBody.parentBody`, which expresses
hierarchy. A body MAY have both.

#### Scenario: Pub quiz is the two teams

- GIVEN gremia "Management team" and "Development team"
- AND a gremium "Pub quiz"
- WHEN two `direct` compositions are created making Pub quiz composed of both
- THEN the members of both teams resolve as participants of a Pub quiz meeting

#### Scenario: A representation seat is a position

- GIVEN a `representation` composition with `seats: 2`
- THEN `seatPosition` names a `PositionType` on the composite
- AND the delegate holding it does so through a `PositionHold`
- AND that delegate can be `interim` like any other office-holder

### Requirement: REQ-CTM-010 Factions and bodies are one concept

The system SHALL continue to model a faction as a `GovernanceBody` with
`bodyType: faction`.

The system SHALL NOT introduce a `Fractie` schema. The unimplemented
`fractievoorzitter-fractie-koppeling` proposal for one is cancelled; a faction
leader is a `PositionHold` on a faction-leader `PositionType`.

The navigation label "Factions & bodies" SHALL be replaced by a single label, so
the UI stops implying two concepts where the data has one.

#### Scenario: A faction leader needs no new schema

- GIVEN a `GovernanceBody` with `bodyType: faction`
- WHEN a faction leader is recorded
- THEN a `PositionHold` on that body's faction-leader position expresses it
- AND no `Fractie` or `FractieLidmaatschap` schema exists

## MODIFIED Requirements

### Requirement: REQ-CTM-011 Five concrete schemas collapse into typed agenda items

`MondelingeVraag`, `Interpellatieverzoek`, `IngekomenStuk`,
`Raadsinformatiebrief` and `KascommissieVerklaring` SHALL be expressed as
`AgendaItem` objects carrying a seeded `AgendaItemType`.

Their type-specific properties SHALL move into the `AgendaItemType.fields`
fragment so that no tenant inherits another tenant's vocabulary.

Their existing routes SHALL remain resolvable. Only the top-level **menu leaf**
is removed; removing a route that a deep link or e2e spec depends on is
functionality loss (gate-53 / ADR-044).

#### Scenario: The nav shrinks but no link breaks

- GIVEN a bookmark to the oral-questions index
- WHEN the menu leaf is removed
- THEN the bookmark still resolves
- AND the surface renders as agenda items filtered by type

### Requirement: REQ-CTM-012 A schema change moves data, or it is not done

Every schema addition in this change SHALL be accompanied by evidence that data
moved, not merely that a descriptor landed.

The register `info.version` SHALL be bumped; the importer skips a version that is
not higher, so an unbumped version means nothing lands.

`occ openregister:tables:reconcile` SHALL be run for schemas that gain
properties, because a property added to an existing schema has no physical
column until something writes it.

Backfill SHALL report a **count**. A run reporting `0` SHALL be treated as a
failure, since `0` is also what a broken slug resolver returns.

#### Scenario: The descriptor landing is not the evidence

- GIVEN the register descriptor reports `installed == shipped`
- WHEN no object carries the new `type` reference
- THEN the migration is incomplete
- AND the schema count alone would have wrongly reported success

## Non-Goals

- Implementing anything in `humaniq`. The onboarding/offboarding/term move is
  handed off as a proposal for that app's own backlog.
- Editing `@conduction/nextcloud-vue`. The meeting calendar is a decidiq-local
  view because `CnIndexPage` has no `calendar` view mode and the shared library
  is owned elsewhere this wave.
- Migrating the remaining 19 concrete schemas. The pattern is established here;
  the rest follow so one PR stays reviewable.
- Deleting the deprecated `Meeting.meetingType` and `Decision.decisionType`
  enums, which stay readable for one release.
