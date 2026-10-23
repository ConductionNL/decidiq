## ADDED Requirements

### Requirement: REQ-PART-101 Participation endpoints record the session identity, never a request-supplied one
Citizen-participation intake — reaction submission, budget-proposal submission and advisory
voting — SHALL remain available to EVERY authenticated Nextcloud account; the system SHALL NOT
narrow these endpoints to a governance body, a group or a member list. The system MUST derive
the actor identity written onto the resulting object (`ConsultationReaction.submitterId`,
`BudgetProposal.submitter`, `CitizenVote.voterId`) from the session, in the routed controller
method, and MUST NOT accept a submitter, author or voter identity from the request. An absent
session MUST be refused with `401`.

#### Scenario: Authenticated citizen submits under their own identity

- **GIVEN** an authenticated user `alice` and an open consultation
- **WHEN** they `POST /api/participation/consultations/{id}/reactions`
- **THEN** the created `ConsultationReaction` carries `submitterId = alice`

#### Scenario: A request-supplied identity is ignored

- **GIVEN** an authenticated user `alice`
- **WHEN** they submit a proposal, a reaction or an advisory vote whose request body also
  carries a `submitter` / `submitterId` / `voterId` field naming `bob`
- **THEN** the stored object records `alice`, taken from the session

#### Scenario: Anonymous caller refused

- **GIVEN** no signed-in session
- **WHEN** any of the three intake endpoints is called
- **THEN** the request is refused with `401` and no object is created

---

### Requirement: REQ-PART-102 The advisory voting window guard fails closed
The system MUST refuse an advisory vote whenever the proposal's `ParticipatoryBudget` round
cannot be established — the proposal references no round, or the referenced round no longer
exists — with the same static voting-closed message used for a round that is past its
`votingDeadline` or not in the `voting` phase. An unresolvable round MUST NOT be treated as an
open round.

#### Scenario: Vote on a proposal whose round reference is missing

- **GIVEN** a `validated` `BudgetProposal` with no resolvable `participatoryBudget` reference
- **WHEN** an authenticated citizen casts an advisory vote on it
- **THEN** the vote is refused with the static voting-closed message and no `CitizenVote` is created

#### Scenario: Vote on a proposal whose round row has been deleted

- **GIVEN** a `validated` `BudgetProposal` referencing a round UUID that no longer resolves
- **WHEN** an authenticated citizen casts an advisory vote on it
- **THEN** the vote is refused with the static voting-closed message

#### Scenario: Vote in an open round still succeeds

- **GIVEN** a `validated` `BudgetProposal` in a round with `status: "voting"` and a future
  `votingDeadline`
- **WHEN** an authenticated citizen casts a `voor` vote
- **THEN** the vote is recorded and the tally is returned

---

### Requirement: REQ-ENG-101 Engagement records are listed only within the caller's authority
`GET /api/engagement?meeting={uuid}` MUST scope its result to the caller's authority over the
named meeting. A Nextcloud admin, or a chair or secretary of the meeting, SHALL receive every
`EngagementRecord` for that meeting (the minutes-review surface of REQ-PE-003). Any other
authenticated caller SHALL receive only the records belonging to their own linked
`Participant`. A caller with no linked `Participant` SHALL receive an empty list rather than
another participant's data or an error that discloses one.

#### Scenario: Chair lists the whole meeting

- **GIVEN** an authenticated user holding the `chair` role on meeting `M`, which has
  engagement records for five participants
- **WHEN** they call `GET /api/engagement?meeting=M`
- **THEN** all five records are returned

#### Scenario: Plain participant sees only their own record

- **GIVEN** an authenticated user linked to participant `P`, with no chair/secretary role on
  meeting `M` and no admin rights, and `M` has records for `P` and for `Q`
- **WHEN** they call `GET /api/engagement?meeting=M`
- **THEN** only `P`'s record is returned and `Q`'s record is not disclosed

#### Scenario: Unrelated authenticated caller learns nothing

- **GIVEN** an authenticated user with no linked `Participant` record
- **WHEN** they call `GET /api/engagement?meeting=M` for a meeting with records
- **THEN** an empty record list is returned

#### Scenario: Anonymous caller refused

- **GIVEN** no signed-in session
- **WHEN** `GET /api/engagement?meeting=M` is called
- **THEN** the request is refused with `401`
