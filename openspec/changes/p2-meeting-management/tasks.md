# Tasks: Meeting Management

## 1. Data Model & Schema Setup

- [ ] 1.1 Create OpenRegister Meeting wrapper schema (calendarId, caldavUid, meetingType, lifecycle fields)
- [ ] 1.2 Create OpenRegister Attendance schema (present/absent/late status, participant, timestamp)
- [ ] 1.3 Create OpenRegister SpeakingTime schema (speaker, duration, agendaItem reference, timestamp)
- [ ] 1.4 Define seed data: 3-5 realistic meetings per governance domain (municipality, water board, corporate, NGO, citizen participation)
- [ ] 1.5 Create migration for seed data import via ConfigurationService

## 2. CalDAV Service & Integration

- [ ] 2.1 Implement CalDavService: VEVENT create, read, update, delete via sabre/dav
- [ ] 2.2 Implement X-DECIDESK-* property serialization/deserialization (lifecycle, meeting-type, meeting-mode, quorum-required, series, body-UID)
- [ ] 2.3 Create dedicated "DecideDesk" calendar per governance body on first meeting scheduled
- [ ] 2.4 Implement ATTENDEE management: sync Person/Membership entities to VEVENT ATTENDEE array
- [ ] 2.5 Test CalDAV round-trip: create VEVENT, read back, verify X-properties preserved (RFC 5545 compliance)
- [ ] 2.6 Implement iCalendar export endpoint returning valid ICS with X-properties intact

## 3. Backend Controllers & Services

- [ ] 3.1 Create MeetingController with routes: POST (create), GET (read), PUT (update), DELETE (remove), list
- [ ] 3.2 Implement MeetingService: delegates to CalDavService for VEVENT + OpenRegister for wrapper
- [ ] 3.3 Create LifecycleService: state machine with transitions (draft→scheduled→opened→paused→adjourned→closed)
- [ ] 3.4 Implement state validation: only chair/secretary can open; only chair can adjourn; etc.
- [ ] 3.5 Create AttendanceService: record attendance, calculate quorum (absolute count, percentage, weighted voting)
- [ ] 3.6 Implement quorum validation: check before transitioning meeting to "opened"
- [ ] 3.7 Create SpeakingTimeService: manage speaker queue, allocate time, track overages
- [ ] 3.8 Implement MeetingTemplateService: clone templates, apply to new meetings
- [ ] 3.9 Create MeetingSeriesService: link meetings, share configuration, auto-schedule recurrences
- [ ] 3.10 Implement meeting materials (documents) upload/download via FileService

## 4. OpenRegister Integration & Mappers

- [ ] 4.1 Create MeetingMapper: read/write OpenRegister wrapper objects (calendarId, caldavUid, relations)
- [ ] 4.2 Create AttendanceMapper: read/write Attendance objects with participant relations
- [ ] 4.3 Create SpeakingTimeMapper: read/write SpeakingTime objects with speaker/agendaItem relations
- [ ] 4.4 Verify schema definitions via ObjectService: all required relations (GovernanceBody, AgendaItem, Motion, Minutes)
- [ ] 4.5 Test OpenRegister wrapper queries: find all agenda items for meeting, find all decisions from meeting

## 5. API Endpoints & Validation

- [ ] 5.1 Implement `POST /api/meetings` with OpenRegister wrapper + CalDAV VEVENT creation
- [ ] 5.2 Implement `GET /api/meetings` with pagination, filtering (by body, type, lifecycle), search
- [ ] 5.3 Implement `GET /api/meetings/{id}` returning merged CalDAV + wrapper data
- [ ] 5.4 Implement `PUT /api/meetings/{id}` with CalDAV update + wrapper sync
- [ ] 5.5 Implement `DELETE /api/meetings/{id}` with CalDAV event + wrapper cleanup
- [ ] 5.6 Implement `PUT /api/meetings/{id}/lifecycle` with state validation
- [ ] 5.7 Implement `POST /api/meetings/{id}/attendance` to record attendance
- [ ] 5.8 Implement `GET /api/meetings/{id}/attendees` with quorum calculation
- [ ] 5.9 Implement `POST /api/meetings/{id}/speaker-queue` to manage speakers
- [ ] 5.10 Implement `GET /api/meetings/{id}/speaker-queue` with time tracking
- [ ] 5.11 Add `@spec` PHPDoc tags to all classes/methods linking to this change's tasks
- [ ] 5.12 Test all endpoints: happy path + error cases (403 forbidden, 404 not found, 400 validation)

## 6. Frontend: List & Detail Views

- [ ] 6.1 Create MeetingList.vue using CnIndexPage (schema-driven, sortable, filterable)
- [ ] 6.2 Create MeetingDetail.vue using CnDetailPage with tabs: Overview, Attendees, Agenda, Speaking Time, Materials
- [ ] 6.3 Implement MeetingCard.vue for dashboard/card grid views
- [ ] 6.4 Create MainMenu navigation with "Meetings" link
- [ ] 6.5 Add router routes: `/meetings` (list), `/meetings/:id` (detail), `/meetings/new` (create)
- [ ] 6.6 Create MeetingFormDialog.vue for create/edit (schema-driven via CnFormDialog)
- [ ] 6.7 Add SPDX headers to all Vue files

## 7. Frontend: Attendance & Speaking Time

- [ ] 7.1 Create AttendanceForm.vue inline form (present/absent/late picker + submit)
- [ ] 7.2 Implement AttendanceList.vue with participant names, status, quorum progress bar
- [ ] 7.3 Create SpeakerQueue.vue visual queue component (drag-reorder speakers, time tracker)
- [ ] 7.4 Implement SpeakerQueueForm.vue for adding/removing speakers
- [ ] 7.5 Add real-time speaker time countdown display
- [ ] 7.6 Create QuorumStatus.vue badge showing met/not-met with participant count

## 8. Frontend: Lifecycle & Templates

- [ ] 8.1 Create LifecycleStateMachine.vue showing current state + valid transitions as buttons
- [ ] 8.2 Implement state transition validation on frontend (show/hide buttons based on permissions)
- [ ] 8.3 Create MeetingTemplateDialog.vue for selecting/creating templates
- [ ] 8.4 Implement MeetingSeriesForm.vue for linking meetings and recurrence rules
- [ ] 8.5 Add lifecycle state badge to MeetingCard (colored: draft, scheduled, opened, closed)

## 9. Frontend: Materials & File Management

- [ ] 9.1 Create MeetingMaterials.vue tab using CnObjectSidebar files integration
- [ ] 9.2 Implement document upload widget (drag-drop, progress, preview)
- [ ] 9.3 Add "download all materials as ZIP" button
- [ ] 9.4 Implement document linking to agenda items (materials tab in AgendaItem detail)

## 10. Frontend: Store & State Management

- [ ] 10.1 Create meeting object store via createObjectStore (name: 'meeting')
- [ ] 10.2 Register meeting type in store/store.js with plugins: files, auditTrails, relations, lifecycle
- [ ] 10.3 Implement store actions: create, fetch, update, delete, transition lifecycle
- [ ] 10.4 Add error handling with try/catch + user feedback for all store calls
- [ ] 10.5 Implement useListView composable for meeting list (search, filter, sort, pagination)

## 11. Frontend: Translations & Accessibility

- [ ] 11.1 Create l10n/en.json with all meeting-related strings (sentence case)
- [ ] 11.2 Create l10n/nl.json with Dutch translations (matching keys)
- [ ] 11.3 Verify all user-visible strings use t('decidesk', 'text') pattern
- [ ] 11.4 Test WCAG 2.1 AA: keyboard navigation (Tab/Arrow keys) for all controls
- [ ] 11.5 Test WCAG 2.1 AA: all form labels associated (for="" attributes)
- [ ] 11.6 Verify color is not sole method of conveying status (use icons + labels, not color alone)
- [ ] 11.7 Test responsive design: 320px (mobile), 768px (tablet), 1920px (desktop)

## 12. ORI API & Integration

- [ ] 12.1 Create OriController endpoint `GET /api/ori/v1/events` returning ORI Event/Meeting objects
- [ ] 12.2 Implement Meeting → ORI Event serialization (map lifecycle to ORI status, etc.)
- [ ] 12.3 Add filtering: by governance body, date range, lifecycle state
- [ ] 12.4 Test ORI export: verify output conforms to ORI Meeting schema (open-raadsinformatie.nl)

## 13. Testing & Validation

- [ ] 13.1 Create PHPUnit test for CalDavService (VEVENT create, X-properties, round-trip)
- [ ] 13.2 Create PHPUnit test for LifecycleService (all state transitions + invalid transitions rejected)
- [ ] 13.3 Create PHPUnit test for AttendanceService (quorum calculation: absolute, percentage, weighted)
- [ ] 13.4 Create PHPUnit test for MeetingController endpoints (happy path + error cases)
- [ ] 13.5 Create Postman/Newman test collection for all API endpoints (CRUD, lifecycle, attendance)
- [ ] 13.6 Create browser test (Playwright) for meeting list/detail/create/edit/delete workflow
- [ ] 13.7 Create browser test for attendance tracking workflow (add attendees, mark present/absent, verify quorum)
- [ ] 13.8 Create browser test for speaker queue workflow (add speakers, allocate time, track overages)
- [ ] 13.9 Test OpenRegister schema validation: wrapper object creation with all required fields
- [ ] 13.10 Test workflow transitions for all governance domains: municipal, water board, corporate, NGO, citizen participation
- [ ] 13.11 Verify ORI API output matches specification (field names, data types, required fields)
- [ ] 13.12 Run accessibility tests: keyboard-only navigation, WCAG AA compliance report

## 14. Deduplication & Code Quality

- [ ] 14.1 Audit existing OpenRegister services: ObjectService, RegisterService, SchemaService, ConfigurationService
- [ ] 14.2 Verify no duplication with existing shared components (@conduction/nextcloud-vue)
- [ ] 14.3 Document which OpenRegister services are leveraged (ObjectService, FileService, etc.)
- [ ] 14.4 Run code style checks: `composer check:strict`, `npm run lint`
- [ ] 14.5 Add SPDX headers to all PHP files (`// SPDX-License-Identifier: EUPL-1.2`)

## 15. Documentation & Release

- [ ] 15.1 Create docs/meeting-management.md with feature overview, screenshots, and usage guide
- [ ] 15.2 Document meeting lifecycle state machine (visual diagram + transition rules per domain)
- [ ] 15.3 Document CalDAV architecture decisions: why CalDAV-first, X-property usage, wrapper pattern
- [ ] 15.4 Document API endpoints in docs/api/meetings.md (request/response examples)
- [ ] 15.5 Document ORI compatibility in docs/ori-export.md
- [ ] 15.6 Verify seed data loads correctly on app install
- [ ] 15.7 Smoke test app deployment: fresh install, verify calendar created, meetings appear in Calendar app
