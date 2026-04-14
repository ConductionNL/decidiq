# Design: Meeting Management (p2-meeting-management)

**Status:** in-progress
**App:** Decidesk
**Platform:** Nextcloud + OpenRegister
**Depends on:** p1-schemas-and-data-model, p1-dashboard-and-navigation, p1-crud-operations

## Context

Decidesk is a Nextcloud app using the thin-client pattern: all domain data is stored as
JSON objects in OpenRegister. The Meeting entity (schema:Event) was defined in the P1
schemas spec and registered with seed data. The P1 CRUD and dashboard specs delivered
placeholder list/detail views, the dashboard with meeting KPIs, and the navigation shell.

This spec replaces the placeholder Meeting views with fully functional list and detail
pages, and adds meeting lifecycle management: the ability to transition a meeting through
its states (draft → scheduled → opened → paused → adjourned → closed). It also adds
a backend MeetingService that enforces lifecycle transition rules and role-based
authorization (chair/secretary only for state changes).

## Goals / Non-Goals

**Goals:**
- Replace placeholder MeetingList with CnIndexPage-based list view (search, filter, paginate)
- Replace placeholder MeetingDetail with CnDetailPage showing full meeting properties
- Implement meeting lifecycle state machine (draft → scheduled → opened → paused → adjourned → closed)
- Enforce chair/secretary authorization for lifecycle transitions
- Add backend MeetingService + MeetingController for lifecycle API
- Add meeting Pinia store for lifecycle actions
- Add meetings to the main navigation menu
- Write PHPUnit tests for MeetingService and MeetingController (≥3 methods each)

**Non-Goals:**
- Agenda item management (p2-agenda-management)
- Motion, voting, amendment workflows (p2-motion-and-voting)
- Minutes and decision recording (p2-minutes-and-decisions)
- Meeting templates and recurring series (future spec)
- Attendance tracking and speaking time (future spec)
- Live meeting view (handled by p2-agenda-management)

## Decisions

### D1: Meeting lifecycle as a backend service
**Decision:** Lifecycle transitions are enforced server-side in MeetingService, not in
the frontend. The controller exposes `PUT /api/meetings/{id}/lifecycle` accepting a
`transition` parameter.
**Rationale:** Security — lifecycle rules must be enforced server-side to prevent
unauthorized state changes. The frontend calls the API and reflects the result.

### D2: Valid lifecycle transitions
**Decision:** The state machine allows these transitions:
- draft → scheduled (requires scheduledDate to be set)
- scheduled → opened
- opened → paused
- paused → opened (resume)
- opened → adjourned
- adjourned → opened (resume from adjournment)
- opened → closed
**Rationale:** Matches the Meeting entity lifecycle defined in ADR-000.

### D3: CnIndexPage + CnDetailPage for views
**Decision:** Use platform components for list and detail views.
**Rationale:** ADR-001 and ADR-004 mandate using platform components.

### D4: Chair/secretary authorization via participant roles
**Decision:** Reuse the same assertChairOrSecretary pattern from AgendaService.
**Rationale:** Consistent authorization across P2 features. Role check runs before
data is revealed (info-disclosure guard).

## Reuse Analysis (ADR-012)

| Capability | Platform Service | Used |
|---|---|---|
| CRUD operations | ObjectService | Yes — fetch/save meeting objects |
| List view | CnIndexPage + useListView | Yes — MeetingList |
| Detail view | CnDetailPage + CnDetailCard | Yes — MeetingDetail |
| Sidebar | CnObjectSidebar | Yes — files, notes, audit |
| Form dialogs | CnFormDialog | Yes — edit meeting |
| Delete dialogs | CnDeleteDialog | Yes — delete meeting |
| Status badges | CnStatusBadge | Yes — lifecycle state display |
| Object store | createObjectStore | Already registered in P1 |
| Notifications | NotificationService | Yes — lifecycle change notifications |

No overlap with existing services found. Lifecycle transition logic is domain-specific
to Decidesk meetings and cannot be generalized to OpenRegister core.
