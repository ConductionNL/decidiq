---
status: in-progress
status-note: >-
  Completed 2026-06-12 via decision-state-machine-v1 (guarded 7-state transition map + per-domain policy, lifecycle/voting detail tabs) on top of decision-evolution-and-cascade + p2-minutes-and-decisions. In progress 2026-06-14 via unify-decision-supertype (Decision becomes the universal supertype: decisionType discriminator, folded motion/amendment/resolution fields, declarative lifecycle, contract attachments).
openspec-changes:
  - unify-decision-supertype
  - decision-detail-fullpicture
  - urgent-decision-procedure
---

# Decision Management Specification

## Purpose

Decision management is the core capability of Decidesk. A decision represents a formal choice made by a governance body, association, corporate board, or operational team. Each decision follows a configurable state machine lifecycle from proposal through deliberation, voting, and resolution. This specification covers the decision entity, status transitions, the Symfony Workflow-backed state machine, and audit trail recording.

**Standards**: Schema.org (`Action`, `VoteAction`, `ChooseAction`), Akoma Ntoso (`decision`, `judgment`), OpenRaadsinformatie (`Besluit`)
**Feature tier**: MVP

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Decision entity definition including property tables, Schema.org mappings, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.
## Requirements

---

### Requirement: Decision Creation

The system MUST support creating decision records linked to a meeting, agenda item, or body. Each decision MUST have a `title`, a `body` (governing body reference), and an initial status of `draft`. Decisions MUST be stored as OpenRegister objects in the `decidesk` register using the `decision` schema.

**Feature tier**: MVP

#### Scenario: Create a decision from a meeting agenda item

- GIVEN a user with decision-making access and an active meeting with agenda items
- WHEN they create a new decision linked to agenda item "Budget Approval 2026"
- THEN the system MUST create an OpenRegister object in the `decidesk` register with the `decision` schema
- AND the object MUST have `@type` set to `schema:ChooseAction`
- AND the `status` MUST be set to `draft`
- AND the decision MUST reference the agenda item and meeting

#### Scenario: Create a standalone decision outside a meeting

- GIVEN a user with decision-making access
- WHEN they create a decision with title "Appoint new treasurer" and body "Board of Directors"
- THEN the system MUST create the decision with status `draft`
- AND the decision MUST NOT require a meeting or agenda item reference
- AND the decision MUST reference the body "Board of Directors"

#### Scenario: Fail to create a decision without a title

- GIVEN a user with decision-making access
- WHEN they submit a new decision form without a title
- THEN the system MUST reject the request with a validation error
- AND no OpenRegister object MUST be created

---

### Requirement: Decision State Machine

The system MUST enforce a configurable state machine for decision lifecycle management, implemented as a guarded transition map (`DecisionTransitionGuard` in `lib/Lifecycle/` — the decidesk lifecycle pattern, no workflow-library dependency). The lifecycle MUST be stored in an additive `lifecycle` field on the `Decision` schema and MUST include the states `draft`, `proposed`, `deliberating`, `voting`, `decided`, `enacted`, `archived`. Only valid transitions MUST be allowed; an invalid transition MUST be rejected with an error naming the allowed transitions from the current state. Transition policy MUST be configurable per governance domain (quorum enforcement, chair-only transitions, decide-without-vote for operational domains) with a default-deny fallback for unknown domains. Entering `voting` in a quorum-enforced domain with a linked meeting MUST be blocked while the meeting's quorum is not met. Chair-only transitions MUST be rejected when the caller is not the resolved meeting chair, and MUST fail closed when no chair can be resolved. The `enact` transition MUST require `outcome=adopted` and MUST record the enacted date. Every transition MUST be appended to the hash-chained audit log with actor and timestamp.

**Feature tier**: MVP
**Legal reference**: Awb 3:40-3:45 (formal decision requirements), Gemeentewet 56 (council decision procedures)

#### Scenario: Transition a decision from draft to proposed

- GIVEN a decision in `draft` status with all required fields completed
- WHEN the decision owner triggers the "propose" transition
- THEN the status MUST change to `proposed`
- AND the transition MUST be recorded in the audit trail with timestamp and actor
- AND notifications MUST be sent to all members of the governing body

#### Scenario: Reject an invalid state transition

- GIVEN a decision in `draft` status
- WHEN a user attempts to transition directly to `decided`
- THEN the system MUST reject the transition with an error indicating the allowed transitions from `draft`
- AND the decision status MUST remain `draft`

#### Scenario: Transition a decision to enacted after approval

- GIVEN a decision in `decided` status with a positive voting outcome
- WHEN the decision owner triggers the "enact" transition
- THEN the status MUST change to `enacted`
- AND the system MUST generate a resolution record (see resolution-minutes spec)
- AND the enacted date MUST be recorded

#### Scenario: Available transitions are exposed for the current state

- GIVEN a decision in any lifecycle state
- WHEN the available transitions are requested for the decision
- THEN the system MUST return the current lifecycle state and exactly the actions permitted by the transition map and the domain policy

#### Scenario: Quorum gate blocks opening the vote

@e2e exclude backend quorum-guard contract — exhaustively covered by the PHPUnit guard matrix and the Newman 422 contract; the dev seed has no unmet-quorum meeting to drive a UI flow against
- GIVEN a decision in `deliberating` status linked to a meeting whose quorum is not met, in a quorum-enforced domain
- WHEN a user triggers the "openVoting" transition
- THEN the system MUST reject the transition with a quorum error
- AND the decision status MUST remain `deliberating`

#### Scenario: Chair-only transition is enforced per domain

@e2e exclude authorization contract — covered by PHPUnit (chair mismatch + unresolvable-chair fail-closed) and Newman; not a UI flow (the UI only offers actions the server allows)
- GIVEN a decision in a domain whose policy marks the transition chair-only
- WHEN an authenticated user who is not the resolved meeting chair triggers that transition
- THEN the system MUST reject the transition
- AND when no chair can be resolved at all the transition MUST also be rejected (fail closed)

---

### Requirement: Decision Audit Trail

The system MUST maintain a complete audit trail for every decision, recording all state transitions, modifications, votes, and comments with timestamps and actor identities. The audit trail MUST be immutable.

**Feature tier**: MVP
**Legal reference**: WBTR (Wet bestuur en toezicht rechtspersonen) documentation requirements

#### Scenario: View the complete history of a decision

- GIVEN a decision that has moved through draft, proposed, deliberating, voting, and decided
- WHEN a user views the decision's audit trail
- THEN the system MUST display all transitions in chronological order
- AND each entry MUST show the timestamp, actor name, previous state, new state, and optional comment

#### Scenario: Audit trail entries are immutable

- GIVEN a decision with audit trail entries
- WHEN any user (including admin) attempts to modify or delete an audit trail entry
- THEN the system MUST reject the modification
- AND the original entry MUST remain unchanged

---

### Requirement: Decision List and Search

The system MUST provide a list view of all decisions with search, sort, and filter capabilities. Users MUST be able to filter by status, body, date range, and decision type.

**Feature tier**: MVP

#### Scenario: Filter decisions by status

- GIVEN the decision list contains decisions in various statuses
- WHEN the user filters by status "voting"
- THEN only decisions currently in the `voting` state MUST be displayed
- AND the result count MUST be shown

#### Scenario: Search decisions by title

- GIVEN decisions exist with titles "Budget 2026", "New parking policy", "Staff expansion"
- WHEN the user searches for "budget"
- THEN the decision "Budget 2026" MUST appear in the results
- AND the search MUST be case-insensitive

---

### Requirement: Decision Detail View

The system MUST provide a detail view for each decision using the `CnDetailPage` and `CnObjectSidebar` components, declared via the manifest registry pattern. The detail view MUST show decision metadata, a **Lifecycle** sidebar tab with state machine visualization (every lifecycle state marked done/current/upcoming plus the allowed next transitions as actions), a **Voting** sidebar tab with voting results (for/against/abstain tallies from the voting-round and vote objects linked through the decision's motion), the linked agenda item/meeting, and the audit trail.

**Feature tier**: MVP

#### Scenario: View decision detail with voting results

- GIVEN a decision in `decided` status with completed voting
- WHEN the user navigates to the decision detail view
- THEN the page MUST display the decision title, body, status badge, and description
- AND the voting results MUST show for/against/abstain counts
- AND the state machine visualization MUST highlight the current state
- AND the sidebar MUST show metadata, linked meeting, and action buttons

#### Scenario: State machine visualization highlights the current state

- GIVEN a decision in any lifecycle state
- WHEN the user opens the Lifecycle tab on the decision detail view
- THEN all seven lifecycle states MUST be rendered in order
- AND the decision's current state MUST be visually highlighted
- AND the allowed next transitions MUST be presented as actions

### Requirement: Decision type discriminator

The `decision` schema SHALL carry a required `decisionType` enum discriminator with values `motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, and `meeting-outcome`. `Decision` SHALL be the single universal supertype for every governance outcome; motion, amendment, resolution, contract, appointment, management point, policy and generic meeting outcome SHALL NOT be modelled as separate schemas but as values of `decisionType` (ADR-005, ADR-006). The decision register list, search and detail page SHALL be one surface filtered by `decisionType`; a typed nav entry such as "Moties" SHALL be the decision register pre-filtered to `decisionType=motion`, never a separate store.

#### Scenario: Create a decision with a required type

@e2e exclude validation contract — covered by PHPUnit/Newman on the required-field rule, not a distinct UI flow
- **GIVEN** a user with decision-making access creating a decision
- **WHEN** they submit a decision without selecting a `decisionType`
- **THEN** the system MUST reject the create with a validation error naming `decisionType` as required

#### Scenario: A typed nav filter is the same store

@e2e exclude store-sourcing invariant — covered by the unified-store filter test; not browser-observable beyond the list already exercised
- **GIVEN** decisions exist with `decisionType` values `motion` and `resolution`
- **WHEN** the user opens the "Moties" nav entry
- **THEN** the decision register list is shown pre-filtered to `decisionType=motion`, sourced from the same `decision` store as all other decisions

---

### Requirement: Folded type-specific fields with progressive disclosure

The `decision` schema SHALL absorb the type-specific fields formerly carried by the retired `motion`, `amendment`, and `resolution` schemas: motion fields (`motionType`, `proposer`, `coSigners`, `text`), amendment fields (`proposedText` and an `amends` relation to the parent decision), and resolution fields (`resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`). These fields SHALL render only when the matching `decisionType` is selected (progressive disclosure, ADR-004 Rule 2). Required-field enforcement SHALL be keyed on `decisionType`: a `motion` decision SHALL require `proposer`; a `resolution` decision SHALL require `resolutionNumber` and `voteThreshold`; an `amendment` decision SHALL require the `amends` relation to a parent decision.

#### Scenario: Motion fields appear only for a motion decision

@e2e exclude progressive-disclosure UI binding — covered by vitest on the form's conditional field rendering
- **GIVEN** a user creating a decision
- **WHEN** they select `decisionType = motion`
- **THEN** the form reveals the `proposer`, `coSigners`, and `motionType` fields, and these fields are hidden when `decisionType` is `meeting-outcome`

#### Scenario: Type-specific required field is enforced

@e2e exclude validation contract — covered by PHPUnit/Newman on the type-keyed required-field rule
- **GIVEN** a user creating a decision with `decisionType = resolution`
- **WHEN** they submit without `resolutionNumber` or `voteThreshold`
- **THEN** the create is rejected with a validation error naming the missing resolution fields

#### Scenario: Amendment links to its parent decision

@e2e exclude relation-storage contract — covered by PHPUnit/Newman on the OpenRegister `amends` relation
- **GIVEN** an existing `decisionType = motion` decision
- **WHEN** a user creates a `decisionType = amendment` decision and sets its `amends` relation to that motion decision
- **THEN** the amendment decision is stored with an OpenRegister relation to the parent motion decision

---

### Requirement: Declarative decision lifecycle

The decision lifecycle SHALL be declared as an `x-openregister-lifecycle` block on the `decision` schema in `lib/Settings/decidesk_register.json` (ADR-031 — declarative, NOT a Service-class state machine). The lifecycle SHALL retain the existing guarded states `draft → proposed → deliberating → voting → decided → enacted → archived` and SHALL add a terminal `withdrawn` state reachable from any non-terminal state before `enacted`. Lifecycle status SHALL be orthogonal to `outcome` (the voting result) and `isPublished` (citizen visibility). Transitions SHALL be guarded by the declared transition map; no imperative `DecisionService` transition method SHALL be introduced by this change.

#### Scenario: Lifecycle is declared in the register

@e2e exclude register-structure invariant — verified by register-import + PHPUnit on the `x-openregister-lifecycle` block, not browser-observable
- **GIVEN** the decidesk register definition
- **WHEN** the `decision` schema is inspected
- **THEN** it contains an `x-openregister-lifecycle` block declaring the guarded transition map including the `withdrawn` terminal state

#### Scenario: A decision can be withdrawn before enactment

@e2e exclude declarative-lifecycle transition contract — covered by PHPUnit/Newman on the declared transition map
- **GIVEN** a decision in lifecycle `deliberating`
- **WHEN** an authorised user withdraws it
- **THEN** the decision transitions to `withdrawn` and no further forward transition is permitted

#### Scenario: A guarded transition is rejected

@e2e exclude declarative-lifecycle guard contract — covered by PHPUnit/Newman (rejected illegal transition); UI only offers server-allowed actions
- **GIVEN** a decision in lifecycle `draft`
- **WHEN** a transition directly to `enacted` is attempted
- **THEN** the transition is rejected by the declared lifecycle guard and the status remains `draft`

---

### Requirement: Contract decisions carry offer/order/product attachments

A `decisionType = contract` decision SHALL be able to carry `offer`, `order`, and `product` objects as attachments via `x-openregister-relations` on the `decision` schema (ADR-005). The `offer`, `order`, and `product` schema.org schemas SHALL NOT exist as orphaned standalone entities in the nav; they SHALL be reachable as attachments of a contract decision. Non-contract decision types SHALL NOT require these attachments.

#### Scenario: Attach an offer to a contract decision

@e2e exclude relation-storage contract — covered by PHPUnit/Newman on the `offer` OpenRegister relation
- **GIVEN** a `decisionType = contract` decision
- **WHEN** an `offer` object is related to it
- **THEN** the offer is stored as an OpenRegister relation on the contract decision and appears in the decision's attachments

#### Scenario: Procurement schemas are not orphaned nav items

@e2e exclude navigation-structure invariant — verified by manifest/nav assertion, not a distinct UI flow
- **GIVEN** the decidesk navigation
- **WHEN** the nav is rendered
- **THEN** `offer`, `order`, and `product` do not appear as standalone top-level stores; they are reached through contract decisions

---

### Requirement: Re-seeded typed decision demo data

The shipped demo data SHALL include at least one `decision` seed object per `decisionType` value so every type is demonstrable on install (ADR-016). The former `motion`, `amendment`, and `resolution` seed objects SHALL be re-seeded as `decision` objects with the matching `decisionType`, preserving their slugs where reasonable and re-pointing their relations (amendment→motion becomes an `amends` relation between decisions). Seed data SHALL use general organisation domains (municipality, corporate board, consultancy, travel agency).

#### Scenario: Every decision type has a seed

@e2e exclude seed-data invariant — verified by register-import + PHPUnit over the seeded objects, not browser-observable
- **GIVEN** a freshly installed decidesk register
- **WHEN** the decision register is listed
- **THEN** at least one decision exists for each `decisionType` value (`motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, `meeting-outcome`)

#### Scenario: Migrated amendment seed links to its motion

@e2e exclude seed-data relation invariant — verified by register-import + PHPUnit on the seeded `amends` relation
- **GIVEN** the re-seeded demo data
- **WHEN** a `decisionType = amendment` seed (e.g. `amendement-cultuursubsidie`) is inspected
- **THEN** it carries an `amends` relation to a `decisionType = motion` decision

### Requirement: Decision route relation

The `Decision` schema SHALL support a `route` relation to `DecisionStage` objects (one Decision → many DecisionStage), representing the ordered path the decision travels across decision-makers. The route SHALL be optional: a Decision with an empty route SHALL remain valid and behave as a single-body decision, preserving all behaviour of decisions created before this change. Adding or removing stages SHALL NOT change the Decision's own `lifecycle` field; the route is orthogonal to (and complements) the decision-to-decision relations owned by the `decision-relations` change.

#### Scenario: A decision exposes its route

@e2e exclude relation-resolution contract — covered by PHPUnit/Newman on the `route`→stages resolution
- **GIVEN** a Decision with three related DecisionStage objects
- **WHEN** the decision is loaded
- **THEN** its `route` resolves to the stages in `sequence` order without altering the decision's `lifecycle`

#### Scenario: Existing single-body decisions are unaffected

@e2e exclude backward-compatibility invariant — covered by PHPUnit on empty-route behaviour, not browser-observable
- **GIVEN** a Decision created before this change with no stages
- **WHEN** it is loaded
- **THEN** its `route` is empty and every existing field and lifecycle transition behaves exactly as before

### Requirement: Declarative route-progress fields on Decision

The `Decision` schema SHALL expose declarative route-progress fields (ADR-031), computed from its related DecisionStage objects with no imperative Service code: `currentStage` (the first stage whose `status` is neither `decided` nor `skipped`, by `sequence`; null when the route is complete), `stageCount`, `decidedStageCount`, `skippedStageCount`, and `routeComplete`. These fields SHALL be derived/materialised by OpenRegister calculations and aggregations, mirroring the existing declarative pattern already used on the Meeting schema. They SHALL NOT introduce a new Service or modify the Decision lifecycle transition map.

#### Scenario: Route progress is materialised on the decision

@e2e exclude declarative-derivation contract — covered by register/Newman, not a UI flow

- **GIVEN** a Decision with a route of three stages, two `decided` and one `active`
- **WHEN** the decision is loaded
- **THEN** `currentStage` points at the active stage, `stageCount` is 3, `decidedStageCount` is 2, and `routeComplete` is false — all derived declaratively

### Requirement: Typed decision-to-decision modification relations

The `Decision` schema SHALL support typed modification relations to other decisions, stored as OpenRegister object relations on the source decision: `supersedes` and `repeals` (effect-bearing) and `implements` and `refersTo` (informational). The existing `amends` relation (decisionType=amendment → its parent motion) SHALL retain its current meaning and SHALL be widened in description to cover "this decision modifies that decision"; a second `amends` relation SHALL NOT be introduced. Inverse views (superseded-by, repealed-by, implemented-by, referenced-by) SHALL be derived from OpenRegister relation queries and SHALL NOT be stored separately. Creating or removing an effect-bearing relation SHALL require the same governance-body authority as decision state transitions; informational relations SHALL require decision write access. Every relation addition and removal SHALL be recorded in the immutable audit trail of both the source and the target decision. The relation types SHALL map to the cited standards: Akoma Ntoso active/passive modifications, OpenRaadsinformatie `Besluit` relations, and schema.org `replacer`/`replacee` for supersession.

#### Scenario: Declare that a decision supersedes another

@e2e exclude relation-storage + audit contract — covered by PHPUnit/Newman on the stored `supersedes` relation and dual audit entries
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

@e2e exclude relation-validation contract — covered by PHPUnit on the self-reference guard
- **WHEN** a user attempts to add any relation from a decision to itself
- **THEN** the relation is rejected with a validation error and nothing is stored

#### Scenario: Cycle rejected

@e2e exclude graph-validation contract — covered by PHPUnit on the relation validation
- **GIVEN** decision A supersedes decision B
- **WHEN** a user attempts to add a `supersedes` relation from B to A
- **THEN** the relation is rejected with an error naming the existing A→B relation and nothing is stored

#### Scenario: Draft relation exerts no effect yet

@e2e exclude derived effective-status contract — covered by PHPUnit on the read-time derivation gated by source status
- **GIVEN** a decision in status `draft` carrying a `repeals` relation to an enacted decision
- **WHEN** the target decision is displayed
- **THEN** the target still presents as in force, and only when the source reaches `decided`/`enacted` does the target's effective status become `repealed`

---

### Requirement: Derived effective status

The system SHALL compute an `effectiveStatus` for every decision, derived at read time from inbound effect-bearing relations and never stored as a lifecycle state: `repealed` when a decided/enacted decision `repeals` it, else `superseded` when a decided/enacted decision `supersedes` it, else the decision's lifecycle status. The lifecycle status and its audit trail SHALL remain unchanged by relations. The derivation SHALL be expressed declaratively as an OpenRegister calculation where the inverse-relation lookup is expressible; otherwise the detail view SHALL compute it client-side from the same incoming-relation query the relations tab uses. The precedence (`repealed` > `superseded` > lifecycle) SHALL hold regardless of mechanism. A declarative ADR-031 notification rule SHALL notify the governance body when a decision becomes superseded or repealed.

#### Scenario: Enacted supersession flips effective status

@e2e exclude derived effective-status contract — covered by PHPUnit on the read-time derivation (lifecycle + audit unchanged)
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

@e2e exclude effective-status filter contract — covered by PHPUnit/Newman on the in-force filter over derived status
- **GIVEN** a register containing enacted, superseded, and repealed decisions
- **WHEN** the user filters the decision list by `in force`
- **THEN** superseded and repealed decisions are excluded from the results and the result count reflects only decisions in force

#### Scenario: Superseded banner with chain navigation

@e2e exclude detail-view derived-banner binding — covered by vitest on the banner component fed the derived effectiveStatus
- **GIVEN** a decision whose effectiveStatus is `superseded` by "Programmabegroting 2027"
- **WHEN** the user opens its detail view
- **THEN** a banner states it is superseded by "Programmabegroting 2027" with its date, activating the banner navigates to "Programmabegroting 2027", and the original lifecycle badge remains visible

### Requirement: Publication state is owned by the publication flow

The `Decision` fields `isPublished` and `publishedAt` SHALL be derived outputs of the public-publication flow: set on publish, cleared on withdraw, and rejected when written directly through object update requests. The decision detail view SHALL expose publish and withdraw actions to staff with governance-body authority, visible only when the decision meets the publication eligibility gates. Publish and withdraw events SHALL be recorded in the decision's immutable audit trail with actor, timestamp, and (for withdraw) reason.

#### Scenario: Publish action visible only when eligible

- **GIVEN** a staff member viewing a decision in status `enacted`
- **WHEN** they open the decision detail view
- **THEN** a publish action is available, while the same view for a `draft` decision offers no publish action

#### Scenario: Direct client write to isPublished rejected

@e2e exclude server-side field guard — covered by Newman attempting a direct OR object update
- **WHEN** a client sends an object update setting `isPublished: true` on a decision outside the publication flow
- **THEN** the write to `isPublished`/`publishedAt` is rejected and the stored values are unchanged

#### Scenario: Publication events in the audit trail

- **GIVEN** a decision that has been published and later withdrawn
- **WHEN** a user views the decision's audit trail
- **THEN** both the publish and the withdraw appear in chronological order with timestamp, actor, and the withdraw reason

## User Stories

1. **Board secretary creating a structured decision**: As a board secretary, I want to create a structured decision proposal with options analysis, risk assessment, and financial impact, so that the board can make well-informed strategic decisions. (Source: intelligence DB #15)

2. **Supervisory board reviewing proposals**: As a supervisory board chair, I want to review strategic proposals with full context and approve or reject them digitally, so that governance oversight is exercised efficiently. (Source: intelligence DB #16)

3. **Secretary recording decisions in real-time**: As a secretary, I want to record decisions in real-time during the MT meeting with a structured format (decision text, type, vote, conditions), so that there is immediate clarity on what was decided. (Source: intelligence DB #89)

4. **Chair circulating written resolution**: As chair, I want to circulate a proposal for written decision to all board members and collect their votes electronically so that urgent decisions can be made between meetings per BW 2:40. (Source: intelligence DB #68)

5. **Member tracking decision implementation**: As chair, I want to track the implementation status of ALV decisions with responsible persons and deadlines so that I can report progress at the next ALV. (Source: intelligence DB #77)

## Acceptance Criteria

- Decisions are stored as OpenRegister objects with `@type` of `schema:ChooseAction`
- State machine enforces valid transitions only (Symfony Workflow Component)
- All transitions are recorded in an immutable audit trail
- Decision list supports search, sort, and filter by status/body/date
- Detail view uses CnDetailPage + CnObjectSidebar with state machine visualization
- OpenRaadsinformatie `Besluit` mapping is available for each decision
