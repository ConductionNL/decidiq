# decision-management Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-decision-page](../../)

## ADDED Requirements

### Requirement: REQ-SDP-001 The next step is the one primary button

In the simple structure, the decision page MUST show one primary button: the transition that leaves the decision's current state. The button MUST post to the guarded transition endpoint with the action and nothing else. The page MUST NOT decide who may take a step. The full structure MUST keep the page as the manifest declares it.

#### Scenario: A decision in deliberation
@e2e exclude Read from the built page in simpleDecisionPage.spec.js with the library's own visibility evaluator; the suite has no fixture that holds a decision in each state.
- **GIVEN** the simple structure and a decision whose lifecycle is `deliberating`
- **WHEN** somebody opens it
- **THEN** the primary button MUST be Open voting
- **AND** no other transition MUST be offered as a button

#### Scenario: Every transition the server has is offered in a state the server allows
@e2e exclude A comparison with DecisionTransitionGuard's transition map, asserted in simpleDecisionPage.spec.js.
- **GIVEN** the transition map in `DecisionTransitionGuard`
- **WHEN** the header actions are read
- **THEN** each of its six actions MUST be offered
- **AND** none MUST be offered in a state the map does not allow it from

#### Scenario: A member tries a chair-only step
@e2e exclude The refusal is DecisionLifecycleService's and is covered by DecisionLifecycleServiceTest; simpleDecisionPage.spec.js asserts the page sends the action only and names no role.
- **GIVEN** a decision in deliberation and a member who is not the chair
- **WHEN** they choose Open voting
- **THEN** the server MUST refuse it, as it does today
- **AND** the page MUST show the server's message

#### Scenario: An enacted decision that is not public
@e2e exclude Read from the built page in simpleDecisionPage.spec.js.
- **GIVEN** a decision whose lifecycle is `enacted` and whose publication state is not `public`
- **WHEN** somebody opens it
- **THEN** the primary button MUST be Publish, and it MUST ask before it publishes

#### Scenario: The full structure
@e2e exclude An equality with the manifest, asserted in simpleDecisionPage.spec.js.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the decision page and the motion page are built
- **THEN** each MUST equal the page the manifest declares

### Requirement: REQ-SDP-002 A what-now card lists what the state still needs

Every state with a next step MUST have a card with a checklist. Each item MUST read a field of the decision. A decision without a state MUST show no card and no next-step button.

#### Scenario: A draft with its text written
@e2e exclude Resolved with the library's resolveNextStep in simpleDecisionPage.spec.js.
- **GIVEN** a draft decision with a text, a proposer and a legal basis
- **WHEN** the card is drawn
- **THEN** all three items MUST be ticked

#### Scenario: A field that does not exist
@e2e exclude A reading of the schema, asserted in simpleDecisionPage.spec.js.
- **GIVEN** the checklists, the pill and the side column
- **WHEN** their fields are compared with the decision schema
- **THEN** every field MUST exist

### Requirement: REQ-SDP-003 A step bar shows where the decision stands

The page MUST show the seven states of a decision as a bar, with the current one marked. The bar MUST NOT move a decision. A withdrawn decision MUST be said to be withdrawn in words.

#### Scenario: A decision in voting
@e2e exclude The model is buildTimeline, asserted in simpleDecisionPage.spec.js and decisionLifecycle.spec.js.
- **GIVEN** a decision whose lifecycle is `voting`
- **WHEN** the bar is drawn
- **THEN** Draft, Proposed and Deliberating MUST be done, Voting current, and the rest upcoming

### Requirement: REQ-SDP-004 The blocks of a decision sit behind five tabs and More

In the simple structure the fifteen blocks MUST be behind the tabs Content, Route and voting, Documents, Consultation and Publication, with the rest under More. Every block the manifest declares MUST be in exactly one tab. The Lifecycle block MUST stay on the page.

#### Scenario: No block is lost
@e2e exclude A comparison of widget ids, asserted in simpleDecisionPage.spec.js.
- **GIVEN** the fifteen widgets the manifest declares
- **WHEN** the tabs and their sections are read
- **THEN** each widget MUST be in one tab, and in one only

#### Scenario: A block a tab cannot resolve
@e2e exclude A reading of widget types against the registry and the library's catalog, asserted in simpleDecisionPage.spec.js.
- **GIVEN** the blocks in the tabs
- **WHEN** their types are read
- **THEN** none MUST be `custom`
- **AND** each MUST be a library widget, the sections container or a component in the app registry

### Requirement: REQ-SDP-005 The motion page has the same header

In the simple structure the motion page MUST show the state as a pill, one primary button for the step that leaves the state, and a what-now card. The buttons MUST post to the motion transition endpoint with the words the Stage block sends. Its blocks MUST stay where they are.

#### Scenario: A motion in voting
@e2e exclude Read from the built page in simpleDecisionPage.spec.js.
- **GIVEN** a motion whose lifecycle is `voting`
- **WHEN** somebody opens it
- **THEN** the primary button MUST be Record as adopted
- **AND** Record as rejected MUST be offered beside it

#### Scenario: The blocks stay
@e2e exclude An equality of widgets and layout with the full structure, asserted in simpleDecisionPage.spec.js.
- **GIVEN** the simple structure
- **WHEN** the motion page is built
- **THEN** its widgets and its layout MUST equal the full structure's
