# Tasks: Meeting Management — Core T1

## 1. Backend Setup and Schema

- [ ] 1.1 Register Meeting schema in OpenRegister via repair step; include all properties from ADR-000 (title, meetingType, scheduledDate, endDate, location, meetingMode, lifecycle, quorumRequired, series)
- [ ] 1.2 Create Meeting entity mapper class (MeetingMapper); implement CRUD methods mapping OpenRegister objects to PHP models
- [ ] 1.3 Create MeetingService with dependency injection; implement `create()`, `read()`, `update()`, `delete()` business logic
- [ ] 1.4 Create MeetingController with routes: GET /api/meetings, POST /api/meetings, GET /api/meetings/{id}, PUT /api/meetings/{id}, DELETE /api/meetings/{id}
- [ ] 1.5 Add `@spec openspec/changes/p2-meeting-management-core-t1/tasks.md` PHPDoc tags to all new classes and public methods

## 2. Meeting Lifecycle & Workflow

- [ ] 2.1 Create WorkflowService extending WorkflowEngineController; implement state validation for 5 governance domains (legislative, association, corporate, operations, citizen)
- [ ] 2.2 Define workflow transitions per domain: draft → scheduled → in-progress → (adjourned | completed); draft → cancelled allowed from any state
- [ ] 2.3 Create POST /api/meetings/{id}/actions/{action} endpoint; implement action handlers: schedule, start, adjourn, complete, cancel
- [ ] 2.4 Validate quorum before allowing meeting state transitions to in-progress
- [ ] 2.5 Implement audit trail integration: capture before/after snapshots on every state change and property update via AuditTrailService

## 3. Quorum Management

- [ ] 3.1 Create QuorumService; implement quorum calculation (fixed count or percentage based on GovernanceBody.quorumRule)
- [ ] 3.2 Implement `validateQuorum(meetingId): boolean` method; fetch meeting attendees via Membership relation and compare against requirement
- [ ] 3.3 Add quorum validation to meeting state transition logic (state cannot move to in-progress if quorum not met)
- [ ] 3.4 Expose quorum status in GET /api/meetings/{id} response: `{ quorumRequired, attendeeCount, quorumMet }`

## 4. Meeting List View (Frontend)

- [ ] 4.1 Create `src/store/modules/meetingStore.js` using createObjectStore('meetings', 'p2-meeting-management-core-t1', 'meetings-register'); add relations plugin
- [ ] 4.2 Create `src/views/MeetingList.vue` component using `CnIndexPage` + `useListView` composable
- [ ] 4.3 Implement meeting list filtering: by governanceBody (dropdown), by dateRange (date picker from/to), by lifecycle status (multi-select: draft/scheduled/in-progress/completed/cancelled)
- [ ] 4.4 Add meeting search: debounced title search via store's search plugin
- [ ] 4.5 Create `src/components/MeetingCard.vue` for card/grid view; display: title, date, body name, attendee count, status badge (color-coded by lifecycle)
- [ ] 4.6 Create `src/components/MeetingListTable.vue` for table view; columns: title, date, body, mode, attendees, status; sortable by date/title
- [ ] 4.7 Add "Add Meeting" button (CnActionsBar) → opens new meeting form via router to /meetings/new
- [ ] 4.8 Implement list pagination with useListView state management

## 5. Meeting Detail View (Frontend)

- [ ] 5.1 Create `src/views/MeetingDetail.vue` component; handle both view mode and edit mode (isNew flag)
- [ ] 5.2 Implement edit mode form using CnFormDialog (schema-driven via meetingStore schema); include: title, meetingType, scheduledDate, endDate, location, meetingMode, quorumRequired
- [ ] 5.3 Implement view mode using CnDetailPage + multiple CnDetailCard sections:
  - Section 1: Meeting header (title, date, body, mode, lifecycle with badge)
  - Section 2: Location & Schedule (location, scheduled start/end, duration)
  - Section 3: Attendees (list via related Membership; add/remove buttons)
  - Section 4: Agenda Items (related AgendaItems in table; add/reorder via buttons)
  - Section 5: Minutes (link to Minutes object if exists, or placeholder "Minutes pending")
- [ ] 5.4 Add sidebar via CnObjectSidebar; include: Files tab (agendas, docs), Notes tab, Audit tab (changes log), Tags tab
- [ ] 5.5 Add lifecycle action buttons in header: [Schedule], [Start], [Adjourn], [Complete], [Cancel] (buttons appear based on current state and allowed transitions)
- [ ] 5.6 Implement action button handlers; call POST /api/meetings/{id}/actions/{action} and reflect state change in UI
- [ ] 5.7 Add Edit button (pencil icon) → toggle edit mode with form
- [ ] 5.8 Add Delete button (trash icon) → opens CnDeleteDialog; calls DELETE /api/meetings/{id}
- [ ] 5.9 Route: `/meetings/:id` (detail), `/meetings/new` (new meeting form)

## 6. Meeting-Governance Body Relation

- [ ] 6.1 Ensure GovernanceBody register includes `workflowTemplate` property (optional string, stores domain preset: legislative|association|corporate|operations|citizen)
- [ ] 6.2 Create meeting form with GovernanceBody select dropdown (pre-fill if navigating from body detail page)
- [ ] 6.3 Implement GovernanceBody→Meeting reverse lookup in GovernanceBody detail page (CnDetailCard section: "Scheduled Meetings" table with recent/upcoming meetings)
- [ ] 6.4 Implement meeting fetch with governanceBody relation expanded (GET /api/meetings/{id}?_expand=governanceBody)

## 7. Attendee & Participant Management

- [ ] 7.1 On meeting creation, auto-populate attendees from GovernanceBody.memberships (fetch active Membership records for the body)
- [ ] 7.2 Create `src/components/AttendeeList.vue`; display Membership list with: Person name, role, voting weight; add/remove buttons
- [ ] 7.3 Implement "Add Attendee" button → dialog to select Person + role + voting weight; creates/links Membership record
- [ ] 7.4 Implement "Remove Attendee" button → removes Membership link (soft delete or relation removal)
- [ ] 7.5 Support observer and guest roles (in addition to member roles from Membership spec)
- [ ] 7.6 Track attendance via optional `attendanceStatus` field on Membership (values: present, absent, proxy, excused); pre-fill from meeting mode (virtual → may auto-mark present on voting)

## 8. Agenda Item Management

- [ ] 8.1 Ensure AgendaItem register includes: title, itemType, orderNumber, estimatedDuration, actualDuration, description, isRecurring (from ADR-000)
- [ ] 8.2 On meeting detail view, display "Agenda Items" CnDetailCard with sortable table (orderNumber, title, type, duration, description)
- [ ] 8.3 Implement "Add Agenda Item" button → CnFormDialog (title, type, duration, description); creates new AgendaItem linked to meeting
- [ ] 8.4 Implement drag-to-reorder for agenda items (update orderNumber via PUT /api/agendaitems/{id})
- [ ] 8.5 Implement "Edit Agenda Item" button (pencil icon on row) → form dialog
- [ ] 8.6 Implement "Delete Agenda Item" button (trash icon on row) → confirmation dialog

## 9. Meeting Series (Recurring Meetings)

- [ ] 9.1 Add `series` and `seriesPattern` properties to Meeting entity (series = identifier string, seriesPattern = JSON object with frequency/interval/until)
- [ ] 9.2 On new meeting form, add "Recurring" checkbox; if checked, show recurrence rule builder (frequency: daily/weekly/monthly, interval, until date, exceptions)
- [ ] 9.3 Implement meeting generation logic: when series meeting is created/updated, generate instance meetings up to `until` date
- [ ] 9.4 Store seriesPattern JSON in meeting object; expose via GET /api/meetings/{id}
- [ ] 9.5 When displaying meeting list, optionally group by series (e.g., "Municipal Council - 2026" with child instances)

## 10. ORI API Integration

- [ ] 10.1 Register Meeting entity with ORI API controller via SettingsController configuration (oriFeedEnabled = true for decidesk)
- [ ] 10.2 Implement ORI-compliant Meeting output: uri, title, date, identifier (meeting ID), governanceBody ref, status, attendeeCount
- [ ] 10.3 Implement ORI endpoint: GET /api/ori/meetings (paginated, filters by date range and governance body)
- [ ] 10.4 Test ORI output format against ORI specification (XML + JSON support)
- [ ] 10.5 Link published decisions and minutes to meeting via ORI `relatedDecision` and `relatedMinutes` fields

## 11. Dashboard Integration

- [ ] 11.1 Add "Upcoming Meetings" widget to dashboard (CnDashboardPage); display next 5 meetings for user's governance bodies
- [ ] 11.2 Widget shows: meeting title, date, body, attendee count; sorted by scheduled date ascending
- [ ] 11.3 Add "Meetings by Status" chart to dashboard (CnChartWidget, bar or donut): count of meetings per lifecycle state (draft/scheduled/in-progress/completed/cancelled)
- [ ] 11.4 Implement meeting search from dashboard (route to /meetings with search param pre-filled)

## 12. Testing

- [ ] 12.1 Create integration test: MeetingServiceTest; test create/read/update/delete with OpenRegister schema validation
- [ ] 12.2 Create workflow test: test state transitions for all 5 governance domains (valid and invalid transitions)
- [ ] 12.3 Create quorum test: validate quorum calculation (fixed count and percentage) and enforcement on state transitions
- [ ] 12.4 Create API endpoint tests: GET /api/meetings (list with filters), POST /api/meetings (create), GET /api/meetings/{id} (detail), PUT /api/meetings/{id} (update), DELETE /api/meetings/{id}, POST /api/meetings/{id}/actions/{action}
- [ ] 12.5 Create frontend component test: MeetingList.vue (filter, search, pagination), MeetingDetail.vue (edit/view modes, lifecycle actions)
- [ ] 12.6 Create ORI API compliance test: verify Meeting output matches ORI specification
- [ ] 12.7 Run WCAG 2.1 AA accessibility test on meeting forms and detail pages (keyboard navigation, color contrast, labels, ARIA)
- [ ] 12.8 Test meeting series generation: create series, verify instance meetings generated with correct dates and parent link

## 13. Documentation

- [ ] 13.1 Update `docs/meeting-management.md`: purpose, user journeys, feature overview, screenshots, quick start guide
- [ ] 13.2 Add API documentation to `docs/api-endpoints.md`: Meeting endpoints, request/response examples, query parameters
- [ ] 13.3 Add workflow documentation: valid state transitions per governance domain, illustrated with state diagram
- [ ] 13.4 Add developer guide: database schema, service architecture, testing patterns
