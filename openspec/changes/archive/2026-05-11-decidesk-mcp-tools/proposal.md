---
kind: code
depends_on: [ai-chat-companion-orchestrator]
---

# Decidesk — MCP Tools Provider (first per-app exemplar)

## Why

Hydra ADR-034 introduces an AI Chat Companion that exposes per-app MCP (Model Context
Protocol) tools through an OpenRegister-owned orchestrator. The orchestrator discovers
implementations of the new `OCA\OpenRegister\Mcp\IMcpToolProvider` interface registered
in the Nextcloud DI container and routes tool calls to the owning app. The interface
itself lands in [openregister PR #1466](https://codeberg.org/Conduction/openregister/pulls)
(open, awaiting review) and is contractually frozen by ADR-034 plus the spec at
[`openspec/specs/ai-chat-companion/spec.md`](https://codeberg.org/Conduction/hydra) in
hydra.

Decidesk is the first per-app exemplar. Until at least one app implements the interface,
the chat companion has nothing to dispatch — and we have no reference implementation
that downstream Conduction apps (docudesk, opencatalogi, launchpad, etc.) can copy. Shipping
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
- **NEW** composer dependency on `openregister/openregister` at the version that
  publishes `IMcpToolProvider` (resolved during apply once the openregister PR merges).
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
- `composer.json` (modified — one new entry under `require`).

**Dependencies:**

- Hard dependency on the [openregister change `ai-chat-companion-orchestrator`](https://codeberg.org/Conduction/openregister)
  shipping `OCA\OpenRegister\Mcp\IMcpToolProvider`. Implementation cannot land before
  the openregister PR merges.
- Hydra ADR-034 + hydra spec [`openspec/specs/ai-chat-companion/spec.md`](https://codeberg.org/Conduction/hydra)
  define the contract this change implements.

**Reused (no changes needed):**

- `OCA\Decidesk\Service\MeetingService` — list, get, transition state.
- `OCA\Decidesk\Service\AgendaService` — fetch agenda items for a meeting.
- `OCA\Decidesk\Service\TaskService` (action items) — list incomplete, create new.
- Existing auth helpers in controllers (`requireChairOrSecretary`, `requireChairOrAdmin`)
  are pulled into the provider as private helpers — the controllers stay untouched.

**Out of scope (explicit non-goals):**

- Per-role / hybrid tool visibility (catalog filtering by user permissions). v2.
- Bulk operations across multiple meetings.
- Delete / archive tools.
- Multi-meeting batch state transitions.
- Frontend changes (the chat widget lives in OR, not in decidesk).
