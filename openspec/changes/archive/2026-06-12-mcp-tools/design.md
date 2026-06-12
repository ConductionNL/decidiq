# Design — Decidesk MCP Tools Provider (first per-app exemplar)

## Context

The AI Chat Companion introduces a chat surface owned by OpenRegister that talks to an
LLM via MCP (Model Context Protocol) tools. Per-app capabilities are plugged in by
implementing the `OCA\OpenRegister\Mcp\IMcpToolProvider` interface and registering the
implementation in the Nextcloud DI container under a per-app alias key. OpenRegister's
`McpToolsService` enumerates registered providers, validates each tool id namespace-matches
`{getAppId()}.`, and routes invocations.

**Current decidesk state:**

- No MCP-related code exists in decidesk today.
- `lib/Service/MeetingService.php`, `lib/Service/AgendaService.php`, and
  `lib/Service/TaskService.php` already implement the business logic the 5 tools need.
- Auth helpers (`requireChairOrSecretary`, `requireChairOrAdmin`) live in controllers
  (`lib/Controller/MeetingController.php`, `lib/Controller/AgendaController.php`,
  `lib/Controller/VotingController.php`, `lib/Controller/MotionController.php`) and will be
  lifted into private helpers on the provider — the controllers stay untouched.
- `MeetingService` exposes only single-record methods (no list helper); listing goes
  through `OCA\OpenRegister\Service\ObjectService::findAll()`, consistent with the
  pattern used in existing decidesk controllers.
- `TaskService` exposes `saveTask(array $task): array` as its create-and-update
  primitive; `decidesk.addActionItem` maps its `inputSchema` fields directly onto this.

**Stakeholders:** the openregister team owns the interface and orchestrator; decidesk
owns this provider; downstream Conduction apps (docudesk, opencatalogi, launchpad) will
copy this exemplar.

## Goals / Non-Goals

**Goals:**

- Ship the first per-app implementation of `OCA\OpenRegister\Mcp\IMcpToolProvider`,
  exposing the 5 tools enumerated in the proposal and spec.
- Establish the reference shape for tool descriptors, the dispatch+validation+auth
  ordering, the structured error envelope, and the `sources`-array citation
  convention. Other apps will copy this file structure.
- Deliver test coverage that documents both happy-path and forbidden-path behaviour for
  every tool.
- Keep `composer check:strict` clean.

**Non-Goals:**

- Per-role / hybrid tool visibility (catalog filtering by user permissions). Full
  catalogue is always exposed; auth is per-invocation. Hybrid mode is v2.
- Bulk operations across multiple meetings.
- Delete / archive tools.
- Multi-meeting batch state transitions (e.g. "close all meetings older than X").
- Frontend changes. The chat widget lives in OpenRegister, not decidesk.
- New OpenRegister schemas, registers, or objects.
- New HTTP endpoints in decidesk.
- New background jobs or repair steps.

## Decisions

### D1: Single provider class, no per-tool subclasses

A single `OCA\Decidesk\Mcp\DecideskToolProvider` class implements `IMcpToolProvider` and
contains 5 private handler methods (`handleListOpenActionItems`, `handleStartMeeting`,
etc.). `invokeTool()` dispatches via a `match` expression on `$toolId`.

**Alternatives considered:** one class per tool (overkill — each handler is ~30 LOC and
the surface is bounded by the openregister contract); a service-locator with tool
registration (premature abstraction — only 5 tools land in v1). **Why this:** keeps the
exemplar copy-able by other apps; one file is the unit of mental work.

### D2: Tool catalogue is always full; auth happens in `invokeTool()`

`getTools()` ALWAYS returns the full 5-tool list. Per-object authorisation runs inside
`invokeTool()` and yields a structured `{isError: true, error: 'forbidden', ...}` payload
on failure so the LLM can explain the denial in natural language.

**Alternatives considered:** filtering the catalogue per caller (would force the LLM to
re-fetch tools every turn; complicates caching in the dispatcher; punts the v2 hybrid
mode prematurely). **Why this:** matches the interface contract and keeps the LLM's
mental model stable.

### D3: Service-container alias key `IMcpToolProvider::decidesk`

Registration uses
`$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk',
DecideskToolProvider::class)` inside the existing `register()` body in
`lib/AppInfo/Application.php`. OpenRegister's `McpToolsService` enumerates aliases
matching the prefix `OCA\OpenRegister\Mcp\IMcpToolProvider::` and resolves each one.

**Alternatives considered:** event-based registration (heavier, no benefit at this
scale); auto-discovery by scanning `OCA\*\Mcp\` namespaces (fragile, breaks PHP
autoload semantics). **Why this:** matches the locked OR dispatcher contract; one line
change in `Application.php`.

### D4: Strict validation pipeline — args → auth → state → business logic

Each handler runs the same four-phase pipeline:

1. **Validate arguments** against the tool's `inputSchema` (UUID format, enum values,
   numeric ranges, required fields). Failure → `{error: 'invalid_arguments'}`.
2. **Authorise** the caller against the target object. Failure → `{error: 'forbidden'}`.
3. **Check state** (for `startMeeting`: must be `scheduled`). Failure →
   `{error: 'invalid_state'}`.
4. **Delegate to service** and shape the success payload (including `sources`).

**Alternatives considered:** auth-first ordering. **Why arg-first:** users typing bad
input shouldn't trigger expensive authorisation lookups; arg validation is cheap.
**Why not state-before-auth:** state checks would leak existence to unauthorised callers.

### D5: Sources array is mandatory on every success path

Every successful tool return carries a `sources: array<{type, uuid, url, label}>` key.
The widget's `CnAiMessageList` renders these as `[label]` inline citations. The
truncation cap (20 items, with `sourcesTruncated` + `sourcesTotalCount`) prevents
pathological tool calls from blowing up the LLM context.

**Why mandatory + capped:** citations are the load-bearing UX feature; making them
optional invites apps to skip them and degrade the chat experience.

### D6: No composer dependency on openregister

Decidesk's existing controllers already consume OpenRegister via Nextcloud's runtime
autoloader (`use OCA\OpenRegister\Service\ObjectService;` with no composer entry). The
`IMcpToolProvider` interface resolves through the same autoloader — installing the OR
app at runtime is the only prerequisite. A minimal `tests/Stubs/Mcp/IMcpToolProvider.php`
stub is added for CI environments lacking the full openregister runtime.

## Reuse Analysis

The provider is a thin adapter — almost all behaviour is reused.

| Code path | Source | Reuse strategy |
|---|---|---|
| `IMcpToolProvider` interface | `openregister/openregister` (runtime autoloader) | No composer dep needed; OR autoloads at runtime exactly as existing controllers use `ObjectService`. |
| List recent meetings | `ObjectService::findAll()` (existing) | Constructor inject; call existing list method with per-user visibility filter and date-desc sort. `MeetingService` has no list helper. |
| Get meeting details | `MeetingService` + `ObjectService` (existing) | Compose meeting lookup + agenda/decision/action-item `findAll` calls into a single result. |
| Transition `scheduled` → `in-progress` | `MeetingService::transition($uuid, 'open', $userId)` (existing) | One call; provider only handles auth and result shaping. |
| List open action items | `TaskService` (existing) | Filter `completed = false`, optional `assigneeUserId = currentUser` when `scope=mine`. |
| Create action item | `TaskService::saveTask([...])` (existing) | Maps `inputSchema` fields (`meetingUuid`, `title`, `assigneeUserId`, `dueDate`) onto the task array directly. |
| Chair / participant / admin auth | `requireChairOrSecretary` / `requireChairOrAdmin` patterns in `lib/Controller/*Controller.php` | Lifted into private boolean-return methods on the provider — controllers untouched. |
| Test stubs for `OCA\OpenRegister\*` | `tests/Stubs/` (existing) | Reused for unit tests. New stub added at `tests/Stubs/Mcp/IMcpToolProvider.php`. |
| Deep-link URLs | Existing route conventions (`/apps/decidesk/meetings/<uuid>`, etc.) | Built as string concatenation in a private `buildDeepLink($type, $uuid)` helper. |

The provider adds NO new business logic. If a tool needs behaviour the services don't
already expose, that's a signal to extend the service — not to inline logic in the
provider.

## Seed Data

**N/A.** This change introduces no new OpenRegister schemas, registers, or objects, and
therefore no seed data. The 5 tools operate on existing decidesk objects (meetings,
agenda items, decisions, action items) that are seeded by the existing
`p2-meeting-management` / `p2-minutes-and-decisions` changes.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **R1 — Schema drift between `getTools()` inputSchema and underlying service signature.** If `TaskService::saveTask()` changes its required parameters, `decidesk.addActionItem`'s `inputSchema` will silently drift out of sync. | Integration test round-trips a real `decidesk.startMeeting` end-to-end through the DI container. Unit tests assert the descriptor shape per REQ-DMCP-002. CI fails on drift. |
| **R2 — LLM hallucinating UUIDs.** The model may invent a plausible-looking UUID that doesn't reference any real object, or paste one from an unrelated tenant. | UUID format validation runs before lookup (REQ-DMCP-007). Lookups that resolve to no object return `not_found`. Authorisation runs before existence-leaking error text. |
| **R3 — ADR-005 IDOR (CRITICAL).** Every action tool MUST do per-object auth. | Every auth helper invoked in `invokeTool()` MUST actually run (no `return true` stubs). Unit tests assert that the auth-failure path returns `forbidden` for every object-targeting tool. |
| **R4 — Provider is not a controller.** `#[NoAdminRequired]` attributes don't apply; chair-only tools (`startMeeting`) still need a semantic chair-check. | Provider declares an internal `requireChairOrAdmin()` boolean helper; unit test for `decidesk.startMeeting` explicitly asserts the non-chair path returns `forbidden`. |
| **R5 — Tool result size explosion.** `getMeetingDetails` sources can grow large enough to blow the LLM's context window. | Hard cap of 20 source descriptors per result, with `sourcesTruncated: true` + `sourcesTotalCount: <int>` markers (REQ-DMCP-006). |
| **R6 — No existing `IMcpToolProvider` stub.** Decidesk may not have the openregister runtime available in CI. | Add `tests/Stubs/Mcp/IMcpToolProvider.php`. Integration test gates behind `class_exists(\OCA\OpenRegister\Mcp\McpToolsService::class)` so it skips cleanly in dev environments lacking openregister. |

## Migration Plan

**Forward path:**

1. Land openregister change `ai-chat-companion-orchestrator`. The interface ships in a
   tagged release.
2. Apply this decidesk change in a single PR targeting `development`. The PR:
   - Adds `lib/Mcp/DecideskToolProvider.php`.
   - Modifies `lib/AppInfo/Application.php` (one `registerServiceAlias` call).
   - Adds `tests/Stubs/Mcp/IMcpToolProvider.php`.
   - Adds `tests/Unit/Mcp/DecideskToolProviderTest.php`.
   - Adds `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`.
   - Adds `docs/features/mcp-tools.md`.

**No data migration.** No schema, register, or object changes. No background job.

**Rollback:** revert the PR. Removing `lib/Mcp/DecideskToolProvider.php` and the
`registerServiceAlias` call removes decidesk from the chat companion's tool catalogue;
the orchestrator continues to operate with whatever other providers remain. No data
cleanup required.

**Compatibility:** opt-in by way of the chat companion being enabled in OpenRegister
admin settings. If the companion is disabled, the provider class is loaded but never
invoked.
