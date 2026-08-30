# Spec delta: Decision Management — typed decision relations and in-force semantics

This file contains delta specifications for the decision-relations change against the existing `decision-management` capability. It adds cross-decision relations and derived in-force semantics; the lifecycle state machine is unchanged.

---

## ADDED Requirements

### Requirement: Typed decision-to-decision relations

The `Decision` schema SHALL support typed relations to other decisions, stored as OpenRegister object relations on the source decision: `supersedes`, `amends`, `repeals` (effect-bearing) and `implements`, `refersTo` (informational). Inverse views (superseded-by, amended-by, repealed-by, implemented-by, referenced-by) SHALL be derived from OpenRegister relation queries and SHALL NOT be stored separately. Creating or removing an effect-bearing relation SHALL require the same governance-body authority as decision state transitions; informational relations SHALL require decision write access. Every relation addition and removal SHALL be recorded in the immutable audit trail of both the source and the target decision. The relation types SHALL map to the cited standards: Akoma Ntoso active/passive modifications, OpenRaadsinformatie `Besluit` relations, and schema.org `replacer`/`replacee` for supersession.

#### Scenario: Declare that a decision supersedes another

- **GIVEN** a staff member with governance-body authority editing decision "Budget 2027"
- **WHEN** they add a `supersedes` relation to the enacted decision "Budget 2026"
- **THEN** the relation is stored on "Budget 2027", and both decisions' audit trails record the relation with actor and timestamp

#### Scenario: Inverse relation is derived

@e2e exclude derived-query contract — covered by PHPUnit/Newman on the relation query, the UI scenario lives in the relations tab
- **GIVEN** decision A with a stored `supersedes` relation to decision B
- **WHEN** decision B's incoming relations are queried
- **THEN** B reports "superseded by A" derived from the OpenRegister relation query, and no inverse relation is stored on B

#### Scenario: Effect-bearing relation requires authority

@e2e exclude API authorization contract — covered by Newman, not a UI flow
- **WHEN** an authenticated user without governance-body authority attempts to add a `repeals` relation
- **THEN** the request is rejected with HTTP 403 and no relation or audit entry is created

---

### Requirement: Relation integrity validation

The system SHALL validate relations server-side at write time: self-references SHALL be rejected for all relation types; cycles in the effect-bearing subgraph (`supersedes`/`amends`/`repeals`) SHALL be rejected via a bounded graph walk with a clear error naming the conflicting decision; relation targets MUST be decisions in the same decidesk register. Effect-bearing relations MAY be declared at any source status but SHALL exert effect only while the source decision is in status `decided` or `enacted`.

#### Scenario: Self-reference rejected

- **WHEN** a user attempts to add any relation from a decision to itself
- **THEN** the relation is rejected with a validation error and nothing is stored

#### Scenario: Cycle rejected

@e2e exclude graph-validation contract — covered by PHPUnit on the relation service
- **GIVEN** decision A supersedes decision B
- **WHEN** a user attempts to add a `supersedes` relation from B to A
- **THEN** the relation is rejected with an error naming the existing A→B relation and nothing is stored

#### Scenario: Draft relation exerts no effect yet

- **GIVEN** a decision in status `draft` carrying a `repeals` relation to an enacted decision
- **WHEN** the target decision is displayed
- **THEN** the target still presents as in force, and only when the draft reaches `decided`/`enacted` does the target's effective status become `repealed`

---

### Requirement: Derived effective status

The system SHALL compute an effective status for every decision, derived at read time and never stored as a lifecycle state: `repealed` when an enacted/decided decision `repeals` it, else `superseded` when an enacted/decided decision `supersedes` it, else the decision's lifecycle status. The lifecycle status and its audit trail SHALL remain unchanged by relations. The computation SHALL live in a single server-side service reused by the detail view, the list filter, and the publication payload builder. A declarative ADR-031 notification rule SHALL notify the governance body when a decision becomes superseded or repealed.

#### Scenario: Enacted supersession flips effective status

- **GIVEN** enacted decision "Budget 2026" and decision "Budget 2027" carrying `supersedes` → "Budget 2026"
- **WHEN** "Budget 2027" transitions to `enacted`
- **THEN** "Budget 2026" presents effective status `superseded` while its lifecycle status remains `enacted` and its audit trail is unchanged

#### Scenario: Repeal outranks supersession

@e2e exclude precedence rule — covered by PHPUnit on the derivation service
- **GIVEN** a decision that is targeted by both an enacted `supersedes` and an enacted `repeals` relation
- **WHEN** its effective status is computed
- **THEN** the result is `repealed`

#### Scenario: Body notified on repeal

@e2e exclude declarative notification dialect — verified by the notification-dialect gate plus PHPUnit on the rule import
- **WHEN** a decision's effective status becomes `repealed`
- **THEN** recipients defined in the ADR-031 rule receive an NC notification naming the repealing decision

---

### Requirement: In-force visibility in list and detail views

The decision list SHALL offer an in-force filter with the values `in force`, `superseded`, and `repealed`, computed from the derived effective status. The decision detail view SHALL display a prominent banner when the effective status differs from the lifecycle status, naming the effecting decision with navigation to it, and SHALL show outgoing and derived incoming relations grouped by type with navigation. The lifecycle status badge SHALL always remain visible alongside the effective status.

#### Scenario: Filter the register to decisions in force

- **GIVEN** a register containing enacted, superseded, and repealed decisions
- **WHEN** the user filters the decision list by `in force`
- **THEN** superseded and repealed decisions are excluded from the results and the result count reflects only decisions in force

#### Scenario: Superseded banner with chain navigation

- **GIVEN** a decision whose effective status is `superseded` by "Budget 2027"
- **WHEN** the user opens its detail view
- **THEN** a banner states it is superseded by "Budget 2027" with its date, clicking the banner navigates to "Budget 2027", and the original lifecycle badge remains visible

#### Scenario: Published payload carries relations and effective status

@e2e exclude payload-shape contract — covered by Newman on the published object (public-publication capability)
- **GIVEN** a published decision that carries relations and the public-publication capability is configured
- **WHEN** the publication payload is built
- **THEN** it includes the relation metadata in ORI/Akoma Ntoso-compatible fields and the effective status at publish time
