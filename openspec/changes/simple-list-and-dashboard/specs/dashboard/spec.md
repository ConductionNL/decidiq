# dashboard Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-list-and-dashboard](../../)

## ADDED Requirements

### Requirement: REQ-SLD-001 The simple dashboard opens with the day

In the simple structure the dashboard MUST show, from the top: a greeting, an attention card when decisions are open for voting, the four counters on one row, and then two columns as the board draws them: a main column (eight of twelve) with the proposals per step, the pending votes and the reader's action items, and a side column (four) with the upcoming meetings and the commitments with a deadline. It MUST keep every widget of the full dashboard; the widgets the board does not show MUST follow below at full width, in the order they had. The full structure MUST keep the dashboard as the manifest declares it.

#### Scenario: Nothing is open for voting
@e2e exclude The card's visibility rule is the library's; simpleListAndDashboard.spec.js asserts the rule that is declared.
- **GIVEN** no decision has the lifecycle `voting`
- **WHEN** somebody opens the dashboard
- **THEN** the attention card MUST NOT show

#### Scenario: No widget is dropped
@e2e exclude A comparison of two built pages, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the widgets and the layout of the full dashboard
- **WHEN** the simple dashboard is built
- **THEN** every widget MUST be there unchanged
- **AND** every widget MUST be laid out once, and no two cards MUST share a cell

#### Scenario: Two columns under the counters
@e2e exclude A reading of the layout, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple dashboard
- **WHEN** its layout is read
- **THEN** the proposals per step, the pending votes and my action items MUST sit in a column eight wide at the left
- **AND** the upcoming meetings and the commitments with a deadline MUST sit in a column four wide at the right
- **AND** the commitments MUST show as the board's narrow list, the text wrapping and the deadline in a fixed column on the right, so nothing runs past the card at 1440 px
- **AND** the primary action of the attention card MUST be the one that opens the decisions

### Requirement: REQ-SLD-002 A number and the list it opens ask the same question

Every number the simple dashboard adds MUST be a plain-equality filter on stored fields of its schema. A link from a number MUST carry the same filter to a list of the same schema.

#### Scenario: The attention card and the Voting view
@e2e exclude A comparison of a filter with a link's query, asserted in simpleListAndDashboard.spec.js; the suite has no fixture holding decisions in voting.
- **GIVEN** the attention card counts decisions with lifecycle `voting`
- **WHEN** its link is read
- **THEN** it MUST open Decisions with `lifecycle=voting`
- **AND** the Decisions list MUST have a counted view with that filter

#### Scenario: Open commitments
@e2e exclude A comparison of a filter with a link's query, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the commitments list shows lifecycle `open`, earliest deadline first
- **WHEN** "view all" is chosen
- **THEN** Commitments MUST open with `lifecycle=open`

### Requirement: REQ-SLD-003 The proposals and decisions lists have five counted views

In the simple structure the proposals list and the decisions list MUST show five views in front, each with a count, and keep every other view behind the overflow. Urgent decisions MUST be a view on both. No view of the full structure may be dropped.

#### Scenario: The views of the proposals list
@e2e exclude A reading of the built page, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure
- **WHEN** the proposals list is built
- **THEN** its first five views MUST be All proposals, Draft, Proposed, Deliberating and Voting
- **AND** Decided and Urgent MUST follow

#### Scenario: Nothing else on the list changes
@e2e exclude A comparison of two built pages, asserted in simpleListAndDashboard.spec.js.
- **GIVEN** the full structure's list page
- **WHEN** the simple one is compared with it
- **THEN** only the views, their limit and the header links MUST differ
