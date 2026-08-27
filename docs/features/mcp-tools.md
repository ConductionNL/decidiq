# MCP Tools (AI Chat Companion Integration)

Decidiq exposes 5 governance tools to the AI Chat Companion (hydra ADR-034) via the
`OCA\OpenRegister\Mcp\IMcpToolProvider` interface. The companion can call these tools
when a user asks governance-related questions (e.g. "what action items are due this
week?" or "start the council meeting").

## Overview

The MCP (Model Context Protocol) integration lets an LLM surface Decidiq
capabilities without screen-scraping or custom API clients. Each tool call goes
through:

1. Per-tool argument validation (UUID shape, enum values, numeric ranges).
2. Per-object authorisation check (OWASP A01:2021, ADR-005) — enforced before any
   business logic executes.
3. Business logic via the existing service layer.
4. A structured result array with a mandatory `sources[]` array so the companion can
   cite which objects it used.

## Enabling the Companion

The integration is registered automatically when Decidiq is loaded alongside
OpenRegister >= the release that publishes `IMcpToolProvider` (PR #1466 in the
openregister repo). No admin configuration is needed.

If OpenRegister is not installed, the tools are simply unavailable; Decidiq
continues to function normally.

## Tool Reference

### `decidesk.listOpenActionItems`

Returns incomplete action items visible to the caller.

**Input fields**

| Field    | Type   | Required | Default | Constraints                   |
|----------|--------|----------|---------|-------------------------------|
| `scope`  | string | no       | `mine`  | `mine` or `all`               |
| `limit`  | int    | no       | 20      | 1 – 50                        |

**Output shape**

```json
{
  "count": 3,
  "items": [
    {
      "uuid": "...",
      "title": "...",
      "dueDate": "2026-06-01",
      "meetingTitle": "...",
      "meetingUuid": "...",
      "assignee": "..."
    }
  ],
  "sources": [
    { "type": "decidesk.actionItem", "uuid": "...", "url": "/apps/decidiq/...", "label": "..." }
  ]
}
```

**Auth requirement:** Any authenticated user. `scope=all` returns items across all
meetings the user can see.

---

### `decidesk.listRecentMeetings`

Returns meetings ordered newest-first.

**Input fields**

| Field          | Type   | Required | Default     | Constraints                             |
|----------------|--------|----------|-------------|-----------------------------------------|
| `limit`        | int    | no       | 10          | 1 – 20                                  |
| `statusFilter` | string | no       | `any`       | `any`, `scheduled`, `in-progress`, `closed` |

**Output shape**

```json
{
  "count": 2,
  "meetings": [
    {
      "uuid": "...",
      "title": "...",
      "scheduledDate": "2026-05-15T14:00:00+02:00",
      "status": "scheduled"
    }
  ],
  "sources": [ ... ]
}
```

**Auth requirement:** Any authenticated user.

---

### `decidesk.getMeetingDetails`

Fetches a single meeting with inline agenda items, decisions, and action items.

**Input fields**

| Field         | Type   | Required | Description            |
|---------------|--------|----------|------------------------|
| `meetingUuid` | string | yes      | UUID of the meeting    |

**Output shape**

```json
{
  "meeting": { "uuid": "...", "title": "...", "status": "...", "scheduledDate": "..." },
  "agendaItems": [ { "uuid": "...", "title": "...", "order": 1 } ],
  "decisions": [ { "uuid": "...", "title": "..." } ],
  "actionItems": [ { "uuid": "...", "title": "...", "dueDate": "..." } ],
  "sources": [ ... ],
  "sourcesTruncated": false,
  "sourcesTotalCount": 5
}
```

**Auth requirement:** The caller must be a participant in the meeting or a system
admin. Returns `forbidden` otherwise.

---

### `decidesk.startMeeting`

Transitions a meeting from `scheduled` to `opened` (in-progress).

**Input fields**

| Field         | Type   | Required | Description            |
|---------------|--------|----------|------------------------|
| `meetingUuid` | string | yes      | UUID of the meeting    |

**Output shape**

```json
{
  "success": true,
  "started": true,
  "meetingUuid": "...",
  "startedAt": "2026-05-15T14:00:00+00:00",
  "sources": [ { "type": "decidesk.meeting", "uuid": "...", "url": "...", "label": "..." } ]
}
```

**Auth requirement:** The caller must be the designated chair of the meeting or a
system admin. Returns `forbidden` otherwise.

**State guard:** If the meeting is not in `scheduled` state, the tool returns
`{ isError: true, error: "invalid_state", message: "Meeting is already <state>." }`.

---

### `decidesk.addActionItem`

Creates a new action item attached to a meeting.

**Input fields**

| Field         | Type   | Required | Constraints                             |
|---------------|--------|----------|-----------------------------------------|
| `meetingUuid` | string | yes      | UUID of the meeting                     |
| `title`       | string | yes      | 3 – 200 characters                      |
| `assigneeId`  | string | no       | Nextcloud user ID of the assignee       |
| `dueDate`     | string | no       | ISO 8601 date (`YYYY-MM-DD`)            |

**Output shape**

```json
{
  "created": true,
  "actionItem": {
    "uuid": "...",
    "title": "...",
    "meetingUuid": "...",
    "dueDate": "2026-06-01"
  },
  "sources": [
    { "type": "decidesk.actionItem", "uuid": "...", "url": "...", "label": "..." },
    { "type": "decidesk.meeting", "uuid": "...", "url": "...", "label": "..." }
  ]
}
```

**Auth requirement:** The caller must be a participant in the meeting or a system
admin. Returns `forbidden` otherwise.

---

## Sources Convention

Every successful tool result contains a `sources` array (REQ-DMCP-006). Each element
has four keys:

| Key     | Type   | Description                              |
|---------|--------|------------------------------------------|
| `type`  | string | Dot-namespaced type (e.g. `decidesk.meeting`) |
| `uuid`  | string | Object UUID                              |
| `url`   | string | Deep link: `/apps/decidiq/<resource>/<uuid>` |
| `label` | string | Human-readable title of the object       |

When a result would produce more than 20 source descriptors, the array is capped at 20
and the response includes `sourcesTruncated: true` and `sourcesTotalCount: <n>`.

## Error Envelope

All errors (validation, auth, state, internal) use a consistent envelope:

```json
{
  "isError": true,
  "error": "unknown_tool | invalid_arguments | forbidden | not_found | invalid_state | internal_error",
  "message": "Human-readable explanation."
}
```

## Troubleshooting

**Tool calls return `forbidden` for an admin user**

System admin status is checked via `IGroupManager::isAdmin()`. Confirm the user is in
the Nextcloud `admin` group, not just an app-level administrator.

**Tool calls return `internal_error`**

Check the Nextcloud server log (`data/nextcloud.log`) for entries tagged with
`DecidiqToolProvider`. Common causes: OpenRegister `ObjectService` unavailable, or a
corrupted meeting object in the register.

**Tools do not appear in the AI Chat Companion**

The alias `OCA\OpenRegister\Mcp\IMcpToolProvider::decidiq` is registered in
`Application::register()`. If OpenRegister's `McpToolsService` cannot resolve it,
verify that OpenRegister is loaded and that no DI container error appears on app
bootstrap (`occ check`).
