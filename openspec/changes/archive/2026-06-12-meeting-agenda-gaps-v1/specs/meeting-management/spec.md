# Delta: meeting-management — meeting+agenda gaps v1

## MODIFIED Requirements

### Requirement: Meeting Creation and Scheduling

The system MUST support creating meetings with a title, date/time, location (physical/digital/hybrid), governing body, and meeting type. Meetings MUST be stored as OpenRegister objects in the `decidesk` register using the `meeting` schema. The system MUST support scheduling recurring meetings: a recurrence pattern (`frequency`, `interval`, `until`, `exceptions` — per meeting-series REQ-MSR-001) on a template meeting MUST be expandable into individual meeting instances sharing a series identifier, capped at 52 instances. The Meeting schema MUST expose Schema.org `eventAttendanceMode` and `virtualLocation` properties with property-level `x-openregister` schemaType annotations.

**Feature tier**: MVP

#### Scenario: Create a board meeting with physical location

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "Board Meeting Q1 2026", date "2026-04-15 14:00", location "Boardroom A", body "Board of Directors", type "regular"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Event`
- AND the `eventAttendanceMode` MUST be set to `schema:OfflineEventAttendanceMode`
- AND the meeting MUST appear in the meeting list

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

### Requirement: Meeting Convocation and Notice

The system MUST support sending meeting convocations (uitnodigingen) to all members of the governing body within configurable notice periods. The system MUST track delivery status per recipient: sending a notice MUST record one delivery entry per board member (recipient, channel, status, send timestamp) on the meeting. The notice period MUST be configurable per meeting (`noticePeriodDays`, default 15) and the system MUST warn when the convocation is sent within 3 days of — or after — the statutory deadline.

**Feature tier**: MVP
**Legal reference**: BW 2:225 (42-day notice for NV, 15-day for BV), BW 2:38 (ALV notice per statutes)

#### Scenario: Send ALV convocation within statutory deadline

- GIVEN an ALV meeting scheduled for 2026-06-01 and a notice period of 15 days
- WHEN the secretary sends the convocation on 2026-05-10
- THEN the system MUST distribute the convocation to all voting members
- AND the system MUST record the send timestamp per recipient
- AND a warning MUST be shown if sending within 3 days of the deadline

#### Scenario: Include agenda and supporting documents in convocation

- GIVEN a meeting with a finalized agenda and attached documents
- WHEN the convocation is sent
- THEN the convocation MUST include the complete agenda
- AND links to all supporting documents MUST be included
- AND recipients MUST be able to access documents via the member portal

#### Scenario: Convocation records per-recipient delivery status

- GIVEN a board meeting in the scheduled state with board members
- WHEN the secretary sends the notice
- THEN the meeting MUST store one delivery entry per board member with recipient, channel, status, and send timestamp
- AND the meeting detail page MUST show the per-recipient delivery table after sending

#### Scenario: Warn when convocation is sent close to the statutory deadline

@e2e exclude time-dependent deadline arithmetic; pinned-clock coverage in BoardMeetingServiceTest::getNoticeDeadlineInfo (PHPUnit) + noticeRules vitest; the warning surface itself is asserted by the delivery-table e2e test

- GIVEN a meeting whose statutory notice deadline (scheduled date minus `noticePeriodDays`) is at most 3 days away
- WHEN the secretary sends the notice
- THEN the send result MUST include a warning that the convocation is sent within 3 days of the deadline
- AND sending after the deadline MUST produce an after-deadline warning instead
