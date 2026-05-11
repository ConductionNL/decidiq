# Proposal: Meeting Management

## Why

Meeting management is the operational heartbeat of governance—councils, boards, and assemblies depend on reliable scheduling, attendance tracking, agenda organization, and lifecycle management to conduct business. Without these capabilities, organizations cannot conduct valid meetings or maintain compliance with governance regulations. This change establishes the core meeting infrastructure: scheduling, lifecycle states, attendance, speaking time, and materials management across all five governance domains (municipalities, water boards, corporate boards, NGO assemblies, and citizen participation forums).

## What Changes

- **Meeting lifecycle states**: Meetings transition through draft → scheduled → opened → paused → resumed → adjourned → closed with state machine enforcement
- **CalDAV-first architecture**: Meetings stored as VEVENT in Nextcloud Calendar with X-DECIDESK-* properties (lifecycle, meeting-type, meeting-mode, quorum-required, series, body-UID), not in OpenRegister. OpenRegister holds thin wrapper objects for relational queries only
- **Attendance tracking**: Record present/absent/late arrival for each participant at a meeting, with quorum calculation and validation
- **Speaking time management**: Track speaker queue, time per participant per agenda item, and enforce time limits
- **Meeting templates**: Reusable templates for recurring meeting types with preset agenda items, structure, and governance rules
- **Meeting series**: Link related meetings (e.g., monthly board meetings, quarterly assemblies) with shared configuration
- **Meeting document attachments**: Attach supporting materials (PDFs, proposals, reports) to meetings and agenda items
- **Hybrid participation**: Support in-person, digital, and hybrid (both) participation modes per meeting
- **Governance body configuration**: Each governance body can customize meeting types, quorum rules, voting defaults, and workflow templates
- **OpenRegister wrapper objects**: Thin records in OpenRegister for relational queries (find agenda items for a meeting, find decisions from a meeting) without duplicating CalDAV data
- **CalDAV X-properties**: Store governance-specific metadata (lifecycle, type, mode, quorum, series, body reference) as X-DECIDESK-* properties per RFC 5545

## Capabilities

### New Capabilities

- `meeting-lifecycle`: Manage meeting state transitions (draft → scheduled → opened → paused → adjourned → closed) with validation rules
- `meeting-scheduling`: Schedule meetings with date/time, location, and recurrence rules
- `attendance-tracking`: Record participant attendance (present, absent, late) and calculate quorum status
- `speaking-time-management`: Track speaker queue, allocate speaking time per participant and agenda item, enforce time limits
- `meeting-templates`: Create and reuse meeting templates with preset agenda structure and governance rules
- `meeting-series`: Link related meetings with shared configuration and series identifier
- `meeting-materials`: Attach documents and materials to meetings and agenda items
- `participation-modes`: Support in-person, digital, and hybrid meeting participation
- `caldav-integration`: Store meetings as CalDAV VEVENT with X-DECIDESK-* custom properties; no sync layer
- `openregister-wrappers`: Maintain thin wrapper objects in OpenRegister for relational queries (agenda items, decisions, attendees)

### Modified Capabilities

None. All Meeting-related requirements are new in this phase.

## Impact

**Code & Architecture:**
- New CalDavService in `lib/Service/` to manage VEVENT creation/update/delete and X-property mapping
- Meeting mapper in `lib/Mapper/` for OpenRegister wrapper objects only (not for CalDAV data)
- MeetingController API endpoints: create, read, update, delete, list, state transitions
- CalDAV calendar lifecycle: one dedicated calendar per governance body, auto-created on first meeting
- OpenRegister Meeting schema defines only wrapper fields: `caldavUid`, `calendarId`, plus relations to AgendaItem, Motion, VotingRound, Minutes

**Data Layer:**
- Meetings stored in `nextcloud_calendarobjects` (CalDAV backend), NOT OpenRegister
- Attendance records stored in OpenRegister with relation to Meeting (via wrapper caldavUid)
- Speaking time records stored in OpenRegister with relation to Meeting + AgendaItem + Person
- AgendaItem stored in OpenRegister with relation to Meeting (via wrapper caldavUid)

**Frontend:**
- MeetingList.vue (schema-driven via CnIndexPage listing OpenRegister wrappers)
- MeetingDetail.vue (CnDetailPage with tabs: Overview, Attendees, Agenda, Speaking Time, Materials)
- AttendanceForm.vue (inline form for attendance tracking)
- SpeakerQueue.vue (visual queue component for speaking time management)
- MeetingTemplateDialog.vue (template selection/creation dialog)
- LifecycleStateMachine.vue (visual state transition control)

**APIs:**
- `POST /api/meetings` — Create meeting, returns caldavUid and OpenRegister wrapper ID
- `GET /api/meetings/{id}` — Fetch meeting details (CalDAV data merged with wrapper metadata)
- `PUT /api/meetings/{id}` — Update meeting (delegates to CalDAV service)
- `PUT /api/meetings/{id}/lifecycle` — State transition with validation
- `POST /api/meetings/{id}/attendance` — Record attendance
- `GET /api/meetings/{id}/attendees` — List attendance with quorum calculation
- `POST /api/meetings/{id}/speaker-queue` — Manage speaker order and time
- `GET /api/meetings/{id}/speaker-queue` — Get current speaker queue state
- `POST /api/meetings/{calendarId}/export` — Export meeting data as CSV, JSON, ICS

**Integrations:**
- Nextcloud Calendar app: meetings appear as native events
- Nextcloud Tasks app: action items from decisions appear as VTODOs
- ORI API endpoint: `/api/ori/v1/events` serializes meetings as ORI Meeting/Event objects

**Testing Requirements:**
- CalDAV round-trip: VEVENT created, X-properties preserved, read back correctly
- Workflow transitions: state machine validates all transition rules per governance domain
- Quorum calculation: test with various rules (absolute count, percentage, weighted voting)
- ORI export: verify meeting data conforms to ORI Meeting schema
- WCAG 2.1 AA: all voting interfaces (attendance, speaker queue, lifecycle buttons) must be keyboard-accessible and labeled

**Compliance & Standards:**
- Akoma Ntoso: meeting data exportable in Akoma Ntoso meeting format (deferred to future phase)
- Gemeentewet: quorum and vote weight rules configurable per municipality type
- Wet digitaal vergaderen: digital meeting requirements (encryption, recording consent, participant identification)
- ORI: meetings published via ORI API for public transparency
