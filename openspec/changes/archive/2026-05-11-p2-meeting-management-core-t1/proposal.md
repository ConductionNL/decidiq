# Proposal: Meeting Management — Core T1

## Why

Meeting management is the highest-demand feature in Decidesk (demand score 520+, 105 tender mentions). This change delivers the core T1 (first tranche) of meeting management functionality — establishing the foundation for governance bodies to schedule, organize, and execute meetings with agendas, attendees, and lifecycle tracking. T1 focuses on essential capabilities needed across all 5 governance domains (legislative, associations, corporate boards, management teams, citizen participation), deferring advanced features (voting, minutes, motions) to later phases.

## What Changes

- **Meeting CRUD**: Create, read, update, delete meetings with full lifecycle tracking (draft → scheduled → in-progress → adjourned/completed)
- **Meeting scheduling**: Assign scheduled date, duration, location (physical/virtual/hybrid), meeting mode
- **Agenda management**: Create and order agenda items within meetings; link items to governance body categories; set item types (discussion, motion, report, etc.)
- **Participant management**: Assign governance body members to meetings; track attendance via Membership relation; support observers and guests
- **Meeting series**: Support recurring meetings with pattern configuration
- **Quorum tracking**: Define and validate quorum requirements per governance body
- **Meeting state transitions**: Enforce valid lifecycle transitions with workflow validation for each governance domain
- **OpenRegister schema**: Meeting entity fully integrated into OpenRegister with schema validation
- **Meeting list view**: Dashboard widget showing upcoming meetings; list page with filtering (by body, date, status); search by title
- **Meeting detail page**: Full meeting context (body, attendees, agenda, dates); edit form; lifecycle action buttons
- **ORI API integration**: Meetings exposed via ORI standard API for open data publication (governance domain compatibility)
- **Audit trail**: All meeting changes tracked with before/after snapshots; audit visible in sidebar

## Capabilities

### New Capabilities
- `meeting-core`: Meeting CRUD, scheduling, and lifecycle management (create, edit, reschedule, cancel, complete)
- `meeting-agenda`: Agenda item creation, ordering, typing, and linking to governance body process categories
- `meeting-attendees`: Participant assignment via Membership relation; attendance and observer tracking
- `meeting-series`: Recurring meeting patterns (daily/weekly/monthly) with recurrence rules and series grouping
- `meeting-list-view`: Meeting index page with filtering (body, date range, lifecycle status); search by title
- `meeting-detail-view`: Meeting detail page with related entities (agenda, attendees, minutes link), edit form, action buttons
- `meeting-workflow`: Lifecycle state machine per governance domain (legislative, association, corporate, operations, citizen); enforce valid transitions
- `meeting-quorum`: Quorum calculation (fixed count or percentage) and validation at meeting level

### Modified Capabilities
- `governance-bodies`: GovernanceBody gains `workflowTemplate` property to define meeting lifecycle rules per domain
- `person-and-membership`: Membership relation enhanced to track meeting attendance (new optional `meetingAttendance` tracking)
- `ori-api`: ORI API output includes Meeting entity with governance body metadata and decision linkage

## Impact

**Affected entities**: Meeting, AgendaItem, Governance Body, Person/Membership, Minutes (reference), Decision (reference)

**Frontend changes**: 
- New pages: Dashboard meeting widget, Meeting list, Meeting detail (view/edit), New meeting form
- New components: Meeting lifecycle badge, Agenda item reorder control, Attendee chip list, Quorum status indicator
- Updated store: createObjectStore for meetings with relations plugin (governance body, attendees, agenda items)

**Backend changes**:
- Meeting controller and service for CRUD + lifecycle
- Workflow engine integration to validate state transitions per governance domain
- QuorumService to calculate and validate quorum
- ORI API endpoint registration for Meeting entity
- Repair step for initial Meeting schema registration in OpenRegister

**API endpoints**: 
- `GET /api/meetings` (list with filters)
- `POST /api/meetings` (create)
- `GET /api/meetings/{id}` (detail)
- `PUT /api/meetings/{id}` (update)
- `DELETE /api/meetings/{id}` (soft delete)
- `POST /api/meetings/{id}/actions/{action}` (lifecycle transitions: schedule, start, adjourn, complete, cancel)

**Standards compliance**: Akoma Ntoso (meeting metadata), ORI (data publication), Wet digitaal vergaderen (digital meeting legal basis), WCAG 2.1 AA (accessibility)

**Testing**: Integration tests with OpenRegister schema validation; workflow tests for all 5 governance domains; ORI API compliance check
