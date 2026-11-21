# p2-minutes-and-decisions Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [minutes-draft-and-send](../../) (this delta)

## Purpose

The server can draft minutes from the agenda, votes and decisions, and from a transcript with AI, and can tell members minutes are available; but the first has no button and no attendance, the AI draft is never written into the minutes, and distribution is API only. Moves decidiq matrix rows min-01, min-02, min-07 toward built.

## ADDED Requirements

### Requirement: REQ-MDS-001 Draft minutes from the meeting

The minutes page SHALL draft the minutes from the meeting's agenda, attendance, votes and decisions.

#### Scenario: The secretary starts from a draft
- GIVEN the meeting of 14 October had 27 present and 2 excused and took 4 decisions
- WHEN the secretary presses Draft from the meeting
- THEN the minutes list the attendance, each item, its votes and its decisions

### Requirement: REQ-MDS-002 Use the AI draft as the minutes

An AI draft from the transcript SHALL be writable into the minutes record, keeping only the sections the secretary kept.

#### Scenario: The AI draft lands in the minutes
- GIVEN an AI draft with six sections of which one was discarded
- WHEN the secretary presses Use as minutes
- THEN the minutes hold the five kept sections

### Requirement: REQ-MDS-003 Send approved minutes to the members

Approved minutes SHALL be sendable to the body members from the minutes page.

#### Scenario: Members receive the minutes
- GIVEN the minutes of 14 October were approved
- WHEN the secretary presses Send to members
- THEN each member is notified with a link to the minutes
