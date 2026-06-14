# Spec delta: Decision Management — universal supertype with decisionType discriminator

This file contains delta specifications for the `unify-decision-supertype` change against the existing `decision-management` capability. It makes `Decision` the single universal supertype (ADR-005): a required `decisionType` discriminator, folded motion/amendment/resolution fields rendered via progressive disclosure, a declarative decision lifecycle (ADR-031), and `offer`/`order`/`product` re-homed as `contract` decision attachments. The existing decision lifecycle and CRUD requirements are otherwise unchanged.

---

## ADDED Requirements

### Requirement: Decision type discriminator

The `decision` schema SHALL carry a required `decisionType` enum discriminator with values `motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, and `meeting-outcome`. `Decision` SHALL be the single universal supertype for every governance outcome; motion, amendment, resolution, contract, appointment, management point, policy and generic meeting outcome SHALL NOT be modelled as separate schemas but as values of `decisionType` (ADR-005, ADR-006). The decision register list, search and detail page SHALL be one surface filtered by `decisionType`; a typed nav entry such as "Moties" SHALL be the decision register pre-filtered to `decisionType=motion`, never a separate store.

#### Scenario: Create a decision with a required type

- **GIVEN** a user with decision-making access creating a decision
- **WHEN** they submit a decision without selecting a `decisionType`
- **THEN** the system MUST reject the create with a validation error naming `decisionType` as required

#### Scenario: A typed nav filter is the same store

- **GIVEN** decisions exist with `decisionType` values `motion` and `resolution`
- **WHEN** the user opens the "Moties" nav entry
- **THEN** the decision register list is shown pre-filtered to `decisionType=motion`, sourced from the same `decision` store as all other decisions

---

### Requirement: Folded type-specific fields with progressive disclosure

The `decision` schema SHALL absorb the type-specific fields formerly carried by the retired `motion`, `amendment`, and `resolution` schemas: motion fields (`motionType`, `proposer`, `coSigners`, `text`), amendment fields (`proposedText` and an `amends` relation to the parent decision), and resolution fields (`resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`). These fields SHALL render only when the matching `decisionType` is selected (progressive disclosure, ADR-004 Rule 2). Required-field enforcement SHALL be keyed on `decisionType`: a `motion` decision SHALL require `proposer`; a `resolution` decision SHALL require `resolutionNumber` and `voteThreshold`; an `amendment` decision SHALL require the `amends` relation to a parent decision.

#### Scenario: Motion fields appear only for a motion decision

- **GIVEN** a user creating a decision
- **WHEN** they select `decisionType = motion`
- **THEN** the form reveals the `proposer`, `coSigners`, and `motionType` fields, and these fields are hidden when `decisionType` is `meeting-outcome`

#### Scenario: Type-specific required field is enforced

- **GIVEN** a user creating a decision with `decisionType = resolution`
- **WHEN** they submit without `resolutionNumber` or `voteThreshold`
- **THEN** the create is rejected with a validation error naming the missing resolution fields

#### Scenario: Amendment links to its parent decision

- **GIVEN** an existing `decisionType = motion` decision
- **WHEN** a user creates a `decisionType = amendment` decision and sets its `amends` relation to that motion decision
- **THEN** the amendment decision is stored with an OpenRegister relation to the parent motion decision

---

### Requirement: Declarative decision lifecycle

The decision lifecycle SHALL be declared as an `x-openregister-lifecycle` block on the `decision` schema in `lib/Settings/decidesk_register.json` (ADR-031 — declarative, NOT a Service-class state machine). The lifecycle SHALL retain the existing guarded states `draft → proposed → deliberating → voting → decided → enacted → archived` and SHALL add a terminal `withdrawn` state reachable from any non-terminal state before `enacted`. Lifecycle status SHALL be orthogonal to `outcome` (the voting result) and `isPublished` (citizen visibility). Transitions SHALL be guarded by the declared transition map; no imperative `DecisionService` transition method SHALL be introduced by this change.

#### Scenario: Lifecycle is declared in the register

- **GIVEN** the decidesk register definition
- **WHEN** the `decision` schema is inspected
- **THEN** it contains an `x-openregister-lifecycle` block declaring the guarded transition map including the `withdrawn` terminal state

#### Scenario: A decision can be withdrawn before enactment

- **GIVEN** a decision in lifecycle `deliberating`
- **WHEN** an authorised user withdraws it
- **THEN** the decision transitions to `withdrawn` and no further forward transition is permitted

#### Scenario: A guarded transition is rejected

- **GIVEN** a decision in lifecycle `draft`
- **WHEN** a transition directly to `enacted` is attempted
- **THEN** the transition is rejected by the declared lifecycle guard and the status remains `draft`

---

### Requirement: Contract decisions carry offer/order/product attachments

A `decisionType = contract` decision SHALL be able to carry `offer`, `order`, and `product` objects as attachments via `x-openregister-relations` on the `decision` schema (ADR-005). The `offer`, `order`, and `product` schema.org schemas SHALL NOT exist as orphaned standalone entities in the nav; they SHALL be reachable as attachments of a contract decision. Non-contract decision types SHALL NOT require these attachments.

#### Scenario: Attach an offer to a contract decision

- **GIVEN** a `decisionType = contract` decision
- **WHEN** an `offer` object is related to it
- **THEN** the offer is stored as an OpenRegister relation on the contract decision and appears in the decision's attachments

#### Scenario: Procurement schemas are not orphaned nav items

- **GIVEN** the decidesk navigation
- **WHEN** the nav is rendered
- **THEN** `offer`, `order`, and `product` do not appear as standalone top-level stores; they are reached through contract decisions

---

### Requirement: Re-seeded typed decision demo data

The shipped demo data SHALL include at least one `decision` seed object per `decisionType` value so every type is demonstrable on install (ADR-016). The former `motion`, `amendment`, and `resolution` seed objects SHALL be re-seeded as `decision` objects with the matching `decisionType`, preserving their slugs where reasonable and re-pointing their relations (amendment→motion becomes an `amends` relation between decisions). Seed data SHALL use general organisation domains (municipality, corporate board, consultancy, travel agency).

#### Scenario: Every decision type has a seed

- **GIVEN** a freshly installed decidesk register
- **WHEN** the decision register is listed
- **THEN** at least one decision exists for each `decisionType` value (`motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, `meeting-outcome`)

#### Scenario: Migrated amendment seed links to its motion

- **GIVEN** the re-seeded demo data
- **WHEN** a `decisionType = amendment` seed (e.g. `amendement-cultuursubsidie`) is inspected
- **THEN** it carries an `amends` relation to a `decisionType = motion` decision
