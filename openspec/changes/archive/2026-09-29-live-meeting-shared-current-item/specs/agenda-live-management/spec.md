# agenda-live-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [live-meeting-shared-current-item](../../) (this delta)

## Purpose

The live screen's current item is local to the chair's browser, the room projection endpoint has no page, the live decision endpoint has no caller, and speeches are not tied to items. Moves decidiq matrix rows liv-01, liv-04, liv-05, liv-11 toward built.

## ADDED Requirements

### Requirement: REQ-LSC-001 Everyone follows the current item

The chair's choice of current item SHALL be saved on the meeting, and members' live screens and the room screen SHALL follow it.

#### Scenario: Members follow the chair
- GIVEN the chair activates item 5 Housing plan
- WHEN member Anna has the live screen open
- THEN her screen marks item 5 as current within seconds

### Requirement: REQ-LSC-002 A room screen shows the current item and vote

decidiq SHALL offer a screen page for the room that shows the current item and the state of an open vote.

#### Scenario: The room sees the vote
- GIVEN a vote on motion M-12 is open
- WHEN the screen page is shown in the council chamber
- THEN it shows the item and that voting is open

### Requirement: REQ-LSC-003 A decision is recorded when it is taken

The live screen SHALL let the chair or secretary record a decision on the current item with its text and type.

#### Scenario: The secretary records the decision
- GIVEN item 5 is current
- WHEN the secretary records Adopted with the decision text
- THEN the decision appears on the meeting and on the item

### Requirement: REQ-LSC-004 Speeches and questions are logged per item

Speeches and questions logged on the live screen SHALL record the agenda item they belong to.

#### Scenario: Who spoke on which item
- GIVEN Anna spoke and Pieter raised a question on item 5
- WHEN the clerk opens the engagement overview
- THEN both entries show item 5
