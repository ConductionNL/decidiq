# Spec delta: Decision Management — typed relations, derived in-force status, in-force filter

This file contains delta specifications for the `decision-detail-fullpicture` change against the existing `decision-management` capability. It adds cross-decision modification relations, a derived effective (in-force) status layered over the lifecycle, and an in-force list filter. The lifecycle state machine is unchanged. This delta SUBSUMES the relation requirements of the active `decision-relations` change.

---

## ADDED Requirements

### Requirement: Typed decision-to-decision modification relations

The `Decision` schema SHALL support typed modification relations to other decisions, stored as OpenRegister object relations on the source decision: `supersedes` and `repeals` (effect-bearing) and `implements` and `refersTo` (informational). The existing `amends` relation (decisionType=amendment → its parent motion) SHALL retain its current meaning and SHALL be widened in description to cover "this decision modifies that decision"; a second `amends` relation SHALL NOT be introduced. Inverse views (superseded-by, repealed-by, implemented-by, referenced-by) SHALL be derived from OpenRegister relation queries and SHALL NOT be stored separately. Creating or removing an effect-bearing relation SHALL require the same governance-body authority as decision state transitions; informational relations SHALL require decision write access. Every relation addition and removal SHALL be recorded in the immutable audit trail of both the source and the target decision. The relation types SHALL map to the cited standards: Akoma Ntoso active/passive modifications, OpenRaadsinformatie `Besluit` relations, and schema.org `replacer`/`replacee` for supersession.

#### Scenario: Declare that a decision supersedes another

- **GIVEN** a staff member with governance-body authority editing decision "Programmabegroting 2027"
- **WHEN** they add a `supersedes` relation to the enacted decision "Programmabegroting 2026"
- **THEN** the relation is stored on "Programmabegroting 2027", and both decisions' audit trails record the relation with actor and timestamp

#### Scenario: Inverse relation is derived

@e2e exclude derived-query contract — covered by PHPUnit/Newman on the relation query; the UI scenario lives in the relations tab
- **GIVEN** decision A with a stored `supersedes` relation to decision B
- **WHEN** decision B's incoming relations are queried
- **THEN** B reports "superseded by A" derived from the OpenRegister relation query, and no inverse relation is stored on B

#### Scenario: Effect-bearing relation requires authority

@e2e exclude API authorization contract — covered by Newman, not a UI flow
- **WHEN** an authenticated user without governance-body authority attempts to add a `repeals` relation
- **THEN** the request is rejected with HTTP 403 and no relation or audit entry is created

---

### Requirement: Relation integrity validation

The system SHALL validate relations at write time: self-references SHALL be rejected for all relation types; cycles in the effect-bearing subgraph (`supersedes`/`repeals`) SHALL be rejected via a bounded graph walk with a clear error naming the conflicting decision; relation targets MUST be decisions in the same decidesk register. Effect-bearing relations MAY be declared at any source status but SHALL exert effect only while the source decision is in status `decided` or `enacted`. Validation SHALL be expressed declaratively where OpenRegister supports it (relation constraints), otherwise via a thin server-side validation seam — relation CRUD itself SHALL remain on the OpenRegister object API (no pass-through controller, per ADR-022).

#### Scenario: Self-reference rejected

- **WHEN** a user attempts to add any relation from a decision to itself
- **THEN** the relation is rejected with a validation error and nothing is stored

#### Scenario: Cycle rejected

@e2e exclude graph-validation contract — covered by PHPUnit on the relation validation
- **GIVEN** decision A supersedes decision B
- **WHEN** a user attempts to add a `supersedes` relation from B to A
- **THEN** the relation is rejected with an error naming the existing A→B relation and nothing is stored

#### Scenario: Draft relation exerts no effect yet

- **GIVEN** a decision in status `draft` carrying a `repeals` relation to an enacted decision
- **WHEN** the target decision is displayed
- **THEN** the target still presents as in force, and only when the source reaches `decided`/`enacted` does the target's effective status become `repealed`

---

### Requirement: Derived effective status

The system SHALL compute an `effectiveStatus` for every decision, derived at read time from inbound effect-bearing relations and never stored as a lifecycle state: `repealed` when a decided/enacted decision `repeals` it, else `superseded` when a decided/enacted decision `supersedes` it, else the decision's lifecycle status. The lifecycle status and its audit trail SHALL remain unchanged by relations. The derivation SHALL be expressed declaratively as an OpenRegister calculation where the inverse-relation lookup is expressible; otherwise the detail view SHALL compute it client-side from the same incoming-relation query the relations tab uses. The precedence (`repealed` > `superseded` > lifecycle) SHALL hold regardless of mechanism. A declarative ADR-031 notification rule SHALL notify the governance body when a decision becomes superseded or repealed.

#### Scenario: Enacted supersession flips effective status

- **GIVEN** enacted decision "Programmabegroting 2026" and decision "Programmabegroting 2027" carrying `supersedes` → "Programmabegroting 2026"
- **WHEN** "Programmabegroting 2027" is enacted
- **THEN** "Programmabegroting 2026" presents effectiveStatus `superseded` while its lifecycle status remains `enacted` and its audit trail is unchanged

#### Scenario: Repeal outranks supersession

@e2e exclude precedence rule — covered by PHPUnit on the derivation
- **GIVEN** a decision targeted by both an enacted `supersedes` and an enacted `repeals` relation
- **WHEN** its effectiveStatus is computed
- **THEN** the result is `repealed`

#### Scenario: Body notified on repeal

@e2e exclude declarative notification dialect — verified by the notification-dialect gate plus PHPUnit on the rule import
- **WHEN** a decision's effectiveStatus becomes `repealed`
- **THEN** recipients defined in the ADR-031 rule receive an NC notification naming the repealing decision

---

### Requirement: In-force visibility in list and detail views

The decision list SHALL offer an in-force filter exposing the values `in force`, `superseded`, and `repealed`, computed from the derived `effectiveStatus`. The decision detail view SHALL display a prominent banner when `effectiveStatus` differs from the lifecycle status, naming the effecting decision with navigation to it. The lifecycle status badge SHALL always remain visible alongside the effective status.

#### Scenario: Filter the register to decisions in force

- **GIVEN** a register containing enacted, superseded, and repealed decisions
- **WHEN** the user filters the decision list by `in force`
- **THEN** superseded and repealed decisions are excluded from the results and the result count reflects only decisions in force

#### Scenario: Superseded banner with chain navigation

- **GIVEN** a decision whose effectiveStatus is `superseded` by "Programmabegroting 2027"
- **WHEN** the user opens its detail view
- **THEN** a banner states it is superseded by "Programmabegroting 2027" with its date, activating the banner navigates to "Programmabegroting 2027", and the original lifecycle badge remains visible
