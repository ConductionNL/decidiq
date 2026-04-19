## Context

Decidesk is a Nextcloud app built on the **thin-client** pattern with OpenRegister as the data layer. The `p1-crud-operations` change already delivered the Meeting entity schema, CRUD views, and basic detail pages. This change (p2) adds the domain-specific business logic for meeting management that cannot be expressed as generic CRUD:

- **Meeting lifecycle** — a state machine governing valid state transitions (draft → scheduled → opened → paused → adjourned → closed). Business rules (e.g. you cannot open a closed meeting) must be enforced server-side, not just in the frontend.
- **Lifecycle UI** — action buttons in `MeetingDetail.vue` that reflect the current state and call the lifecycle endpoint.

Everything else (attendance lists, speaking time, document attachments, series linking) is covered by the OpenRegister platform's built-in file attachments, relations, and CRUD — no custom code is needed.

## Goals / Non-Goals

**Goals:**
- Server-side lifecycle state machine for Meeting (`MeetingService::transition()`)
- Thin `MeetingController` exposing `POST /api/meetings/{id}/lifecycle`
- Vue component `MeetingLifecycle.vue` rendering valid action buttons from the current state
- PHPUnit tests (≥3 test methods each) for `MeetingService` and `MeetingController`

**Non-Goals:**
- Attendance presence-list tracking beyond what OpenRegister relations provide (p3)
- Speaking-time clock / timeboxing UI (p3)
- Meeting template CRUD (relies on OpenRegister schema defaults — p3)
- ORI/Open-RIS publication (p3)

## Decisions

### 1. Server-side state machine in MeetingService
**Decision**: Lifecycle validation lives in `lib/Service/MeetingService.php` as a static transition table.
**Rationale**: ADR-003 mandates all business logic in the Service layer. The frontend cannot be trusted to enforce state machine rules — a malicious client could send any lifecycle value via `objectStore.saveObject()`.
**Alternative considered**: Frontend-only with schema validation — rejected because schema `enum` constraints allow any valid value, not only valid transitions from the current state.

### 2. PATCH semantics for lifecycle update
**Decision**: The lifecycle endpoint calls `ObjectService::updateFromArray($uuid, ['lifecycle' => $newState], updateVersion: true, patch: true)`.
**Rationale**: Only the lifecycle field changes; patch mode preserves all other fields. `updateVersion: true` triggers OpenRegister's audit trail.

### 3. Single POST endpoint with `action` body param
**Decision**: `POST /api/meetings/{id}/lifecycle` with `{ "action": "open|pause|resume|adjourn|close|schedule" }`.
**Rationale**: ADR-002 forbids custom HTTP methods/verbs. Using a single endpoint with an action parameter follows the NL API Design guidelines (7.2 — use POST for state transitions that cannot be expressed as a resource change).
**Alternative considered**: PUT to `/api/meetings/{id}` with new lifecycle value — rejected because it bypasses server-side transition validation.

### 4. MeetingLifecycle.vue renders available actions
**Decision**: A dedicated `MeetingLifecycle.vue` component inside `MeetingDetail.vue` reads the current `lifecycle` value from the object and renders only the valid next-step action buttons.
**Rationale**: ADR-004 single responsibility: the detail page should not contain transition logic. Centralising in a component makes it reusable for future meeting list bulk-actions.

## Lifecycle State Machine

```
draft  ──schedule──►  scheduled
                           │
                          open
                           │
                           ▼
            ◄──resume── opened ──pause──►  paused
                │                              │
               adjourn                      adjourn
                │                              │
                ▼                              ▼
            adjourned  ◄─────────────── adjourned
                │
               open (re-open)
                │
                ▼
             opened
                │
               close
                ▼
             closed  (terminal)
```

| Action   | Valid from states             | Result state |
|----------|-------------------------------|--------------|
| schedule | draft                         | scheduled    |
| open     | scheduled, adjourned          | opened       |
| pause    | opened                        | paused       |
| resume   | paused                        | opened       |
| adjourn  | opened, paused                | adjourned    |
| close    | opened, paused, adjourned, scheduled | closed |

## Reuse Analysis (ADR-012)

- CRUD, pagination, filtering — OpenRegister `ObjectService` (no rebuild)
- File attachments on meeting — OpenRegister `FileService` + `CnObjectSidebar` (no rebuild)
- Audit trail for lifecycle changes — OpenRegister automatic (no rebuild)
- Notifications — `NotificationService` available for future meeting-open notifications
- Frontend list/detail pattern — `CnIndexPage` + `CnDetailPage` (already implemented in p1)

## Seed Data (Dutch examples — lifecycle transitions)

These objects supplement the seed data from `p1-crud-operations`. No schema changes needed.

### Meeting (lifecycle examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "raadsvergadering-2026-04-01" },
    "title": "Raadsvergadering 1 april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-01T19:30:00Z",
    "meetingMode": "in-person",
    "lifecycle": "closed"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "commissie-sociaal-2026-04-15" },
    "title": "Commissie Sociaal 15 april 2026",
    "meetingType": "committee",
    "scheduledDate": "2026-04-15T14:00:00Z",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled"
  }
]
```

## Status

`pr-created` (2026-04-19 — draft PR opened on branch feature/71/p2-meeting-management)
