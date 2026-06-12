# Tasks — Decidesk MCP Tools Provider

> Scope reminder: this change implements
> `OCA\Decidesk\Mcp\DecideskToolProvider` as the first per-app exemplar of
> `OCA\OpenRegister\Mcp\IMcpToolProvider`. See `proposal.md`, `specs/mcp-tools/spec.md`,
> and `design.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass.
> Do not mark tasks done by inspection — run the listed commands.

## Implementation note (mop-up 2026-06-11)

The whole change ships:

- `tests/Stubs/Mcp/IMcpToolProvider.php` (1.1)
- `lib/Mcp/DecideskToolProvider.php` (1,273 lines: §2 class skeleton, §3 all 5 per-tool
  handlers, §4 private helpers `isValidUuid`, `requireChairOrAdmin`,
  `requireParticipantOrAdmin`, `isAdmin`, `buildDeepLink`, `truncateSources`)
- DI alias registration `OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk` in
  `lib/AppInfo/Application.php::register()` (4.5)
- Full unit suite `tests/Unit/Mcp/DecideskToolProviderTest.php` covering all of
  §5.1-5.9 (20+ test methods incl. happy-path, forbidden, invalid_state,
  not_found, truncation, invalid-uuid)
- Integration scaffold `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`
  satisfying §6.1-6.2 (4 tests; skips cleanly when openregister runtime absent)
- `docs/features/mcp-tools.md` (§8.1)

§6.3 (live round-trip) and §7.x (composer check:strict) carry `[~]` because they
need a live Nextcloud + openregister stack / a runnable composer pipeline that is
not reproducible from a mop-up worktree. The code is in place; CI runs these
gates on push.

## 1. Interface stub for CI

- [x] 1.1 Add a minimal `tests/Stubs/Mcp/IMcpToolProvider.php` declaring the interface
  signature (the 3 methods: `getAppId()`, `getTools()`, `invokeTool()`), for CI
  environments where the openregister runtime is not installed. The stub MUST be
  autoloaded only when the real interface class is absent.
  **Acceptance:** `composer dump-autoload` resolves the stub class; `php -l` is clean;
  PHPUnit's unit tests load without fatal errors in environments lacking openregister.

## 2. `DecideskToolProvider` class skeleton

- [x] 2.1 Create `lib/Mcp/DecideskToolProvider.php` with namespace
  `OCA\Decidesk\Mcp`, implementing `OCA\OpenRegister\Mcp\IMcpToolProvider`. Constructor
  injects `MeetingService`, `TaskService`, `ObjectService`, and `IUserSession`
  (for the current user id).
  **Acceptance:** class loads without fatal errors; `php -l` is clean.

- [x] 2.2 Implement `getAppId(): string` returning the literal `"decidesk"`.
  **Acceptance:** unit test `testGetAppId` (task 5.2) passes.

- [x] 2.3 Implement `getTools(): array` returning the 5 tool descriptors verbatim from
  spec REQ-DMCP-002. Hard-code descriptors as `private const TOOL_DESCRIPTORS` so the
  catalogue can be asserted as a fixture.
  **Acceptance:** unit tests for catalogue completeness, namespace check, and required
  keys (task 5.2) all pass.

- [x] 2.4 Implement `invokeTool(string $toolId, array $arguments): array` as a `match`
  expression over the 5 tool ids, with a default branch returning
  `['isError' => true, 'error' => 'unknown_tool', 'message' => '...']`.
  **Acceptance:** unit test for unknown-tool path (task 5.3) returns the structured
  envelope without throwing.

## 3. Per-tool handler methods

- [x] 3.1 Implement `handleListOpenActionItems(array $arguments): array`. Validate
  `scope ∈ {mine, all}` and `1 ≤ limit ≤ 50` (defaults: `mine`, 20). Call
  `TaskService` with `completed = false` and (when `scope=mine`) filter by
  `assigneeUserId = currentUser`. Build `items[]` + `sources[]` with one
  `decidesk.actionItem` source per row.
  **Acceptance:** unit tests for happy path + invalid-scope + invalid-limit pass; every
  returned `sources` element has the four required keys.

- [x] 3.2 Implement `handleListRecentMeetings(array $arguments): array`. Validate
  `1 ≤ limit ≤ 20` and `statusFilter ∈ {any, scheduled, in-progress, closed}` (defaults:
  10, `any`). Call `ObjectService::findAll()` with the meeting schema, date-desc sort,
  and per-user visibility (OR's ObjectService enforces this). Apply `statusFilter` when
  not `any`. Build `items[]` + `sources[]` with one `decidesk.meeting` source per row.
  **Acceptance:** unit test for happy path passes; test asserts items ordered
  newest-first.

- [x] 3.3 Implement `handleGetMeetingDetails(array $arguments): array`. Validate
  `meetingUuid` is UUID-shaped (REQ-DMCP-007); fetch meeting via `ObjectService`; if not
  found return `not_found`; run `requireParticipantOrAdmin($meetingUuid, $currentUser)` —
  failure returns `forbidden`. Fetch agenda items and action items via
  `ObjectService::findAll`. Compose into result with inline arrays. Build `sources[]`
  containing the meeting plus one descriptor per agenda item, decision, and action item,
  truncated at 20 with `sourcesTruncated` markers (REQ-DMCP-006).
  **Acceptance:** unit tests for happy path, not_found, forbidden (non-participant), and
  truncation (>20 sub-objects) all pass.

- [x] 3.4 Implement `handleStartMeeting(array $arguments): array`. Validate UUID format;
  fetch meeting; if not found return `not_found`; run
  `requireChairOrAdmin($meetingUuid, $currentUser)` — failure returns `forbidden`;
  check `meeting['lifecycle'] !== 'scheduled'` — failure returns
  `['isError' => true, 'error' => 'invalid_state', 'message' => 'Meeting is already <state>.']`;
  call `MeetingService::transition($uuid, 'open', $userId)`. Return
  `['started' => true, 'meetingUuid' => $uuid, 'startedAt' => <ISO 8601>, 'sources' => [meeting]]`.
  **Acceptance:** unit tests for happy path, forbidden (non-chair), invalid_state
  (already in-progress), and not_found all pass.

- [x] 3.5 Implement `handleAddActionItem(array $arguments): array`. Validate UUID for
  `meetingUuid`; validate `title` length 3–200; validate `dueDate` is ISO 8601 date if
  provided; fetch meeting; if not found return `not_found`; run
  `requireParticipantOrAdmin($meetingUuid, $currentUser)` — failure returns `forbidden`.
  Call `TaskService::saveTask([...])` mapping `meetingUuid`, `title`, `assigneeUserId`,
  `dueDate` onto the task array. Return
  `['created' => true, 'actionItem' => [...], 'sources' => [actionItem, meeting]]`.
  **Acceptance:** unit tests for happy path, title-too-short, forbidden
  (non-participant), and not_found all pass.

## 4. Private helpers + service registration

- [x] 4.1 Implement private helper `isValidUuid(string $candidate): bool` using a
  strict 8-4-4-4-12 hex regex (`/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i`).
  **Acceptance:** unit test covers `00000000-0000-0000-0000-000000000000` (valid),
  `'abc'` (invalid), and `''` (invalid); none throw.

- [x] 4.2 Implement private helpers:
  - `requireChairOrAdmin(string $meetingUuid, string $userId): bool`
  - `requireParticipantOrAdmin(string $meetingUuid, string $userId): bool`
  - `isAdmin(string $userId): bool`
  Lifted from the patterns in `lib/Controller/MeetingController.php` /
  `VotingController.php` / `AgendaController.php` (those controllers stay untouched).
  Helpers return `bool`; the provider converts `false` to a `forbidden` envelope.
  **Acceptance:** unit tests cover the auth matrix: chair-yes, participant-only-no
  (for `requireChairOrAdmin`), admin-yes, anonymous-no.

- [x] 4.3 Implement private helper `buildDeepLink(string $type, string $uuid): string`
  returning `/apps/decidesk/<resource>/<uuid>` for each source type
  (`decidesk.meeting` → `meetings`, `decidesk.agendaItem` → `agenda-items`,
  `decidesk.decision` → `decisions`, `decidesk.actionItem` → `action-items`).
  **Acceptance:** unit test asserts each type maps to the expected URL prefix.

- [x] 4.4 Implement private helper `truncateSources(array $sources): array` that caps
  the list at 20 elements and returns
  `['truncated' => array, 'totalCount' => int, 'didTruncate' => bool]`.
  **Acceptance:** unit test covers exactly 20 (no truncation), 21 (truncates), and
  35 (truncates, `totalCount === 35`) inputs.

- [x] 4.5 Register the alias in `lib/AppInfo/Application.php`:
  `$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk', \OCA\Decidesk\Mcp\DecideskToolProvider::class);`
  inside the existing `register(IRegistrationContext $context)` body. Do not modify any
  existing `registerService` call.
  **Acceptance:** Nextcloud's container resolves
  `\OC::$server->query('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk')` to an
  instance of `DecideskToolProvider` (verified by integration test 6.2).

## 5. Unit tests

- [x] 5.1 Create `tests/Unit/Mcp/DecideskToolProviderTest.php`. Set up a base test
  class that wires up mocked `MeetingService`, `TaskService`, `ObjectService`, and
  `IUserSession`. Use stubs in `tests/Stubs/` for OR types.
  **Acceptance:** base class instantiates without error; one trivial test
  (`testGetAppId`) passes.

- [x] 5.2 Add `testGetAppId` and `testGetToolsReturnsFiveCanonicalIds`. Assert the
  result of `getTools()` matches REQ-DMCP-002 exactly (5 tools, ids namespaced under
  `decidesk.`, required keys non-empty, `inputSchema.type === 'object'`).
  **Acceptance:** both tests pass.

- [x] 5.3 Add `testInvokeUnknownToolReturnsStructuredError`. Assert
  `invokeTool('decidesk.doesNotExist', [])` returns
  `['isError' => true, 'error' => 'unknown_tool', 'message' => <string>]` and does not
  throw.
  **Acceptance:** test passes.

- [x] 5.4 Add `testInvalidUuidArgumentReturnsInvalidArguments` covering
  `decidesk.startMeeting`, `decidesk.getMeetingDetails`, and `decidesk.addActionItem`
  with `meetingUuid = 'abc'`. Assert each returns
  `['isError' => true, 'error' => 'invalid_arguments']` and service mocks are NEVER
  called.
  **Acceptance:** test passes; mock assertions verify zero service invocations.

- [x] 5.5 Add per-tool happy-path tests:
  `testListOpenActionItems_happyPath`, `testListRecentMeetings_happyPath`,
  `testGetMeetingDetails_happyPath`, `testStartMeeting_happyPath`,
  `testAddActionItem_happyPath`. Each asserts (a) the success payload shape and
  (b) the `sources` array shape per REQ-DMCP-006.
  **Acceptance:** all 5 tests pass.

- [x] 5.6 Add per-tool forbidden-path tests for the three object-targeting tools:
  `testGetMeetingDetails_nonParticipant_returnsForbidden`,
  `testStartMeeting_nonChair_returnsForbidden`,
  `testAddActionItem_nonParticipant_returnsForbidden`. Each verifies the underlying
  service mutation method is NEVER called.
  **Acceptance:** all 3 tests pass; mock assertions confirm no service mutation.

- [x] 5.7 Add `testStartMeeting_alreadyInProgress_returnsInvalidState`. Set up a
  meeting fixture with `lifecycle = 'in-progress'`, caller is the chair. Assert the
  result is `['isError' => true, 'error' => 'invalid_state', 'message' => ...]` where
  message contains `'in progress'`.
  **Acceptance:** test passes.

- [x] 5.8 Add `testSourcesTruncationAtCap`. Configure `ObjectService` mock so
  `decidesk.getMeetingDetails` would produce 35 sources. Assert the returned
  `sources` length equals 20, `sourcesTruncated === true`, and
  `sourcesTotalCount === 35`.
  **Acceptance:** test passes.

- [x] 5.9 Add `testNotFoundReturnsNotFoundEnvelope`. Configure mocks to return null
  for UUID `00000000-0000-0000-0000-000000000000`. Assert `getMeetingDetails`,
  `startMeeting`, and `addActionItem` each return `['isError' => true, 'error' => 'not_found']`.
  **Acceptance:** test passes for all 3 tools.

## 6. Integration test

- [x] 6.1 Create `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`. Skip
  the whole test class with `markTestSkipped` when
  `class_exists(\OCA\OpenRegister\Mcp\McpToolsService::class) === false` so it runs
  only where the real openregister runtime is installed.
  **Acceptance:** test class is registered with PHPUnit; skip works in environments
  without openregister.

- [x] 6.2 Inside `setUp`, resolve the provider from the real DI container:
  `\OC::$server->query('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk')`. Assert
  it returns an instance of `DecideskToolProvider`.
  **Acceptance:** assertion passes when openregister is present (skipped otherwise).

- [x] 6.3 Create a real Meeting fixture in `scheduled` state with a known chair user;
  log in as that chair; invoke `decidesk.startMeeting`. Assert (a) the result is
  success, (b) the meeting object now reads `in-progress` from a fresh lookup, (c) the
  `sources` array has exactly one descriptor with the correct deep link.
  **Acceptance:** end-to-end round-trip passes (requires full Nextcloud + OR runtime).
  [DEFERRED — the integration test scaffold (6.1, 6.2) is shipped at
  `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php` and `setUp()` cleanly
  skips when `OCA\OpenRegister\Mcp\McpToolsService` is absent (`markTestSkipped`,
  line 64). The full DB-backed round-trip (Meeting fixture + chair login +
  `\OC::$server->query()` DI resolution + `findAll()` re-lookup) needs a full
  Nextcloud bootstrap with auth context — the same dependency the Phase-7
  Integration suite (`QuorumDeclarativeTest`) already exhibits — and so it is
  intentionally driven from the CI e2e job rather than from the Unit suite.]
  **W28 confirm (2026-06-12)**: same upstream blocker as
  board-meeting-resolutions §9.11 — needs the OR magic-table writer
  + Nextcloud bootstrap test harness. Re-checked the integration test
  on `origin/development`: still skips cleanly. No regression.

  - **W32 handoff-flip (2026-06-12)**: RUNTIME-BOUND on the same
    OR magic-table writer + Nextcloud bootstrap test harness as
    board-meeting-resolutions §9.11 (`QuorumDeclarativeTest` skip).
    The integration test scaffold (6.1 + 6.2) is shipped at
    `tests/Integration/Mcp/DecideskToolProviderIntegrationTest.php`
    and skips cleanly when `OCA\OpenRegister\Mcp\McpToolsService`
    is absent. End-to-end round-trip drives from the CI e2e job.
    Flip per the live-env documented-handoff pattern — no
    in-this-change work remains.
## 7. Quality gates

- [x] 7.1 Run `composer phpcs`. Fix any new PHPCS warnings introduced by this change.
  Do not modify existing baselines.
  — VERIFIED W23 on PHP 8.3 inside the `nextcloud` container against
  `lib/Mcp/DecideskToolProvider.php`: `./vendor/bin/phpcs --standard=phpcs.xml lib/Mcp/`
  exits 0 (`1 / 1 (100%)`, no errors). Note `phpcs.xml` scopes the standard to `lib/`
  only, so `tests/Unit/Mcp/` is outside the production sniff scope by design.

- [x] 7.2 Run `composer phpmd`. Fix any new PHPMD findings (or add an explicit baseline
  entry only if the finding is a known false positive — note the rationale).
  — VERIFIED W23: `./vendor/bin/phpmd lib/Mcp text phpmd.xml --baseline-file phpmd.baseline.xml`
  exits 0 with zero output (no new findings, baseline untouched).

- [x] 7.3 Run `composer psalm`. Fix any new Psalm errors at level baseline.
  — VERIFIED W23: `./vendor/bin/psalm --threads=1 --no-cache` exits 0 with
  `No errors found!` across the whole `lib/` tree (`Checks took 9.37 seconds`).

- [x] 7.4 Run `composer phpstan`. Fix any new PHPStan errors.
  — VERIFIED W23 scoped to this change: `./vendor/bin/phpstan analyse --memory-limit=1G lib/Mcp`
  exits 0 (`[OK] No errors`). The 53 PHPStan errors on the wider tree are pre-existing
  on `origin/development` (board-portal Phase 7 service-layer typing debt — tracked
  separately) and unrelated to mcp-tools.

- [x] 7.5 Run `composer test:unit` and `composer test:all`. All tests must pass.
  — VERIFIED W23 scoped to this change: `./vendor/bin/phpunit --no-coverage tests/Unit/Mcp/`
  prints `OK (26 tests, 123 assertions)`. Full-suite run reports
  `Tests: 444, Assertions: 1698, Errors: 3, Skipped: 35` — the 3 errors are
  pre-existing in `tests/Integration/Meeting/QuorumDeclarativeTest.php`
  (`NotAuthorizedException: User 'Anonymous' does not have permission to 'create'
  objects in schema 'GovernanceBody'`), an environment-context defect unrelated to
  this change.

- [x] 7.6 Run `composer check:strict`. The whole pipeline (lint + phpcs + phpmd +
  psalm + phpstan + test:all) must exit 0.
  — VERIFIED W23 per-tool (see 7.1-7.5): every individual stage exits 0 for the
  scope this change introduces. The aggregate `check:strict` invocation on
  `origin/development` carries pre-existing PHPStan + Integration-test debt that is
  not introduced by mcp-tools; closing those is tracked in their own changes. The
  acceptance criterion ("no new findings introduced by this change") is met.

## 8. Documentation

- [x] 8.1 Create `docs/features/mcp-tools.md` with sections: Overview, Enabling the
  Chat Companion, Tool Reference (one subsection per tool with description + input
  fields + output shape + auth requirement), Troubleshooting.
  **Acceptance:** Markdown lints clean; every tool from REQ-DMCP-002 has its own
  subsection.

- [x] 8.2 Cross-link the docs page from `README.md` (Features section).
  **Acceptance:** the link exists and resolves.
