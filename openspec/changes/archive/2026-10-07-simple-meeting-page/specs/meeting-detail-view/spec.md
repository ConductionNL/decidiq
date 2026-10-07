# meeting-detail-view Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-meeting-page](../../)

## ADDED Requirements

### Requirement: REQ-SMP-001 The next step is the one primary button

In the simple structure, the meeting page MUST show one primary button for every state but `closed`: the step that moves the meeting on. The button MUST post to the guarded lifecycle endpoint with the action and nothing else. The page MUST NOT decide who may take a step. The header MUST NOT offer a step a governance domain can switch off. The full structure MUST keep the page as the manifest declares it.

#### Scenario: A convened meeting
@e2e exclude Read from the built page in simpleMeetingPage.spec.js with the library's own visibility evaluator; the suite has no fixture that holds a meeting in each state.
- **GIVEN** the simple structure and a meeting whose lifecycle is `scheduled`
- **WHEN** somebody opens it
- **THEN** the primary button MUST be Open meeting, and it MUST ask before it opens
- **AND** Close meeting MUST be under More

#### Scenario: Every step is offered in exactly the states the server allows
@e2e exclude A comparison with MeetingService's transition table, asserted in simpleMeetingPage.spec.js.
- **GIVEN** the transition table in `MeetingService`
- **WHEN** the header actions are read
- **THEN** convene, open, resume and close MUST each be offered in every state the table allows them from
- **AND** none MUST be offered in any other state

#### Scenario: Pause and adjourn stay in the Stage block
@e2e exclude A reading of WorkflowService's domain rules, asserted in simpleMeetingPage.spec.js.
- **GIVEN** the two edges a governance domain can forbid
- **WHEN** the header actions are read
- **THEN** neither pause nor adjourn MUST be among them
- **AND** the Stage block MUST still be on the page

#### Scenario: A closed meeting
@e2e exclude Read from the built page in simpleMeetingPage.spec.js.
- **GIVEN** a meeting whose lifecycle is `closed`
- **WHEN** somebody opens it
- **THEN** no step and no what-now card MUST show

#### Scenario: The full structure
@e2e exclude An equality with the manifest, asserted in simpleMeetingPage.spec.js.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the meeting page is built
- **THEN** it MUST equal the page the manifest declares

### Requirement: REQ-SMP-002 A what-now card lists what the state still needs

A draft, a convened and an open meeting MUST each have a card with a checklist. Each item MUST read a field of the meeting. A card MUST NOT show in a state without a primary button. The quorum item MUST read the field the server refuses on.

#### Scenario: A convened meeting that is ready
@e2e exclude Resolved with the library's resolveNextStep in simpleMeetingPage.spec.js.
- **GIVEN** a convened meeting with a published agenda, a sent convocation and a quorum
- **WHEN** the card is drawn
- **THEN** all three items MUST be ticked

#### Scenario: A field that does not exist
@e2e exclude A reading of the schema, asserted in simpleMeetingPage.spec.js.
- **GIVEN** the checklists, the pill and the side column
- **WHEN** their fields are compared with the meeting schema
- **THEN** every field MUST exist

### Requirement: REQ-SMP-003 A step bar shows where the meeting stands

The page MUST show four steps as a bar: draft, convened, in session and closed, with the current one marked in a word and not by colour alone. The bar MUST NOT move a meeting. A paused or adjourned meeting MUST stay on the third step, and the bar MUST say the break in words.

#### Scenario: A paused meeting
@e2e exclude The model is buildMeetingSteps, asserted in simpleMeetingPage.spec.js.
- **GIVEN** a meeting whose lifecycle is `paused`
- **WHEN** the bar is drawn
- **THEN** Draft and Convened MUST be done, In session current and Closed upcoming

### Requirement: REQ-SMP-004 The page says who takes the next step

The step bar MUST ask the server which steps it offers the reader. When the step the primary button takes is not among them, the bar MUST say that only the chair or the secretary can take the next step. Without an answer the bar MUST say nothing about roles.

#### Scenario: A member opens a convened meeting
@e2e exclude The decision is nextStepIsOffered, asserted in simpleMeetingPage.spec.js; the refusal itself is MeetingController's and is covered by its own tests.
- **GIVEN** a convened meeting and a member who is not its chair or secretary
- **WHEN** the server offers that member no step
- **THEN** the bar MUST say that only the chair or the secretary can take the next step
- **AND** pressing Open meeting MUST show the server's refusal, as it does today

#### Scenario: The chair opens a convened meeting
@e2e exclude The decision is nextStepIsOffered, asserted in simpleMeetingPage.spec.js.
- **GIVEN** a convened meeting and its chair
- **WHEN** the server offers `open`
- **THEN** the bar MUST NOT show that sentence

### Requirement: REQ-SMP-005 The blocks of a meeting sit behind five tabs and More

In the simple structure the twenty-five blocks MUST be behind the tabs Agenda, Participants, Documents, Decisions and Minutes, with the rest under More. Every block the manifest declares MUST be in exactly one tab. The Stage block MUST stay on the page.

#### Scenario: No block is lost
@e2e exclude A comparison of widget ids, asserted in simpleMeetingPage.spec.js.
- **GIVEN** the twenty-five widgets the manifest declares
- **WHEN** the tabs and their sections are read
- **THEN** each widget MUST be in one tab, and in one only

#### Scenario: A block a tab cannot resolve
@e2e exclude A reading of widget types against the registry and the library's catalog, asserted in simpleMeetingPage.spec.js.
- **GIVEN** the blocks in the tabs
- **WHEN** their types are read
- **THEN** none MUST be `custom`
- **AND** each MUST be a library widget, the sections container or a component in the app registry
