# meeting-management Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Retires the parallel corporate `BoardMeeting` entity (and its routes, DI, and
CalDAV bridge) from the meeting-management capability. Per ADR-006 a corporate
board meeting is the universal `meeting` (CalDAV VEVENT per ADR-002) with
mode=corp labels — never a separate schema.

## REMOVED Requirements

### Requirement: Parallel corporate board meeting entity
The parallel `BoardMeeting` schema (slug `board-meeting`), the
`BoardMeetingList` / `BoardMeetingDetail` Vue views, the
`BoardMeetingCreateModal`, the board-meeting routes (`boardMeeting#*`,
`board#*`, `boardMember#*`, `boardVote#*`, `boardMaterial#*`,
`resolution#*`), the board-meeting controller/service, and the
`BoardMeetingCalDavBridge` listener are REMOVED. A corporate board meeting is a
universal `meeting`; its CalDAV sync uses the universal `meeting` path
(ADR-002).

#### Scenario: No parallel board-meeting schema or routes
- GIVEN the register is imported and the app boots
- WHEN the schemas and routes are inspected
- THEN no `board-meeting` schema and no `boardMeeting#*` / `board#*` route exist
- AND the app boots without a fatal (no DI registration references a deleted board class)

#### Scenario: Board meeting CalDAV sync via the universal meeting path
- GIVEN a corporate `meeting` is created
- WHEN it is persisted
- THEN CalDAV sync occurs via the universal `meeting` listener (no `BoardMeetingCalDavBridge`)

## Acceptance Criteria

- [ ] No `board-meeting` schema remains; no board-meeting routes remain
- [ ] `BoardMeetingCalDavBridge` listener and its DI/event registration are removed
- [ ] The app boots without a fatal after the deletions
