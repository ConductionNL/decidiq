# decidesk-notifications Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-reminders-before-deadlines](../../) (this delta)

## Purpose

The notification settings offer meeting created and meeting reminder switches that no sender reads, and no reminder goes out before a submission deadline. Moves decidiq matrix rows pla-06, plt-09 toward built.

## ADDED Requirements

### Requirement: REQ-MRD-001 Meeting notices follow the member switches

decidiq SHALL send a notice when a meeting is scheduled and a reminder before it starts, each only to members who left that switch on.

#### Scenario: Pieter is reminded
- GIVEN Pieter left Meeting reminder on and a council meeting starts tomorrow at 19:00
- WHEN the reminder job runs 24 hours before
- THEN Pieter gets one reminder linking the meeting, and Anna who switched it off gets none

### Requirement: REQ-MRD-002 A reminder before the submission deadline

decidiq SHALL remind the members of a body once, 48 hours before a meeting's submission deadline.

#### Scenario: The deadline reminder
- GIVEN the submission deadline for motions is Friday 12:00
- WHEN the reminder job runs on Wednesday after 12:00
- THEN members get one reminder naming the deadline and the meeting
