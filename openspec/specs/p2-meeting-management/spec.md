---
status: done
---

# p2-meeting-management Specification

## Purpose
Manages the full lifecycle of governance meetings, from scheduling and templating through opening, pausing, adjourning, and closing, with server-side state-machine and role-based authorization enforcement. Meetings are stored as CalDAV VEVENTs in a dedicated calendar per governance body alongside thin OpenRegister wrapper objects, and the capability covers quorum gating, attendance tracking, speaker-queue and speaking-time management, meeting series, document attachments, and an ORI-compatible events endpoint.

## Requirements

<!-- Schema.org: schema:Event + custom:MeetingLifecycle -->
### Requirement: REQ-ML-001 — Meeting lifecycle state machine

The system SHALL enforce a meeting lifecycle state machine with the following valid states:
`draft`, `scheduled`, `opened`, `paused`, `adjourned`, `closed`. State transitions SHALL
be validated server-side by `LifecycleService`. Frontend action buttons SHALL reflect only
the valid next states from the current state. The state SHALL be persisted as
`X-DECIDESK-LIFECYCLE` in the CalDAV VEVENT and mirrored on the OpenRegister wrapper
(`lifecycle` field). All state changes SHALL be recorded in OpenRegister's audit trail.

**Valid transitions:**

| Action | From states | To state |
|--------|-------------|----------|
| schedule | draft | scheduled |
| open | scheduled, adjourned | opened |
| pause | opened | paused |
| resume | paused | opened |
| adjourn | opened, paused | adjourned |
| close | opened, paused, adjourned, scheduled | closed |

#### Scenario: Schedule a draft meeting
- **GIVEN** a meeting in `draft` state
- **WHEN** an authorised user sends `POST /api/meetings/{id}/lifecycle` with `{"action": "schedule"}`
- **THEN** the meeting transitions to `scheduled`, the VEVENT `X-DECIDESK-LIFECYCLE` property is updated to `scheduled`, and an audit trail entry is created

#### Scenario: Open a scheduled meeting
- **GIVEN** a meeting in `scheduled` state with quorum confirmed
- **WHEN** a chair user sends `POST /api/meetings/{id}/lifecycle` with `{"action": "open"}`
- **THEN** the meeting transitions to `opened` and the `X-DECIDESK-LIFECYCLE` property reads `opened`

#### Scenario: Reject invalid transition
- **GIVEN** a meeting in `closed` state
- **WHEN** any user sends `POST /api/meetings/{id}/lifecycle` with `{"action": "open"}`
- **THEN** the server returns HTTP 422 with a descriptive error message and the meeting remains `closed`

#### Scenario: Pause an open meeting
- **GIVEN** a meeting in `opened` state
- **WHEN** the chair sends `POST /api/meetings/{id}/lifecycle` with `{"action": "pause"}`
- **THEN** the meeting transitions to `paused`

#### Scenario: Resume a paused meeting
- **GIVEN** a meeting in `paused` state
- **WHEN** the chair sends `POST /api/meetings/{id}/lifecycle` with `{"action": "resume"}`
- **THEN** the meeting transitions to `opened`

#### Scenario: Adjourn a meeting
- **GIVEN** a meeting in `opened` or `paused` state
- **WHEN** the chair sends `POST /api/meetings/{id}/lifecycle` with `{"action": "adjourn"}`
- **THEN** the meeting transitions to `adjourned`

#### Scenario: Close a meeting from multiple valid states
- **GIVEN** a meeting in `scheduled`, `opened`, `paused`, or `adjourned` state
- **WHEN** the chair or secretary sends `POST /api/meetings/{id}/lifecycle` with `{"action": "close"}`
- **THEN** the meeting transitions to `closed` (terminal — no further transitions allowed)

---

<!-- Schema.org: schema:Role; OCP: \OCP\IGroupManager, AuthorizationService -->
### Requirement: REQ-ML-002 — Role-based lifecycle authorization

The system SHALL enforce role-based authorization for lifecycle transitions. Only users
with the `chair` role in the relevant GovernanceBody SHALL be authorized to execute
`open`, `pause`, `resume`, and `adjourn` transitions. Users with `secretary` or `chair`
roles SHALL be authorized to execute `schedule` and `close` transitions. Unauthorized
transition attempts SHALL return HTTP 403. Role authorization SHALL be checked on the
backend using `AuthorizationService` — never validated on the frontend alone.

#### Scenario: Non-chair attempts to open meeting
- **GIVEN** a meeting in `scheduled` state and an authenticated user with role `member`
- **WHEN** the user sends `POST /api/meetings/{id}/lifecycle` with `{"action": "open"}`
- **THEN** the server returns HTTP 403 and the meeting remains `scheduled`

#### Scenario: Secretary schedules a meeting
- **GIVEN** a meeting in `draft` state and an authenticated user with role `secretary`
- **WHEN** the user sends `POST /api/meetings/{id}/lifecycle` with `{"action": "schedule"}`
- **THEN** the meeting transitions to `scheduled` successfully

---

<!-- Schema.org: schema:Event quorumRequired; ORI: Event.required_attendees -->
### Requirement: REQ-ML-003 — Quorum gate before opening

The system SHALL verify that quorum is met before allowing the `open` transition.
The quorum check SHALL compare the count of `present` + `late` Attendance records for
the meeting against the `quorumRequired` value on the Meeting and the quorum rule
configured on the GovernanceBody. If quorum is not met, the `open` action SHALL be
rejected with HTTP 422 and a human-readable message stating the shortfall.

#### Scenario: Open meeting with sufficient quorum
- **GIVEN** a meeting with `quorumRequired = 10` and 12 attendance records with status `present` or `late`
- **WHEN** the chair sends `POST /api/meetings/{id}/lifecycle` with `{"action": "open"}`
- **THEN** the meeting transitions to `opened`

#### Scenario: Reject open when quorum not met
- **GIVEN** a meeting with `quorumRequired = 10` and only 8 attendance records with status `present`
- **WHEN** the chair sends `POST /api/meetings/{id}/lifecycle` with `{"action": "open"}`
- **THEN** the server returns HTTP 422 with a message indicating 2 more members are required and the meeting remains `scheduled`

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: meeting-scheduling                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:Event; CalDAV: VEVENT RFC 5545; ORI: Event.start_date, Event.location -->
### Requirement: REQ-MS-001 — Create and store a meeting as CalDAV VEVENT

The system SHALL store each meeting as a CalDAV VEVENT in the governance body's dedicated
Nextcloud Calendar (`decidesk-{bodySlug}`). The calendar SHALL be created automatically
on the first meeting scheduled for a governance body. The VEVENT SHALL contain at minimum:
`SUMMARY` (title), `DTSTART` (scheduledDate), `DTEND` (endDate), `LOCATION`, `DESCRIPTION`,
and all applicable `X-DECIDESK-*` custom properties. An OpenRegister wrapper object SHALL
be created simultaneously, storing `caldavUid`, `calendarId`, and governance relations.

#### Scenario: Create a new meeting
- **GIVEN** a governance body exists and no meeting calendar exists yet
- **WHEN** a secretary sends `POST /api/meetings` with title, scheduledDate, location, meetingType, meetingMode, and bodyId
- **THEN** a VEVENT is created in a new `decidesk-{bodySlug}` calendar with the correct SUMMARY, DTSTART, and X-DECIDESK-* properties; an OpenRegister wrapper is created; and the response includes `caldavUid` and wrapper `id`

#### Scenario: Calendar auto-created on first meeting
- **GIVEN** no calendar exists for governance body `gem-utrecht`
- **WHEN** the first meeting for that body is created via `POST /api/meetings`
- **THEN** a CalDAV calendar named `Decidesk — Gemeente Utrecht` with slug `decidesk-gem-utrecht` is created and the VEVENT is placed in it

#### Scenario: Update meeting date and location
- **GIVEN** an existing meeting in `draft` or `scheduled` state
- **WHEN** a secretary sends `PUT /api/meetings/{id}` with updated `scheduledDate` and `location`
- **THEN** the VEVENT `DTSTART` and `LOCATION` are updated, the OpenRegister wrapper `scheduledDate` is patched, and the CalDAV `caldavUid` is unchanged

#### Scenario: List meetings with filtering
- **GIVEN** multiple meetings exist for different governance bodies and lifecycle states
- **WHEN** a user sends `GET /api/meetings?bodyId=X&lifecycle=scheduled&_page=1&_limit=20`
- **THEN** the response contains only `scheduled` meetings for body `X` with correct pagination fields (`total`, `page`, `pages`)

---

<!-- Schema.org: schema:Event; CalDAV: DELETE VEVENT -->
### Requirement: REQ-MS-002 — Delete meeting removes VEVENT and wrapper

The system SHALL delete both the CalDAV VEVENT and the OpenRegister wrapper object when
a meeting is deleted. Deletion SHALL only be permitted for meetings in `draft` or `scheduled`
state. Deletion of meetings in `opened`, `paused`, `adjourned`, or `closed` state SHALL
return HTTP 422. Related Attendance and SpeakingTime objects SHALL be soft-deleted via
OpenRegister's cascade delete.

#### Scenario: Delete a draft meeting
- **GIVEN** a meeting in `draft` state
- **WHEN** a secretary sends `DELETE /api/meetings/{id}`
- **THEN** the VEVENT is removed from the CalDAV calendar and the OpenRegister wrapper is deleted

#### Scenario: Reject deletion of opened meeting
- **GIVEN** a meeting in `opened` state
- **WHEN** a user sends `DELETE /api/meetings/{id}`
- **THEN** the server returns HTTP 422 and the meeting is unchanged

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: attendance-tracking                                         -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:Event → attendee; Popolo: Attendance; ORI: Event.invitees -->
### Requirement: REQ-AT-001 — Record participant attendance

The system SHALL allow authorised users to record attendance for each expected participant
at a meeting. Each `Attendance` record SHALL store: `attendanceStatus` (present / absent /
late), `arrivalTime`, `departureTime`, `proxyFor` (optional Person reference for proxy
delegation), and `note`. The `Attendance` object SHALL relate to a `Person`, a Meeting
wrapper (via `caldavUid`), and optionally to an `AgendaItem` (for item-level presence
tracking). Attendance records SHALL be stored in OpenRegister, not in the CalDAV ATTENDEE
component.

#### Scenario: Mark participant as present
- **GIVEN** a meeting in `scheduled` state and a registered participant
- **WHEN** a secretary sends `POST /api/meetings/{id}/attendance` with `{"personId": "...", "attendanceStatus": "present", "arrivalTime": "2026-04-16T19:25:00+02:00"}`
- **THEN** an Attendance record is created with status `present` and related to the meeting wrapper and person

#### Scenario: Mark participant as late
- **GIVEN** a meeting in `opened` state and a participant who arrived after opening
- **WHEN** a secretary sends `POST /api/meetings/{id}/attendance` with `{"personId": "...", "attendanceStatus": "late", "arrivalTime": "2026-04-16T19:47:00+02:00", "note": "Vertraging OV"}`
- **THEN** an Attendance record is created with status `late` and the note is persisted

#### Scenario: Record proxy delegation
- **GIVEN** a meeting and participant A who has authorized participant B as their proxy
- **WHEN** a secretary sends `POST /api/meetings/{id}/attendance` with `{"personId": "B", "attendanceStatus": "present", "proxyFor": "A"}`
- **THEN** participant B's Attendance record includes `proxyFor: A`, counting A's voting weight toward quorum if weighted voting is configured

#### Scenario: List attendees with quorum status
- **GIVEN** a meeting with 15 attendance records (12 present, 2 late, 1 absent) and `quorumRequired = 10`
- **WHEN** a user sends `GET /api/meetings/{id}/attendees`
- **THEN** the response includes all 15 attendance records and a `quorumStatus: {met: true, present: 14, required: 10}` summary

---

<!-- Schema.org: schema:Event quorumRequired; Gemeentewet Art. 19 -->
### Requirement: REQ-AT-002 — Configurable quorum calculation

The system SHALL support three quorum calculation methods configurable per GovernanceBody
via `quorumRule`:
- **Absolute count**: quorum met when `present + late >= quorumRequired` (integer)
- **Percentage**: quorum met when `(present + late) / totalMembers >= threshold` (e.g. 0.50)
- **Weighted**: quorum met when sum of `votingWeight` of present+late members >= threshold

The `AttendanceService` SHALL evaluate the appropriate formula at runtime based on the
body's `quorumRule` configuration. The quorum status SHALL be returned in
`GET /api/meetings/{id}/attendees`.

#### Scenario: Absolute count quorum met
- **GIVEN** a meeting with `quorumRequired = 7` and absolute-count rule, with 8 present members
- **WHEN** `AttendanceService.calculateQuorum()` is called
- **THEN** the result is `{met: true, present: 8, required: 7, method: "absolute"}`

#### Scenario: Percentage quorum not met
- **GIVEN** a meeting with percentage rule (50%), 20 total members, and only 9 present
- **WHEN** `AttendanceService.calculateQuorum()` is called
- **THEN** the result is `{met: false, present: 9, threshold: 0.50, actual: 0.45, method: "percentage"}`

#### Scenario: Weighted quorum with proxy
- **GIVEN** a meeting with weighted-voting rule, 3 present members each with `votingWeight = 2` and 1 proxy vote with `votingWeight = 1`, threshold = 7
- **WHEN** `AttendanceService.calculateQuorum()` is called
- **THEN** the result is `{met: true, totalWeight: 7, threshold: 7, method: "weighted"}`

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: speaking-time-management                                    -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: opengov:Speech; Popolo: Speech.start_date, Speech.end_date -->
### Requirement: REQ-ST-001 — Speaker queue management

The system SHALL maintain an ordered speaker queue per agenda item per meeting.
`POST /api/meetings/{id}/speaker-queue` SHALL add a speaker (Person reference) to the
end of the queue for a specified agenda item with an allocated time in seconds.
`GET /api/meetings/{id}/speaker-queue` SHALL return the current queue state including
each speaker's name, allocated time, used time, and overtime flag. Speakers SHALL be
removable from the queue while they have not yet spoken.

#### Scenario: Add speaker to queue
- **GIVEN** a meeting in `opened` state with an agenda item in progress
- **WHEN** a clerk sends `POST /api/meetings/{id}/speaker-queue` with `{"personId": "...", "agendaItemId": "...", "allocatedSeconds": 180}`
- **THEN** a SpeakingTime record is created with status `queued`, `allocatedSeconds = 180`, `usedSeconds = 0`

#### Scenario: Get speaker queue
- **GIVEN** a meeting with 3 speakers in the queue for agenda item `item-001`
- **WHEN** a user sends `GET /api/meetings/{id}/speaker-queue?agendaItemId=item-001`
- **THEN** the response lists all 3 speakers in queue order with their allocated seconds and queue position

#### Scenario: Remove speaker from queue before speaking
- **GIVEN** a speaker in the queue who has not yet spoken
- **WHEN** a clerk sends `DELETE /api/meetings/{id}/speaker-queue/{speakerEntryId}`
- **THEN** the SpeakingTime record is removed and the queue is renumbered

---

<!-- Schema.org: opengov:Speech startDate/endDate; ORI: SpeechActivity -->
### Requirement: REQ-ST-002 — Speaking time tracking and overtime detection

The system SHALL record the start and end times of each speech via
`PUT /api/meetings/{id}/speaker-queue/{entryId}` with `{"action": "start"}` or
`{"action": "stop"}`. On stop, the system SHALL calculate `usedSeconds` and set
`isOvertime = true` if `usedSeconds > allocatedSeconds`. `SpeakingTimeService` SHALL
enforce a configurable hard stop: if `hardStop = true` on the GovernanceBody, the
system SHALL emit a notification when `usedSeconds == allocatedSeconds`.

#### Scenario: Start and stop speaker timer
- **GIVEN** a speaker entry in the queue with `allocatedSeconds = 180`
- **WHEN** a clerk sends start, then stop after 162 seconds have elapsed
- **THEN** `usedSeconds = 162`, `isOvertime = false`, `startedAt` and `endedAt` are recorded

#### Scenario: Detect overtime
- **GIVEN** a speaker entry with `allocatedSeconds = 180`
- **WHEN** the clerk stops the timer after 207 seconds
- **THEN** `usedSeconds = 207`, `isOvertime = true`

#### Scenario: Hard stop notification
- **GIVEN** a GovernanceBody with `hardStop = true` and a speaker with `allocatedSeconds = 180`
- **WHEN** `usedSeconds` reaches 180
- **THEN** `NotificationService` emits a notification to the chair and clerk that the speaker's time has expired

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: meeting-templates                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:Event → based on template; custom:MeetingTemplate -->
### Requirement: REQ-MTE-001 — Create and apply meeting templates

The system SHALL allow authorised users to create MeetingTemplate objects in OpenRegister
with the following fields: `name`, `meetingType`, `quorumRule`, `defaultAgendaItems`
(ordered list of agenda item titles). When applying a template to a new meeting,
`MeetingTemplateService` SHALL generate a new VEVENT, wrapper object, and pre-populated
AgendaItem records based on the template's `defaultAgendaItems` list. The template itself
SHALL NOT be a CalDAV event.

#### Scenario: Create a meeting template
- **GIVEN** an authenticated secretary
- **WHEN** the user sends `POST /api/meeting-templates` with `{"name": "Reguliere raadsvergadering", "meetingType": "regular", "quorumRule": {"method": "absolute", "required": 23}, "defaultAgendaItems": ["Opening", "Vaststellen agenda", "Mededelingen", "Rondvraag", "Sluiting"]}`
- **THEN** a MeetingTemplate object is created in OpenRegister and returned with an `id`

#### Scenario: Apply template to new meeting
- **GIVEN** a MeetingTemplate with 5 default agenda items
- **WHEN** a user creates a meeting via `POST /api/meetings` with `{"templateId": "...", "scheduledDate": "...", ...}`
- **THEN** a VEVENT is created, an OpenRegister wrapper is created, and 5 AgendaItem objects are created pre-linked to the meeting in order

#### Scenario: List available templates
- **GIVEN** 3 meeting templates exist for a governance body
- **WHEN** a user sends `GET /api/meeting-templates?bodyId={id}`
- **THEN** the response lists all 3 templates with name, meetingType, and item count

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: meeting-series                                              -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:EventSeries; CalDAV: RRULE RFC 5545 §3.8.5.3 -->
### Requirement: REQ-MSE-001 — Link meetings into a series

The system SHALL support grouping related meetings into a named series. A MeetingSeries
object in OpenRegister SHALL store `name`, `seriesIdentifier` (slug), `rrule` (iCalendar
RRULE string), shared `quorumRule`, and shared `templateId`. All meetings belonging to a
series SHALL reference the `series` identifier in the OpenRegister wrapper and in
`X-DECIDESK-SERIES` on the VEVENT. `MeetingSeriesService` SHALL use CalDAV RRULE for
recurrence. Individual occurrence wrappers SHALL be created lazily on first access.

#### Scenario: Create a meeting series
- **GIVEN** a governance body with monthly council meetings
- **WHEN** a secretary sends `POST /api/meeting-series` with `{"name": "Maandelijkse raadsvergadering 2026", "rrule": "FREQ=MONTHLY;BYDAY=3TH;COUNT=12", "templateId": "..."}`
- **THEN** a MeetingSeries object is created in OpenRegister and a master VEVENT with the RRULE is created in CalDAV

#### Scenario: Access a series occurrence creates wrapper
- **GIVEN** a series with RRULE defining 12 occurrences and no wrapper yet for occurrence 3
- **WHEN** a user requests `GET /api/meetings?series=raadsvergadering-2026&occurrence=3`
- **THEN** an OpenRegister wrapper is created lazily for that occurrence and returned

#### Scenario: List all meetings in a series
- **GIVEN** a series with 12 occurrences (3 wrappers created, 9 pending)
- **WHEN** a user sends `GET /api/meetings?series=raadsvergadering-2026`
- **THEN** all 12 occurrence dates are returned; wrappers show full data, non-created occurrences show date and series metadata only

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: meeting-materials                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:DigitalDocument; OCP: \OCP\Files\IAppData; FileService -->
### Requirement: REQ-MMA-001 — Attach documents to meetings

The system SHALL support attaching supporting documents (PDFs, reports, proposals) to
meetings and agenda items using OpenRegister's `FileService`. Files SHALL be accessible
via `CnObjectSidebar → CnFilesTab` without any custom upload controller. Bulk download
of all meeting materials as a ZIP archive SHALL be available via `FileService.createObjectFilesZip()`.
Text extraction from attached PDFs SHALL be handled automatically by
`TextExtractionService`.

#### Scenario: Attach a document to a meeting
- **GIVEN** a meeting in `scheduled` state and a PDF agenda pack
- **WHEN** a secretary uploads the file via the Files tab in MeetingDetail.vue
- **THEN** the file is stored via `FileService`, linked to the meeting wrapper in OpenRegister, and visible in `CnFilesTab`

#### Scenario: Download all materials as ZIP
- **GIVEN** a meeting with 4 attached documents
- **WHEN** a user clicks "Download all materials" in MeetingDetail.vue
- **THEN** `FileService.createObjectFilesZip()` is called and a ZIP archive containing all 4 files is returned

#### Scenario: Text extracted from uploaded PDF
- **GIVEN** a PDF agenda attached to a meeting
- **WHEN** the attachment is created
- **THEN** `TextExtractionService` automatically extracts the text content and indexes it for full-text search via `IndexService`

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: participation-modes                                         -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: schema:Event eventAttendanceMode; Wet digitaal vergaderen -->
### Requirement: REQ-PMD-001 — Support in-person, digital, and hybrid participation

The system SHALL support three participation modes for meetings, stored as
`X-DECIDESK-MEETING-MODE` in the VEVENT and `meetingMode` on the OpenRegister wrapper:
- `in-person`: physical location required; `LOCATION` field holds venue address
- `digital`: online only; `LOCATION` field holds video conference URL or platform reference
- `hybrid`: both physical and digital; `LOCATION` field holds both venue and conference URL

Meeting creation (`POST /api/meetings`) SHALL require `meetingMode`. MeetingDetail.vue
SHALL display participation mode prominently with an icon distinguishing each mode.
WCAG 2.1 AA: participation mode SHALL be conveyed via text label + icon (not color alone).

#### Scenario: Create digital meeting
- **GIVEN** a governance body configured for digital meetings (Wet digitaal vergaderen)
- **WHEN** a secretary creates a meeting with `meetingMode: "digital"` and `location: "https://talk.nextcloud.example.nl/room/raad-2026-04"`
- **THEN** the VEVENT has `X-DECIDESK-MEETING-MODE:digital` and `LOCATION` contains the video URL

#### Scenario: Create hybrid meeting
- **GIVEN** a governance body that supports hybrid attendance
- **WHEN** a meeting is created with `meetingMode: "hybrid"` and `location: "Stadhuis, Amstel 1, Amsterdam | https://meet.example.nl/raad"`
- **THEN** the VEVENT `LOCATION` field contains both the physical address and the video link

#### Scenario: Participation mode shown without color-only indicator
- **GIVEN** a meeting in `hybrid` mode displayed in MeetingCard.vue
- **WHEN** a screen reader navigates to the participation mode indicator
- **THEN** the indicator has an accessible label "Hybride bijeenkomst" and an icon with `aria-hidden="true"`, satisfying WCAG 2.1 AA criterion 1.4.1

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: caldav-integration                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- CalDAV: RFC 5545 §3.8.8.2; sabre/vobject; \OCA\DAV\CalDAV\CalDavBackend -->
### Requirement: REQ-CAL-001 — VEVENT round-trip preserves X-DECIDESK-* properties

The system SHALL preserve all `X-DECIDESK-*` custom properties in the VEVENT ICS blob
across create, read, update, and delete operations. `CalDavService` SHALL use
`sabre/vobject` to parse and serialize ICS content. After any update to meeting metadata
(e.g. lifecycle transition), the `X-DECIDESK-LIFECYCLE` property SHALL be rewritten in
the ICS blob via `CalDavService`. `CalDavService` SHALL use
`\OCA\DAV\CalDAV\CalDavBackend::updateCalendarObject()` and
`CalDavBackend::getCalendarObjects()` via Nextcloud's dependency injection container.

#### Scenario: X-properties preserved on round-trip
- **GIVEN** a VEVENT created with `X-DECIDESK-LIFECYCLE:scheduled` and `X-DECIDESK-BODY-UID:{uuid}`
- **WHEN** `CalDavService.getEvent(caldavUid)` is called after creation
- **THEN** the returned VObject contains `X-DECIDESK-LIFECYCLE` with value `scheduled` and `X-DECIDESK-BODY-UID` with the original uuid

#### Scenario: Lifecycle transition updates VEVENT X-property
- **GIVEN** a meeting VEVENT with `X-DECIDESK-LIFECYCLE:scheduled`
- **WHEN** the meeting is transitioned to `opened` via `LifecycleService`
- **THEN** `CalDavService` rewrites the ICS blob with `X-DECIDESK-LIFECYCLE:opened` and `CalDavBackend.updateCalendarObject()` is called

#### Scenario: Export meeting as ICS file
- **GIVEN** a meeting with all X-DECIDESK-* properties set
- **WHEN** a user requests `POST /api/meetings/{calendarId}/export` with `{"format": "ics"}`
- **THEN** a valid ICS file is returned conforming to RFC 5545 with all X-DECIDESK-* properties included

---

<!-- CalDAV: \OCA\DAV\CalDAV\CalDavBackend; OCP: calendar management -->
### Requirement: REQ-CAL-002 — Dedicated CalDAV calendar per governance body

The system SHALL create one dedicated Nextcloud Calendar per governance body with the
naming convention `decidesk-{bodySlug}` (display name: `Decidesk — {bodyName}`).
The calendar SHALL be created automatically (lazy creation) when the first meeting for
that body is scheduled. Subsequent meetings for the same body SHALL use the existing
calendar. The calendar SHALL be visible in the Nextcloud Calendar app alongside personal
calendars.

#### Scenario: Calendar created on first meeting
- **GIVEN** a governance body with no existing calendar
- **WHEN** the first meeting is created via `POST /api/meetings` referencing that body
- **THEN** `CalDavService.ensureCalendar(bodySlug, bodyName)` creates a calendar named `decidesk-{bodySlug}` and the VEVENT is stored in it

#### Scenario: Second meeting reuses existing calendar
- **GIVEN** a governance body with an existing calendar `decidesk-gem-utrecht`
- **WHEN** a second meeting is created for the same body
- **THEN** `CalDavService.ensureCalendar()` detects the calendar exists and does NOT create a duplicate

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: openregister-wrappers                                       -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- Schema.org: meeting:Meeting; OpenRegister: ObjectService, relations -->
### Requirement: REQ-ORW-001 — OpenRegister wrapper objects for relational queries

The system SHALL maintain thin OpenRegister wrapper objects for each meeting. The wrapper
SHALL store only: `caldavUid`, `calendarId`, and OpenRegister relations to `GovernanceBody`,
`AgendaItem`, `Motion`, and `Minutes`. The wrapper SHALL NOT duplicate CalDAV fields
(`title`, `scheduledDate`, `location`, etc.). All relational queries (e.g. "find all
agenda items for meeting X", "find all decisions from meeting Y") SHALL be executed
against the OpenRegister wrapper using `ObjectService.findAll()` with relation filters.
The frontend (`MeetingList.vue`) SHALL query OpenRegister wrappers, not CalDAV directly.

#### Scenario: Find all agenda items for a meeting
- **GIVEN** a meeting wrapper with 5 related AgendaItem objects
- **WHEN** `ObjectService.findAll({schema: "AgendaItem", relation: {meetingId: wrapperId}})` is called
- **THEN** all 5 AgendaItem objects are returned

#### Scenario: Find all meetings for a governance body
- **GIVEN** 12 meeting wrappers with relation `governanceBodyId = body-001`
- **WHEN** `ObjectService.findAll({schema: "Meeting", relation: {governanceBodyId: "body-001"}, _page: 1, _limit: 10})` is called
- **THEN** 10 meeting wrappers are returned with `total: 12, page: 1, pages: 2`

#### Scenario: Wrapper created atomically with VEVENT
- **GIVEN** a valid meeting creation request
- **WHEN** `POST /api/meetings` is processed
- **THEN** both the CalDAV VEVENT and the OpenRegister wrapper are created in the same operation; if VEVENT creation fails, no wrapper is persisted (rollback via deletion if VEVENT fails after wrapper creation)

---

<!-- Schema.org: schema:Event; OCP: ObjectService + CalDavService -->
### Requirement: REQ-ORW-002 — Merged response from CalDAV and wrapper

`GET /api/meetings/{id}` SHALL return a merged response combining CalDAV VEVENT fields
(`title`, `scheduledDate`, `endDate`, `location`, `meetingMode`, `lifecycle` from
X-properties) with OpenRegister wrapper metadata (`id`, `caldavUid`, `calendarId`,
`relations`, audit fields). The `MeetingService.getMeeting(wrapperId)` method SHALL
fetch the wrapper via `ObjectService`, then fetch the VEVENT via `CalDavService` using
the stored `caldavUid`, and return the merged structure.

#### Scenario: Get meeting detail with merged fields
- **GIVEN** a meeting wrapper with `caldavUid = meeting-001@decidesk` and a corresponding VEVENT
- **WHEN** a user sends `GET /api/meetings/{wrapperId}`
- **THEN** the response includes `title`, `scheduledDate`, `location` (from VEVENT), `lifecycle` (from X-DECIDESK-LIFECYCLE), `id`, `caldavUid`, `relations` (from wrapper), and `createdAt`, `updatedAt` (from OpenRegister built-in fields)

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ORI API compatibility                                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<!-- ORI API: /api/ori/v1/events; Popolo: Event; ADR-003 -->
### Requirement: REQ-ORI-001 — ORI-compatible events endpoint

The system SHALL expose `GET /api/ori/v1/events` returning ORI-compatible Meeting/Event
objects. Each event SHALL include:
- `id` — meeting CalDAV UID
- `name` — VEVENT SUMMARY
- `start_date` — VEVENT DTSTART (ISO 8601)
- `end_date` — VEVENT DTEND (ISO 8601)
- `location` — VEVENT LOCATION
- `status` — mapped from X-DECIDESK-LIFECYCLE (`opened` → `active`, `closed` → `past`,
  `scheduled` → `confirmed`, `draft` → `tentative`)
- `organization` — GovernanceBody identifier (from X-DECIDESK-BODY-UID)
- `classification` — `meetingType` (from X-DECIDESK-MEETING-TYPE)

The endpoint SHALL support filtering by `organization`, `start_date[gte]`, and `status`.
The endpoint SHALL be publicly accessible (`#[PublicPage]`, `#[NoCSRFRequired]`). The
response SHALL include `@context`, `@type: "Meeting"` JSON-LD framing per ORI spec.

#### Scenario: List ORI events
- **GIVEN** 5 meetings in OpenRegister wrappers with CalDAV VEVENTs
- **WHEN** a public client sends `GET /api/ori/v1/events`
- **THEN** the response is a JSON-LD array with `@type: "Meeting"` objects, each containing `name`, `start_date`, `end_date`, `status`, and `organization`

#### Scenario: Filter ORI events by organisation
- **GIVEN** meetings for two governance bodies
- **WHEN** a client sends `GET /api/ori/v1/events?organization={bodyUuid}`
- **THEN** only meetings belonging to that governance body are returned

#### Scenario: Lifecycle status mapped to ORI status
- **GIVEN** a meeting with `X-DECIDESK-LIFECYCLE: opened`
- **WHEN** the event is serialized for the ORI endpoint
- **THEN** the ORI `status` field value is `active`
