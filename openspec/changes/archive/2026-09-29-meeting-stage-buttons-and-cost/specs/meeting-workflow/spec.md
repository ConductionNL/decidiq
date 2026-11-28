# meeting-workflow Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-stage-buttons-and-cost](../../) (this delta)

## Purpose

The guarded meeting state machine and the meeting cost calculation exist on the server, but no button calls them, so the stage is typed as a plain field and the cost is never stamped. Moves decidiq matrix rows pla-15, pla-10 toward built.

## ADDED Requirements

### Requirement: REQ-MSB-001 The chair moves a meeting through its stages

The meeting page SHALL show the stage transitions the caller may take and SHALL apply them through the guarded lifecycle; the create form SHALL NOT set the stage directly.

#### Scenario: The chair opens the meeting
- GIVEN a scheduled council meeting
- WHEN its chair presses Open meeting on the meeting page
- THEN the page shows the stage opened and a member sees no stage buttons

### Requirement: REQ-MSB-002 Closing a meeting records its cost

Closing a meeting through the stage buttons SHALL record the meeting's cost in attendee time, shown on the meeting and in the body's efficiency widget.

#### Scenario: The cost appears after closing
- GIVEN an opened meeting with 10 attendees ran 2 hours and the body has an hourly rate
- WHEN the chair closes the meeting
- THEN the Outcome widget shows the meeting cost
