# mcp-tools Specification

## Purpose
@e2e exclude Pure PHP backend spec — all scenarios are server-side DI/service-layer contracts covered by PHPUnit (tests/Unit/Mcp/DecideskToolProviderTest.php and tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php). No browser UI surface exists.

TBD - created by archiving change decidesk-mcp-tools. Update Purpose after archive.
## Requirements
### Requirement: REQ-DMCP-001 — Implement IMcpToolProvider

The system SHALL provide a class `OCA\Decidesk\Mcp\DecideskToolProvider` that implements
`OCA\OpenRegister\Mcp\IMcpToolProvider`. The class SHALL be registered in the Nextcloud
service container via `IRegistrationContext::registerServiceAlias` in
`lib/AppInfo/Application.php` using the alias key
`OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk` so OpenRegister's `McpToolsService`
discovers it.

The class MUST implement three methods:

| Method | Return | Behaviour |
|---|---|---|
| `getAppId(): string` | `"decidesk"` (constant) | Identifies the owning app. |
| `getTools(): array` | List of 5 tool descriptors | See REQ-DMCP-002. |
| `invokeTool(string $toolId, array $arguments): array` | Tool result payload | See REQ-DMCP-003. |

#### Scenario: Service alias resolves to provider
- **GIVEN** decidesk is enabled and openregister is installed
- **WHEN** `\OC::$server->query('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk')` is called
- **THEN** the container returns an instance of `OCA\Decidesk\Mcp\DecideskToolProvider`

#### Scenario: getAppId returns the canonical slug
- **WHEN** `getAppId()` is called on the provider
- **THEN** it returns the string `"decidesk"` (case-sensitive, no whitespace)

---

### Requirement: REQ-DMCP-002 — Tool catalogue (v1)

The system SHALL expose exactly the following 5 tool descriptors from `getTools()`. Each
descriptor MUST be an associative array with the keys `id`, `name`, `description`, and
`inputSchema`. The `id` MUST start with `decidesk.` (the value of `getAppId()` followed
by a literal `.`).

| Tool ID | Purpose |
|---|---|
| `decidesk.listOpenActionItems` | List incomplete action items (scope: mine or all visible). |
| `decidesk.listRecentMeetings` | List the caller's recent meetings, ordered by date desc. |
| `decidesk.getMeetingDetails` | Fetch a meeting with agenda + decisions + action items inlined. |
| `decidesk.startMeeting` | Transition a `scheduled` meeting to `in-progress` (chair-only). |
| `decidesk.addActionItem` | Create an action item attached to a meeting. |

**Input schemas** (JSON Schema fragments):

```yaml
decidesk.listOpenActionItems:
  type: object
  properties:
    scope: { type: string, enum: [mine, all], default: mine }
    limit: { type: integer, minimum: 1, maximum: 50, default: 20 }
  required: []

decidesk.listRecentMeetings:
  type: object
  properties:
    limit: { type: integer, minimum: 1, maximum: 20, default: 10 }
    statusFilter: { type: string, enum: [any, scheduled, in-progress, closed], default: any }
  required: []

decidesk.getMeetingDetails:
  type: object
  properties:
    meetingUuid: { type: string, format: uuid }
  required: [meetingUuid]

decidesk.startMeeting:
  type: object
  properties:
    meetingUuid: { type: string, format: uuid }
  required: [meetingUuid]

decidesk.addActionItem:
  type: object
  properties:
    meetingUuid:    { type: string, format: uuid }
    title:          { type: string, minLength: 3, maxLength: 200 }
    assigneeUserId: { type: [string, "null"], default: null }
    dueDate:        { type: [string, "null"], format: date, default: null }
  required: [meetingUuid, title]
```

#### Scenario: getTools returns exactly 5 tools
- **WHEN** `getTools()` is called
- **THEN** it returns an array of length 5
- **AND** the set of `id` values equals `{decidesk.listOpenActionItems, decidesk.listRecentMeetings, decidesk.getMeetingDetails, decidesk.startMeeting, decidesk.addActionItem}`

#### Scenario: Every tool id is namespaced under getAppId
- **GIVEN** the list returned by `getTools()`
- **WHEN** each tool's `id` is examined
- **THEN** every `id` starts with `"decidesk."` (the value of `getAppId()` plus a dot)

#### Scenario: Every tool descriptor declares the required keys
- **WHEN** a caller iterates `getTools()`
- **THEN** every descriptor has non-empty `id`, `name`, `description`, and `inputSchema` keys
- **AND** `inputSchema` is an array with `type === "object"` and a `properties` key

---

### Requirement: REQ-DMCP-003 — `invokeTool()` dispatch and error envelope

The system SHALL route `invokeTool(string $toolId, array $arguments)` to the correct
handler based on `$toolId`. Unknown tool ids SHALL return a structured error envelope
(NOT throw). Argument validation SHALL run before authorisation, which SHALL run before
business logic.

**Error envelope** — every failure path SHALL return an array of shape:

```php
[
    'isError' => true,
    'error'   => '<machine-readable-code>',
    'message' => '<human-readable explanation>',
]
```

with `error` drawn from the closed enum:
`unknown_tool | invalid_arguments | forbidden | not_found | invalid_state | internal_error`.

#### Scenario: Unknown tool id returns structured error
- **GIVEN** the provider is registered
- **WHEN** `invokeTool('decidesk.doesNotExist', [])` is called
- **THEN** the return value is `{isError: true, error: 'unknown_tool', message: '...'}`
- **AND** no exception is thrown

#### Scenario: Missing required argument returns structured error
- **WHEN** `invokeTool('decidesk.startMeeting', [])` is called (no `meetingUuid`)
- **THEN** the return value is `{isError: true, error: 'invalid_arguments', message: '...'}` mentioning the missing field

#### Scenario: Invalid UUID format returns structured error
- **WHEN** `invokeTool('decidesk.getMeetingDetails', ['meetingUuid' => 'not-a-uuid'])` is called
- **THEN** the return value is `{isError: true, error: 'invalid_arguments', message: '...'}`

#### Scenario: Target object not found returns structured error
- **GIVEN** no meeting exists with uuid `00000000-0000-0000-0000-000000000000`
- **WHEN** `invokeTool('decidesk.getMeetingDetails', ['meetingUuid' => '00000000-0000-0000-0000-000000000000'])` is called
- **THEN** the return value is `{isError: true, error: 'not_found', message: '...'}`

---

### Requirement: REQ-DMCP-004 — Per-object authorisation (ADR-005 IDOR)

The provider SHALL enforce per-object authorisation for every tool that targets a
specific object (`getMeetingDetails`, `startMeeting`, `addActionItem`). The provider
SHALL verify the calling user is authorised against the target BEFORE executing the
action. Authorisation failures SHALL return
`{isError: true, error: 'forbidden', message: '...'}` — the provider SHALL NOT throw,
SHALL NOT leak object existence beyond what `not_found` already exposes, and SHALL NOT
silently degrade to a successful no-op.

**Authorisation matrix:**

| Tool | Allowed callers |
|---|---|
| `listOpenActionItems` | Any authenticated user (results scoped by `scope` argument). |
| `listRecentMeetings` | Any authenticated user (results scoped to meetings they can see). |
| `getMeetingDetails` | Participant of the meeting OR governance-body admin. |
| `startMeeting` | Chair of the meeting OR governance-body admin. |
| `addActionItem` | Participant of the meeting OR governance-body admin. |

The provider MUST NOT rely on `#[NoAdminRequired]` annotations (it is not a controller).
Every authorisation helper invoked MUST actually run — no stub `return true` shortcuts,
no dead code paths.

#### Scenario: Non-chair cannot start meeting
- **GIVEN** a meeting `<uuid>` in state `scheduled` whose chair is user `alice`
- **AND** an authenticated caller `bob` who is a participant but not the chair
- **WHEN** `bob` invokes `decidesk.startMeeting` with `{meetingUuid: '<uuid>'}`
- **THEN** the return value is `{isError: true, error: 'forbidden', message: 'Only the chair or an admin can start this meeting.'}`
- **AND** the meeting remains in state `scheduled`

#### Scenario: Non-participant cannot read meeting details
- **GIVEN** a meeting `<uuid>` with participants `alice, bob`
- **AND** an authenticated caller `carol` who is neither a participant nor an admin
- **WHEN** `carol` invokes `decidesk.getMeetingDetails` with `{meetingUuid: '<uuid>'}`
- **THEN** the return value is `{isError: true, error: 'forbidden', message: '...'}`

#### Scenario: Admin can start any meeting
- **GIVEN** a meeting `<uuid>` in state `scheduled`
- **AND** an authenticated caller with governance-body admin rights for the owning body
- **WHEN** the admin invokes `decidesk.startMeeting` with `{meetingUuid: '<uuid>'}`
- **THEN** the meeting transitions to `in-progress` and the return payload reports success

---

### Requirement: REQ-DMCP-005 — `startMeeting` state-machine guard

The system SHALL reject `decidesk.startMeeting` calls that target a meeting whose
current state is not `scheduled`. The provider SHALL return
`{isError: true, error: 'invalid_state', message: '...'}` describing the actual state.
On success the provider SHALL transition the meeting via the existing
`MeetingService::startMeeting()` code path (no new lifecycle code), recording the
transition in OpenRegister's audit trail.

#### Scenario: Cannot start an already in-progress meeting
- **GIVEN** a meeting `<uuid>` in state `in-progress` and a caller who is the chair
- **WHEN** the chair invokes `decidesk.startMeeting` with `{meetingUuid: '<uuid>'}`
- **THEN** the return value is `{isError: true, error: 'invalid_state', message: 'Meeting is already in progress.'}`

#### Scenario: Successful start returns structured payload
- **GIVEN** a meeting `<uuid>` in state `scheduled` and a caller who is the chair
- **WHEN** the chair invokes `decidesk.startMeeting` with `{meetingUuid: '<uuid>'}`
- **THEN** the return value contains `started: true`, `meetingUuid: '<uuid>'`, an ISO 8601 `startedAt` timestamp, and a `sources` array (see REQ-DMCP-006)

---

### Requirement: REQ-DMCP-006 — Mandatory `sources` array for inline citations

Every successful (`isError` absent or `false`) tool result SHALL include a `sources` key
holding an array of inline-citation descriptors. Each descriptor SHALL be an associative
array with the keys `type`, `uuid`, `url`, and `label`. The chat companion widget's
`CnAiMessageList` (in `@conduction/nextcloud-vue`) renders these as inline `[label]`
links.

**Source descriptor shape:**

```yaml
type:  string  # 'decidesk.meeting' | 'decidesk.agendaItem' | 'decidesk.decision' | 'decidesk.actionItem'
uuid:  string  # the object's UUID
url:   string  # deep link, e.g. '/apps/decidesk/meetings/<uuid>'
label: string  # human-readable label (meeting title, action-item title, etc.)
```

**Per-tool source contracts:**

| Tool | Sources contents |
|---|---|
| `listOpenActionItems` | One `decidesk.actionItem` source per returned item. |
| `listRecentMeetings` | One `decidesk.meeting` source per returned meeting. |
| `getMeetingDetails` | One `decidesk.meeting` source for the meeting, plus one per inlined agenda item, decision, and action item. |
| `startMeeting` | Exactly one `decidesk.meeting` source for the started meeting. |
| `addActionItem` | One `decidesk.actionItem` source for the new item, plus one `decidesk.meeting` source for its parent meeting. |

**Truncation:** if a tool result would carry more than 20 source descriptors, the
provider SHALL truncate to the first 20 and append a `sourcesTruncated: true` key plus
a `sourcesTotalCount: <int>` key at the top level of the result so the LLM can mention
the truncation.

#### Scenario: Every success response includes a sources array
- **WHEN** any of the 5 tools returns a success payload
- **THEN** the payload contains a `sources` key whose value is an array
- **AND** every element of `sources` has non-empty `type`, `uuid`, `url`, and `label` keys

#### Scenario: getMeetingDetails sources include sub-objects
- **GIVEN** a meeting `<uuid>` with 3 agenda items, 1 decision, and 2 action items
- **WHEN** `getMeetingDetails` is invoked successfully
- **THEN** the `sources` array contains 7 descriptors: 1 meeting, 3 agenda items, 1 decision, 2 action items
- **AND** each `type` value matches the descriptor's object kind

#### Scenario: Sources are truncated at the cap
- **GIVEN** a tool result would include 35 source descriptors
- **WHEN** the tool returns
- **THEN** the `sources` array has length 20
- **AND** the top-level result has `sourcesTruncated: true` and `sourcesTotalCount: 35`

---

### Requirement: REQ-DMCP-007 — UUID validation and not_found semantics

The provider SHALL validate that any `*Uuid` argument is a syntactically valid UUID
(8-4-4-4-12 hex with hyphens) before dispatching to a service. Invalid UUID strings
SHALL return `{isError: true, error: 'invalid_arguments', message: '...'}`. UUIDs that
parse correctly but reference no existing object SHALL return
`{isError: true, error: 'not_found', message: '...'}` AFTER an authorisation pre-check
that does not leak existence by message text.

#### Scenario: Syntactically invalid UUID is rejected before lookup
- **WHEN** `invokeTool('decidesk.startMeeting', ['meetingUuid' => 'abc'])` is called
- **THEN** the return value is `{isError: true, error: 'invalid_arguments', message: '...'}`
- **AND** the underlying `MeetingService` is not queried

#### Scenario: Valid UUID with no matching object returns not_found
- **GIVEN** no meeting exists with uuid `00000000-0000-0000-0000-000000000000`
- **WHEN** `invokeTool('decidesk.getMeetingDetails', ['meetingUuid' => '00000000-0000-0000-0000-000000000000'])` is called
- **THEN** the return value is `{isError: true, error: 'not_found', message: 'Meeting not found.'}`

---

### Requirement: REQ-DMCP-008 — Service reuse via DI

The provider SHALL delegate to existing decidesk services via constructor injection. The
provider MUST NOT issue HTTP calls, MUST NOT instantiate services with `new`, and MUST
NOT duplicate business logic that already lives in `MeetingService`, `AgendaService`, or
`TaskService` (action items).

| Tool | Primary service | Notes |
|---|---|---|
| `listOpenActionItems` | `TaskService` | Filter `completed = false`, scope by user when `scope=mine`. |
| `listRecentMeetings` | `MeetingService` | Visibility filter applied per current user. |
| `getMeetingDetails` | `MeetingService` + `AgendaService` + `TaskService` | Compose agenda + decisions + action items into result. |
| `startMeeting` | `MeetingService::startMeeting()` | Existing transition code path; provider only authorises and adapts the result. |
| `addActionItem` | `TaskService::createForMeeting()` (or equivalent existing method) | Validate fields, delegate creation. |

#### Scenario: Provider delegates startMeeting to MeetingService
- **GIVEN** the provider is invoked with a valid `decidesk.startMeeting` call
- **WHEN** the provider passes authorisation and validation
- **THEN** it calls `MeetingService::startMeeting()` exactly once with the resolved meeting
- **AND** it does NOT directly mutate any OpenRegister object

---

### Requirement: REQ-DMCP-009 — Composer dependency on openregister

The system SHALL declare a composer dependency on `openregister/openregister` at the
minimum version that publishes `OCA\OpenRegister\Mcp\IMcpToolProvider`. The dependency
SHALL appear in `composer.json` under `require` (not `require-dev`) because the
interface is referenced from production code in `lib/Mcp/DecideskToolProvider.php`.

The existing test stub at `tests/Stubs/` SHALL be retained for unit-test scenarios where
the full openregister runtime is unavailable.

#### Scenario: composer.json declares the openregister requirement
- **WHEN** `composer.json` is inspected
- **THEN** `require` contains an entry for `openregister/openregister` with a version constraint that includes the `IMcpToolProvider` release

---

### Requirement: REQ-DMCP-010 — Test coverage

The system SHALL ship the following automated tests:

1. **Unit tests** at `tests/Unit/Mcp/DecideskToolProviderTest.php` covering:
   - `getAppId()` returns `"decidesk"`.
   - `getTools()` returns exactly 5 descriptors with the canonical ids.
   - Every tool id is namespaced under `decidesk.`.
   - Each tool's happy path (mocked services).
   - Each tool's auth-failure path returns `forbidden`.
   - Invalid UUID returns `invalid_arguments`.
   - Unknown tool id returns `unknown_tool`.
   - `startMeeting` returns `invalid_state` when the meeting is not `scheduled`.
   - Every success response carries a non-empty `sources` array of the right shape.
   - Source truncation kicks in past 20 descriptors.

2. **Integration test** at `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`
   exercising one full happy-path round-trip (e.g. `decidesk.startMeeting`) through the
   real Nextcloud DI container against a real Meeting fixture, asserting the post-call
   state mutation and the structure of the returned payload.

3. The `composer check:strict` script SHALL exit zero after this change.

#### Scenario: Test suite covers every tool id
- **WHEN** the unit test class is run
- **THEN** the test runner reports at least one passing test per tool id (5 minimum) and at least one passing auth-failure test per object-targeting tool (3 minimum)

#### Scenario: composer check:strict is clean
- **WHEN** `composer check:strict` is run after the change is applied
- **THEN** the script exits with status 0
- **AND** PHPCS, PHPMD, Psalm, PHPStan, and PHPUnit all report no issues attributable to the new code

