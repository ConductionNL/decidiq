# Design — Decidesk MCP Tools Provider (first per-app exemplar)

## Context

The AI Chat Companion (hydra ADR-034) introduces a chat surface owned by OpenRegister
that talks to an LLM via MCP (Model Context Protocol) tools. Per-app capabilities are
plugged in by implementing the `OCA\OpenRegister\Mcp\IMcpToolProvider` interface and
registering the implementation in the Nextcloud DI container under a per-app alias key.
OpenRegister's `McpToolsService` enumerates registered providers, validates each tool
id namespace-matches `{getAppId()}.`, and routes invocations.

**Canonical contracts (authoritative — do NOT re-decide here):**

- Interface + dispatcher live in [openregister](https://github.com/ConductionNL/openregister), Codeberg PR #1466 (pre-migration, not migrated to GitHub — open, awaiting review at the time of writing) under change `ai-chat-companion-orchestrator`.
- The interface signature is locked by [hydra ADR-034](https://github.com/ConductionNL/hydra) and [hydra's `ai-chat-companion` spec](https://github.com/ConductionNL/hydra) on `development`.
- Tool descriptor shape, sources-array convention, and dispatcher discovery pattern are defined in the two documents above. This design references but does not duplicate them.

**Current decidesk state:**

- No MCP-related code exists in decidesk today.
- `lib/Service/MeetingService.php`, `lib/Service/AgendaService.php`, and
  `lib/Service/TaskService.php` already implement the business logic the 5 tools need.
- Auth helpers (`requireChairOrSecretary`, `requireChairOrAdmin`) live in controllers
  (`lib/Controller/MeetingController.php`, `lib/Controller/AgendaController.php`,
  `lib/Controller/VotingController.php`, `lib/Controller/MotionController.php`) and need
  to be lifted into private helpers on the provider — the controllers stay untouched.
- `composer.json` does NOT currently require `openregister/openregister`. It only carries
  `nextcloud/ocp` plus dev tooling. Stub classes live at `tests/Stubs/` for unit tests.

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

Per the locked decision from the task brief: `getTools()` ALWAYS returns the full
5-tool list. Per-object authorisation runs inside `invokeTool()` and yields a
structured `{isError: true, error: 'forbidden', ...}` payload on failure so the LLM can
explain the denial in natural language.

**Alternatives considered:** filtering the catalogue per caller (would force the LLM to
re-fetch tools every turn; complicates caching in the dispatcher; punts the v2 hybrid
mode prematurely). **Why this:** matches the contract from hydra ADR-034 and keeps the
LLM's mental model stable.

### D3: Service-container alias key `IMcpToolProvider::decidesk`

Registration uses
`$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk',
DecideskToolProvider::class)` inside the existing `register()` body in
`lib/AppInfo/Application.php`. OpenRegister's `McpToolsService` enumerates aliases
matching the prefix `OCA\OpenRegister\Mcp\IMcpToolProvider::` and resolves each one.

**Alternatives considered:** Event-based registration (heavier, no benefit at this
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
**Why not state-before-auth:** state checks would leak existence to unauthorised
callers.

### D5: Sources array is mandatory on every success path

Every successful tool return carries a `sources: array<{type, uuid, url, label}>` key.
The widget's `CnAiMessageList` (in `@conduction/nextcloud-vue`) renders these as
`[label]` inline citations. The truncation cap (20 items, with `sourcesTruncated`
+ `sourcesTotalCount`) prevents pathological tool calls from blowing up the LLM
context.

**Alternatives considered:** optional sources, sources-by-request, sources via a
secondary tool call. **Why mandatory + capped:** citations are the load-bearing UX
feature; making them optional invites apps to skip them and degrade the chat
experience.

## Reuse Analysis

The provider is a thin adapter — almost all behaviour is reused.

| Code path | Source | Reuse strategy |
|---|---|---|
| `IMcpToolProvider` interface | `openregister/openregister` (composer dep) | Declared in `require`; new dependency. Imported in `lib/Mcp/DecideskToolProvider.php` via `use`. |
| List recent meetings | `MeetingService` (existing) | Constructor inject; call existing list method with a per-user visibility filter. |
| Get meeting + agenda + decisions + action items | `MeetingService` + `AgendaService` + `TaskService` (existing) | Compose three calls into a single result object. |
| Transition `scheduled` → `in-progress` | `MeetingService::startMeeting()` (existing) | One call; provider only handles auth and result shaping. |
| List open action items | `TaskService` (existing) | Filter `completed = false`, optional `assigneeUserId = currentUser` when `scope=mine`. |
| Create action item | `TaskService::createForMeeting()` (existing) | One call. |
| Chair / participant / admin auth | `requireChairOrSecretary` / `requireChairOrAdmin` patterns in `lib/Controller/*Controller.php` | Lifted into private methods on the provider — controllers untouched. Returns boolean (auth pass/fail) rather than `JSONResponse` since the provider is not a controller. |
| Test stubs for `OCA\OpenRegister\*` | `tests/Stubs/` (existing) | Reused for unit tests that need to mock the interface without the full OR runtime. |
| Deep-link URLs | Existing route conventions (`/apps/decidesk/meetings/<uuid>`, etc.) | Built as string concatenation in a private `buildDeepLink($type, $uuid)` helper on the provider. |

The provider adds NO new business logic. If a tool needs a behaviour the services don't
already expose, that's a signal to extend the service — not to inline logic in the
provider.

## Seed Data

**N/A.** This change introduces no new OpenRegister schemas, registers, or objects, and
therefore no seed data. The 5 tools operate on existing decidesk objects (meetings,
agenda items, decisions, action items) that are seeded by the existing
`p2-meeting-management` / `p2-minutes-and-decisions` changes.

## Declarative-vs-Imperative (ADR-031)

**N/A.** Tool dispatch is a controller-style imperative pattern: a single class with
five handler methods, each delegating to existing services. No part of this change
touches:

- OpenRegister lifecycles or state machines (the only state change uses an existing
  imperative `MeetingService::startMeeting()` call).
- Derived fields, aggregations, or computed properties.
- Notifications (the chat widget renders results client-side from the `sources` array;
  no email/push/in-app notifications dispatched by this change).
- Declarative relations / widgets / register-level metadata.

ADR-031's guidance to prefer declarative over imperative does not apply because the
domain here (tool dispatch routed by `$toolId`) is intrinsically imperative — a `match`
expression on a closed enum of tool ids is the simplest possible expression of the
contract.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **R1 — Schema drift between `getTools()` inputSchema and underlying service signature.** If `TaskService::createForMeeting()` adds/changes a required parameter, `decidesk.addActionItem`'s `inputSchema` will silently drift out of sync. | Integration test round-trips a real `decidesk.startMeeting` and `decidesk.addActionItem` end-to-end through the DI container against a fixture. Unit tests assert the descriptor shape. CI fails on drift. |
| **R2 — LLM hallucinating UUIDs.** The model may invent a plausible-looking UUID that doesn't reference any real object, or paste one from an unrelated tenant. | UUID format validation runs before lookup (REQ-DMCP-007). Lookups that resolve to no object return `not_found`. Authorisation runs before existence-leaking error text. |
| **R3 — ADR-005 IDOR (CRITICAL).** Every action tool MUST do per-object auth. Per user memory, `hydra-gate-orphan-auth` previously caught dead auth methods in decidesk; the stub-method exploit risk is logged on decidesk#60. | Every helper invoked in `invokeTool()` MUST actually run (no `return true` stubs). Unit tests assert that the auth-failure path returns `forbidden` for every object-targeting tool. Hydra gate-9 (semantic auth) will catch chair-only routes that lack `requireChair`. |
| **R4 — Semantic auth gate.** The provider is not a controller, so `#[NoAdminRequired]` attributes don't apply. Chair-only tools (`startMeeting`) still need a semantic chair-check. | Provider declares an internal `requireChair($meetingUuid, $userId): bool` helper; the unit test for `decidesk.startMeeting` explicitly asserts the non-chair path returns `forbidden`. Hydra gate-9 reviewer instructed to inspect `lib/Mcp/DecideskToolProvider.php` even though it is not a controller. |
| **R5 — Tool result size explosion.** Deep-link inline citations on `getMeetingDetails` (meeting + N agenda items + M decisions + K action items) can grow large enough to blow the LLM's context window or the dispatcher's payload cap. | Hard cap of 20 source descriptors per result, with `sourcesTruncated: true` + `sourcesTotalCount: <int>` markers (REQ-DMCP-006) so the LLM can mention the truncation. The widget renders the truncation marker as `[+N more]`. |
| **R6 — No existing IMcpToolProvider test fixture.** Decidesk has stubs at `tests/Stubs/` for some OR classes but no `IMcpToolProvider` stub yet, and may not have the openregister runtime available in CI. | Add a minimal `tests/Stubs/Mcp/IMcpToolProvider.php` interface declaration mirroring the openregister signature, used only when the real package is not on the classpath. Integration test gates behind `class_exists(\OCA\OpenRegister\Mcp\McpToolsService::class)` so it skips cleanly in dev environments lacking openregister. |
| **R7 — Composer dependency ordering.** The interface lands in an openregister release that has not yet shipped. | Mark the depends_on relationship explicit in the change frontmatter (`ai-chat-companion-orchestrator`). The apply step pins the composer constraint to the first openregister tag that includes the interface (e.g. `^1.4` once tagged). Implementation cannot land before the openregister PR merges. |

## Migration Plan

**Forward path:**

1. Land openregister change `ai-chat-companion-orchestrator` (PR #1466). The interface
   ships in a tagged release.
2. Apply this decidesk change in a single PR targeting `development`. The PR:
   - Adds `lib/Mcp/DecideskToolProvider.php`.
   - Modifies `lib/AppInfo/Application.php` (one `registerServiceAlias` call).
   - Modifies `composer.json` (one new `require` entry).
   - Adds `tests/Unit/Mcp/DecideskToolProviderTest.php` and
     `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`.
   - Optionally adds `docs/features/mcp-tools.md`.
3. PR auto-merges per the pre-production-app policy (decidesk is pre-production; admin
   merge to development OK).

**No data migration.** No schema, register, or object changes. No background job.

**Rollback:** revert the PR. Removing `lib/Mcp/DecideskToolProvider.php` and the
`registerServiceAlias` call removes decidesk from the chat companion's tool catalogue;
the orchestrator continues to operate with whatever other providers remain. No data
cleanup required.

**Compatibility:** opt-in by way of the chat companion being enabled in OpenRegister
admin settings. If the companion is disabled, the provider class is loaded but never
invoked.

## Open Questions

All three open questions were resolved by code inspection at the end of `/opsx-ff` before this design locked. Recorded here for traceability:

- **OQ1 RESOLVED — no composer dep on openregister needed.** decidesk's existing controllers (e.g. `lib/Controller/MeetingController.php`, `lib/Controller/MinutesController.php`) already consume OR via Nextcloud's runtime autoloader (`use OCA\OpenRegister\Service\ObjectService;` with no composer entry). The `IMcpToolProvider` interface resolves through the same autoloader — installing the OR app at runtime is the only prerequisite, exactly as the widget's health probe already verifies. No `composer require` entry is added; the change pulls zero new composer deps.
- **OQ2 RESOLVED — no `createForMeeting` shim needed.** `lib/Service/TaskService.php` exposes `saveTask(array $task): array` as its create-and-update primitive (action items are stored as task records with a meeting reference). The `decidesk.addActionItem` handler in the provider calls `TaskService::saveTask([...])` directly, mapping the `inputSchema` fields (`meetingUuid`, `title`, `assigneeUserId`, `dueDate`) onto the task array. No new service method is required.
- **OQ3 RESOLVED — listing goes through OR's `ObjectService::findAll`, not `MeetingService`.** `lib/Service/MeetingService.php` exposes only single-record `create` / `read` / `update` / `delete` / `transition` / `getAvailableActions` methods (no list helper). Decidesk already calls `OCA\OpenRegister\Service\ObjectService::findAll()` for listing in other places (e.g. `MeetingController` reads list views). The `decidesk.listRecentMeetings` handler injects `ObjectService` and calls `findAll(register: <decidesk-register-uuid>, schema: <meeting-schema-uuid>, limit: N, sortDesc: 'createdAt')`. Per-user visibility filtering is enforced inside OR's `ObjectService` (the existing call sites rely on this); no decidesk-side post-filter is required.

Additional implementation note from inspection: `startMeeting` is NOT a dedicated method on `MeetingService`. The lifecycle is owned by `MeetingService::transition($meetingUuid, $action, $currentUserId)`. The provider's `startMeeting` handler invokes `transition($meetingUuid, 'start', $userId)`. Auth flowthrough (the per-object check for chair / admin) is enforced inside `MeetingService::transition` via `ObjectService::saveObject()` (per the existing inline comment in `MeetingController` at line 276), so the provider gets the IDOR check for free from the same code path the existing REST API uses.
