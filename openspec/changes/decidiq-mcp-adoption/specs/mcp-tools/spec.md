# Spec Delta: mcp-tools — ADR-063 adoption

Decidiq's MCP surface moves from a hand-written `IMcpToolProvider` to OpenRegister's declarative + attribute-driven chains (ADR-063). The provider is deleted; two curated tools survive on real services; one write tool is withdrawn from the agent surface entirely.

## ADDED Requirements

### Requirement: REQ-DMCP-011 — Declarative CRUD via the `x-openregister-mcp` dialect

Decidiq MUST declare its agent-visible CRUD surface with the `x-openregister-mcp` schema dialect and MUST NOT hand-write any MCP CRUD tool. Exactly ten schemas opt in — `meeting`, `decision`, `agenda-item`, `action-item`, `minutes`, `governance-body`, `person`, `membership`, `voting-round`, `conflict-of-interest` — each declaring `enabled: true` and the verbs `search` and `get` only. Every verb config SHALL declare `scope: read`, `readOnlyHint: true`, and an agent-facing `description`. Every entry in a `search.filters` list SHALL be a real property of that schema. All other Decidiq schemas SHALL omit the dialect (default OFF).

The block is placed at schema root; `Schema::hydrate()` folds root-level `x-openregister-*` keys into `configuration`, where `SchemaMapper` validates them and `SchemaDerivedToolProvider` reads them.

#### Scenario: Ten schemas opt in, and no more
- **GIVEN** `lib/Settings/decidesk_register.json` after this change
- **WHEN** every schema's `x-openregister-mcp.enabled` value is collected
- **THEN** exactly 10 schemas declare `enabled: true`
- **AND** they are `meeting`, `decision`, `agenda-item`, `action-item`, `minutes`, `governance-body`, `person`, `membership`, `voting-round`, `conflict-of-interest`

#### Scenario: Derived read tools are emitted
- **GIVEN** the register has been imported
- **WHEN** the MCP tool catalogue is listed
- **THEN** it contains `decidesk.meeting.search`, `decidesk.meeting.get`, `decidesk.action-item.search`, and `decidesk.decision.search`
- **AND** every derived tool id matches `decidesk.{slug}.{verb}`

#### Scenario: Every declared search filter is a real schema property
- **GIVEN** any opted-in schema declaring `tools.search.filters`
- **WHEN** each filter name is looked up in that schema's `properties` block
- **THEN** the property exists
- **AND** the register imports without an `mcp-unknown-filter` validation error

#### Scenario: No schema declares a write verb
- **GIVEN** the ten opted-in schemas
- **WHEN** their `tools` keys are collected
- **THEN** no schema declares `create`, `update`, or `delete`

### Requirement: REQ-DMCP-012 — Curated non-CRUD tools live on services with honest annotations

Every retained non-CRUD tool MUST be a `#[McpTool]`-annotated method on a real service class, and MUST declare a `scope` and explicit hints. Exactly two curated tools survive: `MeetingService::getMeetingDossier()` (a read aggregation over meeting + agenda items + decisions + action items) and `ActionItemWriter::addActionItemToMeeting()` (the sole write on Decidiq's agent surface). Business logic MUST NOT live in an MCP provider class.

| Method | scope | readOnlyHint | destructiveHint | idempotentHint |
|---|---|---|---|---|
| `MeetingService::getMeetingDossier()` | `read` | `true` | `false` | `true` |
| `ActionItemWriter::addActionItemToMeeting()` | `create` | `false` | `false` | `false` |

#### Scenario: The dossier tool aggregates four schemas in one call
- **GIVEN** a meeting `<uuid>` with 3 agenda items, 1 decision, and 2 action items
- **AND** an authenticated caller who is a participant of that meeting
- **WHEN** the caller invokes the curated dossier tool with `{meetingUuid: '<uuid>'}`
- **THEN** the payload contains the meeting plus all 3 agenda items, 1 decision, and 2 action items

#### Scenario: The write tool declares create scope and non-destructive hints
- **GIVEN** the MCP tool catalogue
- **WHEN** the descriptor for the curated add-action-item tool is read
- **THEN** it reports `scope: 'create'`, `readOnlyHint: false`, and `destructiveHint: false`
- **AND** it is classified as a write tool by the consuming agent's approval gate

#### Scenario: No curated tool ships without a scope
- **GIVEN** every `#[McpTool]` attribute in `lib/`
- **WHEN** its arguments are inspected
- **THEN** each declares a non-null `scope`
- **AND** each declares an explicit `readOnlyHint`

### Requirement: REQ-DMCP-013 — Scannable-services opt-in

Decidiq MUST register an `IMcpScannableServices` implementation naming every class that carries a `#[McpTool]`. `lib/Mcp/DecidiqScannableServices.php` SHALL return `[MeetingService::class, ActionItemWriter::class]` from `getScannableServiceClasses()`, and `Application.php` SHALL register it. Without this opt-in, OpenRegister never scans the app and every curated tool is silently absent.

#### Scenario: The opt-in lists exactly the annotated classes
- **WHEN** `DecidiqScannableServices::getScannableServiceClasses()` is called
- **THEN** it returns exactly `MeetingService::class` and `ActionItemWriter::class`
- **AND** every class it names contains at least one `#[McpTool]` method

#### Scenario: Curated tools are discoverable at runtime
- **GIVEN** the app is booted
- **WHEN** the MCP tool catalogue is listed
- **THEN** both curated tool ids are present

### Requirement: REQ-DMCP-014 — `action-item` is read-only on the derived surface

The `action-item` schema MUST NOT declare a `create`, `update`, or `delete` verb, because it is a read-only OpenRegister projection over CalDAV VTODOs. `ActionItem` carries `x-openregister-object-source: {provider: "caldav-vtodo", readOnly: true}`; the VTODO is the authoritative record (ADR-002) and `ObjectService::saveObject()` rejects writes to the projection. A derived write tool would therefore be permanently broken. All action-item writes SHALL route through `ActionItemWriter`.

#### Scenario: The derived surface exposes no action-item write tool
- **GIVEN** the MCP tool catalogue
- **WHEN** tool ids beginning `decidesk.action-item.` are collected
- **THEN** only `decidesk.action-item.search` and `decidesk.action-item.get` are present
- **AND** no `decidesk.action-item.create`, `.update`, or `.delete` tool exists

## MODIFIED Requirements

### Requirement: REQ-DMCP-004 — Per-object authorisation (ADR-005 IDOR)

Every curated tool that targets a specific object MUST enforce per-object authorisation in its receiving SERVICE before executing, and OpenRegister RBAC SHALL remain the authoritative gate for every derived tool. Authorisation logic MUST NOT live in an MCP provider class. Authorisation failures SHALL NOT throw, SHALL NOT leak object existence beyond what `not_found` already exposes, and SHALL NOT silently degrade to a successful no-op. Every authorisation helper invoked MUST actually run — no stub `return true` shortcuts, no dead code paths.

**Authorisation matrix (post-ADR-063):**

| Tool | Surface | Allowed callers |
|---|---|---|
| `decidesk.{slug}.search` / `.get` (10 schemas) | derived | Enforced by OpenRegister RBAC + multitenancy at invoke time. |
| `MeetingService::getMeetingDossier()` | curated | Participant of the meeting OR governance-body admin. |
| `ActionItemWriter::addActionItemToMeeting()` | curated | Participant of the meeting OR governance-body admin. |

The participant/chair guards previously private to `DecidiqToolProvider` SHALL move into the receiving services intact — no guard is weakened or dropped by this change.

#### Scenario: Non-participant cannot read a meeting dossier
- **GIVEN** a meeting `<uuid>` with participants `alice, bob`
- **AND** an authenticated caller `carol` who is neither a participant nor an admin
- **WHEN** `carol` invokes the curated dossier tool with `{meetingUuid: '<uuid>'}`
- **THEN** the call is refused with a `forbidden` error and no meeting content is returned

#### Scenario: Non-participant cannot add an action item
- **GIVEN** a meeting `<uuid>` and an authenticated caller who is not a participant or admin
- **WHEN** the caller invokes the curated add-action-item tool
- **THEN** the call is refused with a `forbidden` error
- **AND** no VTODO is created

#### Scenario: The guard runs in the service, not the provider
- **GIVEN** the codebase after this change
- **WHEN** `lib/Mcp/` is searched for authorisation helpers
- **THEN** no authorisation logic is found there
- **AND** the participant guard is present in the receiving service

### Requirement: REQ-DMCP-010 — Test coverage

The system MUST ship automated tests for both surviving MCP surfaces, and MUST NOT retain tests for the deleted provider.

1. **Unit tests** covering:
   - `DecidiqScannableServices::getScannableServiceClasses()` returns exactly the two annotated classes.
   - Every `#[McpTool]` in `lib/` declares a non-null `scope` and an explicit `readOnlyHint`.
   - `getMeetingDossier()` happy path (mocked services) and its `forbidden` path.
   - `addActionItemToMeeting()` happy path (mocked writer) and its `forbidden` path.
   - Exactly 10 schemas in `decidesk_register.json` declare `x-openregister-mcp.enabled: true`.
   - Every declared `search.filters` entry names a real property of its schema.
   - No schema declares a `create`, `update`, or `delete` verb.
2. `tests/Unit/Mcp/DecidiqToolProviderTest.php` and `tests/Integration/Mcp/DecidiqToolProviderIntegrationTest.php` SHALL be deleted along with the provider.
3. The `composer check:strict` script SHALL exit zero after this change.

#### Scenario: Register dialect is asserted from the JSON
- **WHEN** the unit test suite runs
- **THEN** a test parses `lib/Settings/decidesk_register.json` and asserts the 10-schema opt-in set
- **AND** a test asserts every `search.filters` entry resolves to a declared property

#### Scenario: composer check:strict is clean
- **WHEN** `composer check:strict` is run after the change is applied
- **THEN** the script exits with status 0

## REMOVED Requirements

### Requirement: REQ-DMCP-001 — Implement IMcpToolProvider

**Reason**: ADR-063 forbids hand-written MCP tool code. OpenRegister's `SchemaDerivedToolProvider` and `AttributeToolProvider` now supply the entire surface; `lib/Mcp/DecidiqToolProvider.php` is deleted.

**Migration**: The provider class and its service alias are removed from `Application.php`. `lib/Mcp/DecidiqScannableServices.php` (REQ-DMCP-013) replaces it as the app's only MCP registration.

### Requirement: REQ-DMCP-002 — Tool catalogue (v1)

**Reason**: The hard-coded 5-tool `TOOL_DESCRIPTORS` constant is deleted. The catalogue is now derived from the schema dialect (20 read tools) plus two `#[McpTool]` methods.

**Migration**: `listOpenActionItems` → `decidesk.action-item.search`; `listRecentMeetings` → `decidesk.meeting.search`; `getMeetingDetails` → the curated dossier tool; `addActionItem` → the curated add-action-item tool; `startMeeting` → **withdrawn, see REQ-DMCP-005**.

### Requirement: REQ-DMCP-003 — `invokeTool()` dispatch and error envelope

**Reason**: Dispatch, argument validation, UUID validation, and the error envelope are OpenRegister's responsibility for derived tools and the `AttributeToolProvider`'s for curated ones. Decidiq hand-rolled all four.

**Migration**: No app-side dispatch remains. Callers receive OpenRegister's standard MCP error envelope.

### Requirement: REQ-DMCP-005 — `startMeeting` state-machine guard

**Reason**: `decidesk.startMeeting` is **withdrawn from the agent surface entirely** — not migrated. Opening a meeting writes an irreversible `openedAt` timestamp to the official record, starts the meeting-cost clock, and fires the lifecycle notification fan-out to the whole governance body. The existing chair guard does not mitigate the real risk: the MCP caller *is* the chair, so the guard passes on every mis-resolved UUID an assistant hallucinates. It defends against the wrong user, not the wrong object. As a 2-segment curated tool declaring no `scope` and no `destructiveHint`, it is additionally **failing open** today — never stripped by default-deny, never gated for approval.

**Migration**: None — the capability is deliberately not replaced on the agent surface. `MeetingService::transition()` is untouched and the UI path is unchanged; a chair opens a meeting with one click. No schema declares a write verb, so no derived tool resurrects this behaviour.

### Requirement: REQ-DMCP-006 — Mandatory `sources` array for inline citations

**Reason**: The `sources` envelope, the 20-descriptor truncation cap, and the deep-link builder were provider-private implementations of what OpenRegister's MCP response envelope now provides uniformly across every app.

**Migration**: Derived tools return OpenRegister's standard envelope. The curated dossier tool returns object identifiers from which the chat companion resolves citations.

### Requirement: REQ-DMCP-007 — UUID validation and not_found semantics

**Reason**: Hand-rolled `isValidUuid()` / `isValidDate()` helpers duplicated validation OpenRegister performs for every derived and attributed tool.

**Migration**: Validation is inherited from OpenRegister's tool-invocation path; the private helpers are deleted with the provider.

### Requirement: REQ-DMCP-008 — Service reuse via DI

**Reason**: Superseded by REQ-DMCP-012. The tools no longer *delegate to* services — they **are** service methods. The requirement's routing table is also stale: it names the retired `TaskService` and specifies a `completed = false` filter for a property that does not exist on the `ActionItem` schema (the real field is `taskStatus`).

**Migration**: `getMeetingDossier()` lives on `MeetingService`; `addActionItemToMeeting()` lives on `ActionItemWriter` (the ADR-002 canonical CalDAV write path).

### Requirement: REQ-DMCP-009 — Interface resolution via runtime autoloader

**Reason**: Decidiq no longer implements `IMcpToolProvider`, so there is no interface to resolve and no stub to maintain.

**Migration**: `tests/Stubs/Mcp/IMcpToolProvider.php` is replaced by a stub for `IMcpScannableServices` (the one interface the app still implements), following the same runtime-autoloader convention.
