# Changelog

All notable changes to Decidesk are documented in this file.

## [0.1.7]

### Added

- **MCP Tools Provider** (`mcp-tools`) — first per-app exemplar of
  `OCA\OpenRegister\Mcp\IMcpToolProvider` for the OpenRegister AI Chat Companion.
  `OCA\Decidesk\Mcp\DecideskToolProvider` exposes 5 MCP tools to the LLM:
  - `decidesk.listOpenActionItems` — list incomplete action items (scope: mine | all)
  - `decidesk.listRecentMeetings` — recent meetings ordered by date desc
  - `decidesk.getMeetingDetails` — meeting + agenda + decisions + action items inlined
  - `decidesk.startMeeting` — transition `scheduled` → `in-progress` (chair/admin only)
  - `decidesk.addActionItem` — create an action item attached to a meeting
  - Per-object authorisation runs inside `invokeTool()` (ADR-005, IDOR-safe): every
    object-targeting tool verifies the caller is a participant / chair / admin and
    returns a structured `{isError, error: 'forbidden'}` envelope on denial.
  - Every success path carries a mandatory `sources` citation array (deep links),
    capped at 20 with `sourcesTruncated` / `sourcesTotalCount` markers.
  - Registered via `registerServiceAlias('OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk', …)`
    so OpenRegister's `McpToolsService` discovers it.
  - Consumes existing decidesk services (MeetingService, TaskService, ParticipantResolver)
    and OpenRegister's `ObjectService` (ADR-022/ADR-001) — no new schemas, endpoints,
    or business logic.
  - Operator docs at `docs/features/mcp-tools.md`; unit + integration test coverage
    under `tests/Unit/Mcp/` and `tests/Integration/Mcp/`.
