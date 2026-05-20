---
kind: code
depends_on: [ai-chat-companion-orchestrator]
---

# Decidesk — MCP Tools Provider (first per-app exemplar)

## Why

OpenRegister introduces an AI Chat Companion that exposes per-app MCP (Model Context
Protocol) tools through an orchestrator. The orchestrator discovers implementations of
the `OCA\OpenRegister\Mcp\IMcpToolProvider` interface registered in the Nextcloud DI
container and routes tool calls to the owning app.

Decidesk is the first per-app exemplar. Until at least one app implements the interface,
the chat companion has nothing to dispatch — and there is no reference implementation
that downstream Conduction apps (docudesk, opencatalogi, mydash, etc.) can copy. Shipping
this change unblocks (a) the chat companion's end-to-end flow, (b) the pattern other
apps will mirror, and (c) the citation/source-array convention the widget's
`CnAiMessageList` relies on to render `[view meeting]`-style inline links.

## What Changes

- **NEW** `lib/Mcp/DecideskToolProvider.php` — single class implementing
  `OCA\OpenRegister\Mcp\IMcpToolProvider`, exposing 5 MCP tools:
  - `decidesk.listOpenActionItems` — list incomplete action items (scope: mine | all)
  - `decidesk.listRecentMeetings` — recent meetings ordered by date desc
  - `decidesk.getMeetingDetails` — meeting + agenda + decisions + action items
  - `decidesk.startMeeting` — transition `scheduled` → `in-progress` (chair-only)
  - `decidesk.addActionItem` — create an action item attached to a meeting
- **NEW** service-container registration in `lib/AppInfo/Application.php` using
  `registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk',
  DecideskToolProvider::class)` so OR's `McpToolsService` discovers it.
- **NEW** per-tool authorisation: every tool that touches an object verifies the caller
  is a participant / chair / admin **inside** `invokeTool()` and returns a structured
  `{isError: true, error: 'forbidden', message: '...'}` payload on failure (so the LLM
  can explain the denial in natural language). No per-role tool hiding in v1 — full
  catalog always visible.
- **NEW** every tool result MUST include a `sources` array (deep links: meeting / agenda
  / decision / action-item URIs and labels) so the chat widget can render inline
  citations.
- **NEW** unit + integration tests under `tests/Unit/Mcp/` and `tests/Integration/Mcp/`.
- **NEW** short operator-facing docs page describing the 5 tools and how to enable the
  companion.
- **NEW** test stub `tests/Stubs/Mcp/IMcpToolProvider.php` for CI environments without
  the full openregister runtime.

No frontend changes. No new schemas. No new HTTP endpoints. No new database tables.

## Capabilities

### New Capabilities

- `mcp-tools`: Decidesk's implementation of `OCA\OpenRegister\Mcp\IMcpToolProvider`,
  the 5 tools it exposes, their input schemas, authorisation rules, and the contract
  for result payloads (including the mandatory `sources` array for inline citations).

### Modified Capabilities

None. This change is purely additive — no existing decidesk spec gains, loses, or
modifies a requirement.

## Impact

**Code:**

- `lib/Mcp/DecideskToolProvider.php` (new — single class, ~350 LOC estimated).
- `lib/AppInfo/Application.php` (modified — one `registerServiceAlias` call inside the
  existing `register(IRegistrationContext $context)` body).
- `tests/Stubs/Mcp/IMcpToolProvider.php` (new — interface stub for unit-test CI).
- `tests/Unit/Mcp/DecideskToolProviderTest.php` (new — unit test class).
- `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php` (new — integration test).
- `docs/features/mcp-tools.md` (new — operator docs).

**Dependencies:**

- Hard dependency on the openregister change `ai-chat-companion-orchestrator` shipping
  `OCA\OpenRegister\Mcp\IMcpToolProvider`. Implementation cannot land before the
  openregister PR merges.
- The interface contract is frozen by the hydra ADR-034 spec and the AI Chat Companion
  orchestrator spec in openregister.

**Reused (no changes needed):**

- `OCA\Decidesk\Service\MeetingService` — list, get, transition state.
- `OCA\Decidesk\Service\AgendaService` — fetch agenda items for a meeting.
- `OCA\Decidesk\Service\TaskService` (action items) — list incomplete, create new.
- `OCA\OpenRegister\Service\ObjectService` — list meetings and agenda items via
  `findAll()` (existing list pattern used elsewhere in decidesk controllers).
- Existing auth helpers in controllers (`requireChairOrSecretary`, `requireChairOrAdmin`)
  are lifted into private helpers on the provider — the controllers stay untouched.

**Out of scope (explicit non-goals):**

- Per-role / hybrid tool visibility (catalog filtering by user permissions). v2.
- Bulk operations across multiple meetings.
- Delete / archive tools.
- Multi-meeting batch state transitions.
- Frontend changes (the chat widget lives in OR, not in decidesk).
- New OpenRegister schemas, registers, or objects.
- New HTTP endpoints in decidesk.
- New background jobs or repair steps.
