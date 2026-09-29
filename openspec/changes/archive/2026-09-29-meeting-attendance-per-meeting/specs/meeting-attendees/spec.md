# meeting-attendees Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-attendance-per-meeting](../../) (this delta)

## Purpose

Attendance is one value per person that each meeting overwrites, so there is no record of who attended which meeting. Moves decidiq matrix rows pla-09 toward built.

## ADDED Requirements

### Requirement: REQ-MAPM-001 Attendance is recorded per meeting

The meeting's Participants widget SHALL record for each participant whether they were present, absent, excused or represented, per meeting, without changing other meetings' records.

#### Scenario: The clerk records apologies
- GIVEN council member Anna sent apologies for the meeting of 14 October
- WHEN the clerk marks her as excused on that meeting
- THEN the meeting shows Anna as excused and her attendance on 7 October is unchanged
