# meeting-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [planning-parallel-sessions](../../) (this delta)

## Purpose

Splits one meeting evening into parallel sessions, each a full meeting with its own agenda, recording and subtitles, and shows them side by side. Closes decidiq matrix row pla-17.

**Standards**: Schema.org `Event` (`superEvent`, `subEvent`), OpenRaadsinformatie `Vergadering`.

## ADDED Requirements

### Requirement: REQ-PPS-001 An evening holds parallel sessions, and each session is a meeting

A meeting SHALL be able to point at a parent meeting, making it a session of that evening. A session SHALL have its own agenda, minutes, decisions, votes, transcript and broadcast, as any meeting does. The app SHALL refuse a session of a session, a session scheduled outside its evening's times, and a meeting that is its own parent. A new session SHALL take the evening's body, publicity and mode when those are left empty.

#### Scenario: The griffier splits a committee evening
- GIVEN the meeting "Commissieavond 3 november" from 18:00 to 23:00
- WHEN the griffier adds the sessions Commissie Ruimte, Commissie Bestuur and Commissie Samenleving at 19:30 in three rooms
- THEN three meetings exist with that evening as their parent, each with its own empty agenda

#### Scenario: A session outside the evening
- GIVEN the same evening
- WHEN the griffier schedules a session at 23:30
- THEN the save is refused with a message naming the evening's times

### Requirement: REQ-PPS-002 The evening's page shows its sessions side by side

The page of a meeting with sessions SHALL show its sessions next to each other, each with its room, chair, time, lifecycle, first agenda items and live status, linking to the session. A session's page SHALL name its evening and link the sibling sessions.

#### Scenario: One overview of the evening
- GIVEN the three sessions of 3 November
- WHEN a council member opens the evening's page
- THEN he sees three columns, one per session, and opens Commissie Bestuur from its column

### Requirement: REQ-PPS-003 The calendar and the meetings list group sessions under their evening

The meetings calendar SHALL show an evening once, with its sessions inside it, and the meetings list SHALL filter on the evening.

#### Scenario: The calendar on 3 November
- GIVEN the evening and its three sessions
- WHEN the griffier opens the meetings calendar at 3 November
- THEN one event shows, holding the three sessions

### Requirement: REQ-PPS-004 Each session's broadcast names its evening for residents

When a session is broadcast, its broadcast SHALL carry the evening's title, and the portal's list of live and recent meetings SHALL show it, so residents find an evening's sessions together.

#### Scenario: A resident looks for tonight's committees
- GIVEN the three sessions are broadcast
- WHEN a resident opens the portal's live and recent meetings
- THEN the three broadcasts are listed together under "Commissieavond 3 november"
