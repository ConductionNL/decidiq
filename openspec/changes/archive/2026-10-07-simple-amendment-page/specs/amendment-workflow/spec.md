# amendment-workflow Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-amendment-page](../../)

## ADDED Requirements

### Requirement: REQ-SAP-001 The two steps before the vote are the primary button

In the simple structure, the amendment page MUST show one primary button on a draft and on a proposed amendment: the step that leaves that state. The button MUST post to the guarded amendment transition endpoint with the new state and nothing else. The page MUST NOT decide who may take a step. The header MUST NOT offer the vote or its result. The full structure MUST keep the page as the manifest declares it.

#### Scenario: A proposed amendment
@e2e exclude Read from the built page in simpleAmendmentPage.spec.js with the library's own visibility evaluator; the suite has no fixture that holds an amendment in each state.
- **GIVEN** the simple structure and an amendment whose lifecycle is `proposed`
- **WHEN** somebody opens it
- **THEN** the primary button MUST be Start the debate
- **AND** no other step MUST be offered as a button

#### Scenario: Each button names the state the server allows
@e2e exclude A comparison with MotionLifecycleTransitioner's amendment table, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** the amendment transition table
- **WHEN** the header actions are read
- **THEN** each MUST send the one state the table allows from the state it shows in

#### Scenario: The vote stays in the Voting round block
@e2e exclude A reading of the header actions and of VotingRoundOpener and VotingRoundCloser, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** an amendment in deliberation
- **WHEN** somebody opens it
- **THEN** no header button MUST send `voting`, `decided` or an outcome
- **AND** the Voting round block MUST be a tab of its own

#### Scenario: The full structure
@e2e exclude An equality with the manifest, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the amendment page is built
- **THEN** it MUST equal the page the manifest declares

### Requirement: REQ-SAP-002 A what-now card lists what the state still needs

A draft and a proposed amendment MUST each have a card with a checklist. Each item MUST read a field of the decision schema. A card MUST NOT show in a state without a primary button.

#### Scenario: A draft that is ready
@e2e exclude Resolved with the library's resolveNextStep in simpleAmendmentPage.spec.js.
- **GIVEN** a draft amendment with a proposed text, a proposer and a motion
- **WHEN** the card is drawn
- **THEN** all three items MUST be ticked

### Requirement: REQ-SAP-003 A step bar shows where the amendment stands

The page MUST show the five states of an amendment as a bar, in the order the server moves through them, with the current one marked in a word and not by colour alone. The bar MUST NOT move an amendment.

#### Scenario: An amendment in deliberation
@e2e exclude The model is buildAmendmentSteps, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** an amendment whose lifecycle is `deliberating`
- **WHEN** the bar is drawn
- **THEN** Draft and Proposed MUST be done, Deliberating current, and the rest upcoming

### Requirement: REQ-SAP-004 The page says who takes the next step and where

Under the steps the bar MUST say, on a draft and on a proposed amendment, that only the chair or the secretary can take the next step. In deliberation and in voting it MUST say that the vote opens and closes under Voting round. On a decided amendment it MUST say whether the amendment was adopted or rejected.

#### Scenario: A member opens a draft amendment
@e2e exclude The sentence is chosen by amendmentNote, asserted in simpleAmendmentPage.spec.js; the refusal itself is MotionController's.
- **GIVEN** a draft amendment and a member who is not in the chair group
- **WHEN** they open it
- **THEN** the bar MUST say that only the chair or the secretary can take the next step
- **AND** pressing Submit amendment MUST show the server's refusal

#### Scenario: An adopted amendment
@e2e exclude The sentence is chosen by amendmentNote, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** a decided amendment whose outcome is `adopted`
- **WHEN** the bar is drawn
- **THEN** it MUST say the amendment was adopted

### Requirement: REQ-SAP-005 The blocks of an amendment sit behind three tabs

In the simple structure the four blocks MUST be behind the tabs Text changes, Amendment and Voting round. Every block the manifest declares MUST be in exactly one tab.

#### Scenario: No block is lost
@e2e exclude A comparison of widget ids, asserted in simpleAmendmentPage.spec.js.
- **GIVEN** the four widgets the manifest declares
- **WHEN** the tabs and their sections are read
- **THEN** each widget MUST be in one tab, and in one only
