# meeting-management Specification

**Status**: done
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Meeting management covers the full lifecycle of governance meetings for every
audience — councils, associations (ALV/ledenraad), corporate boards, and
operational teams — on the single universal `meeting` schema (CalDAV VEVENT,
ADR-002). Per ADR-006 a "board meeting" is not a separate entity: it is a
`meeting` with corporate-mode labels. This delta retires the parallel
`BoardMeeting` schema and folds the corporate meeting onto `meeting`.

## MODIFIED Requirements

### Requirement: Meeting Creation and Scheduling

The system MUST support creating meetings with a title, date/time, location (physical/digital/hybrid), governing body, and meeting type. Meetings MUST be stored as OpenRegister objects in the `decidesk` register using the `meeting` schema — for every audience, including corporate boards. There MUST NOT be a parallel `board-meeting` schema; corporate board meetings are `meeting` objects whose governing body has `bodyType=corporate-board`, with mode=corp label adaptation (per ADR-006). The system MUST support scheduling recurring meetings: a recurrence pattern (`frequency`, `interval`, `until`, `exceptions` — per meeting-series REQ-MSR-001) on a template meeting MUST be expandable into individual meeting instances sharing a series identifier, capped at 52 instances. The Meeting schema MUST expose Schema.org `eventAttendanceMode` and `virtualLocation` properties with property-level `x-openregister` schemaType annotations.

**Feature tier**: MVP

#### Scenario: Create a board meeting with physical location

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "Board Meeting Q1 2026", date "2026-04-15 14:00", location "Boardroom A", body "Board of Directors" (a `governance-body` with `bodyType=corporate-board`), type "regular"
- THEN the system MUST create a `meeting` OpenRegister object with `@type` set to `schema:Event` (NOT a `board-meeting` object)
- AND the `eventAttendanceMode` MUST be set to `schema:OfflineEventAttendanceMode`
- AND the meeting MUST appear in the universal meeting list

#### Scenario: Create a hybrid ALV meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "ALV 2026", type "general_assembly", and attendance mode "hybrid" with both physical address and video conference link
- THEN the `eventAttendanceMode` MUST be set to `schema:MixedEventAttendanceMode`
- AND both `location` (physical) and `virtualLocation` (video link) MUST be stored

#### Scenario: Schedule a recurring monthly meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with recurrence "monthly, every 2nd Tuesday at 14:00"
- THEN the system MUST generate individual meeting instances for the specified period
- AND each instance MUST be independently editable

#### Scenario: Generate a meeting series from a recurrence pattern

- GIVEN a meeting detail page with the Series tab open
- WHEN the user configures frequency "monthly", interval 1, and an until date, and triggers series generation
- THEN the system MUST show a preview of how many instances the pattern will create
- AND on generation each created instance MUST share the series identifier with the template meeting
- AND the instances MUST be listed in the Series tab sorted by scheduled date

#### Scenario: Series generation skips exception dates and caps instances

@e2e exclude backend date-expansion semantics; covered by MeetingSeriesServiceTest (PHPUnit)

- GIVEN a recurrence pattern with an exception date and an until date more than a year away
- WHEN the series is expanded
- THEN no instance MUST be created on the exception date
- AND at most 52 instances MUST be generated, with a warning logged when the cap truncates the series

#### Scenario: Meeting schema maps attendance mode and virtual location to Schema.org

@e2e exclude schema-level mapping; asserted by RegisterJsonTest (PHPUnit), no UI surface

- GIVEN the decidesk register configuration
- WHEN the Meeting schema is inspected
- THEN the `eventAttendanceMode` property MUST enumerate `schema:OfflineEventAttendanceMode`, `schema:OnlineEventAttendanceMode`, and `schema:MixedEventAttendanceMode` with an `x-openregister` schemaType annotation
- AND the `virtualLocation` property MUST carry the `schema:VirtualLocation` schemaType annotation

## REMOVED Requirements

### Requirement: Parallel corporate Board Meeting entity

**Reason**: Violates ADR-006 (one schema per concept). The `BoardMeeting`
schema duplicated `meeting`, with its own CalDAV bridge, views, and routes.
A board meeting is a meeting; storing them as different schemas guarantees
drift.

**Migration**: Corporate board meetings are represented as `meeting` objects
(CalDAV VEVENT, ADR-002) whose governing body has `bodyType=corporate-board`.
A corporate `meeting` is re-seeded by this change (slug
`rvc-vergadering-2025-q2`). The `BoardMeeting` schema and seeds, the
`boardMeeting#*` routes, `BoardMeetingController`, `BoardMeetingService`,
`BoardCalDavSyncService`, the `BoardMeetingCalDavBridge` listener, and the
`BoardMeetingList`/`BoardMeetingDetail` Vue views are deleted; CalDAV sync is
subsumed by the universal `meeting` path (see design.md "Reference cleanup").

### Requirement: Parallel corporate Board Material entity

**Reason**: Violates ADR-006. `BoardMaterial` duplicated generic document
attachments for the corporate audience.

**Migration**: Corporate board materials become generic DigitalDocument
attachments on the `meeting`. The `BoardMaterial` schema and seeds, the
`boardMaterial#*` routes, `BoardMaterialController`, and
`BoardMaterialAuthorizationService` are deleted (apply may retarget the
authorization logic onto generic attachments per design.md).

## Acceptance Criteria

- [ ] No `BoardMeeting` or `BoardMaterial` schema remains in the register.
- [ ] A corporate `meeting` seed exists (`rvc-vergadering-2025-q2`).
- [ ] No `BoardMeetingCalDavBridge` listener is registered; meeting CalDAV sync uses the universal `meeting` path.

## Notes

Related ADRs: ADR-006 (mode adaptation), ADR-002 (CalDAV-first storage),
ADR-004 (six-item nav).
