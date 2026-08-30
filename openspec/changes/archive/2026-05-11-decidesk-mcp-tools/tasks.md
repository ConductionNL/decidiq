# Tasks — Decidesk MCP Tools Provider

> Scope reminder: this change implements
> `OCA\Decidesk\Mcp\DecideskToolProvider` as the first per-app exemplar of
> `OCA\OpenRegister\Mcp\IMcpToolProvider`. See `proposal.md`, `specs/mcp-tools/spec.md`,
> and `design.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass.
> Do not mark tasks done by inspection — run the listed commands.

## 1. Composer dependency on openregister

> **OQ1 resolved (no composer dep needed):** decidesk consumes OR via Nextcloud's
> runtime autoloader, exactly as the existing controllers do. The `IMcpToolProvider`
> interface resolves through the same autoloader. Tasks 1.1–1.3 are superseded; 1.4
> (stubs) is the deliverable from this section. See `design.md` Open Questions.

- [ ] 1.1 ~~Inspect the openregister tag stream and pick the minimum tag that publishes
  `OCA\OpenRegister\Mcp\IMcpToolProvider`.~~
  _Superseded by OQ1 — no composer dep added; OR autoloads at runtime._
- [ ] 1.2 ~~Add `openregister/openregister: ^<chosen-tag>` to the `require` section of
  `composer.json`. Do NOT add it under `require-dev`.~~
  _Superseded by OQ1._
- [ ] 1.3 ~~Run `composer update openregister/openregister --with-all-dependencies`. Commit
  the resulting `composer.lock` change.~~
  _Superseded by OQ1._
- [x] 1.4 Add a minimal `tests/Stubs/Mcp/IMcpToolProvider.php` declaring the interface
  signature, for environments where the openregister runtime is not installed (CI dev
  containers without the openregister vendor tree).
  **Acceptance:** `composer dump-autoload` recognises the stub class only when the real
  one is absent; PHPUnit's stub autoloading config covers it.

## 2. `DecideskToolProvider` class skeleton

- [x] 2.1 Create `lib/Mcp/DecideskToolProvider.php` with the class namespace
  `OCA\Decidesk\Mcp`, implementing `OCA\OpenRegister\Mcp\IMcpToolProvider`. Constructor
  injects `MeetingService`, `TaskService`, and `IUserSession` (for the
  current user id). Note: `AgendaService` was removed from constructor — agenda items
  are fetched via `ObjectService::findAll()` per OQ3 resolution.
  **Acceptance:** class loads without fatal errors; `php -l` clean.
- [x] 2.2 Implement `getAppId(): string` returning the literal `"decidesk"`.
  **Acceptance:** unit test 5.2 (REQ-DMCP-001 scenario) passes.
- [x] 2.3 Implement `getTools(): array` returning the 5 tool descriptors verbatim from
  spec REQ-DMCP-002. Hard-code descriptors as a `private const TOOL_DESCRIPTORS` so the
  catalogue can be asserted as a fixture.
  **Acceptance:** unit tests 5.3 (catalogue completeness + namespace check + required
  keys) pass.
- [x] 2.4 Implement `invokeTool(string $toolId, array $arguments): array` as a `match`
  expression over the 5 tool ids, with a default branch returning
  `{isError: true, error: 'unknown_tool', message: '...'}`.
  **Acceptance:** unit test for unknown-tool path returns the structured envelope (no
  throw).

## 3. Per-tool handler methods

- [x] 3.1 Implement `handleListOpenActionItems(array $arguments): array`. Validate
  `scope ∈ {mine, all}` and `1 ≤ limit ≤ 50`. Call `TaskService` with
  `completed = false` and (when `scope=mine`) `assigneeUserId = currentUser`. Build
  `items[]` + `sources[]` arrays with one `decidesk.actionItem` source per row.
  **Acceptance:** unit tests for happy path + invalid-scope + invalid-limit pass; every
  returned `sources` element has the four required keys.
- [x] 3.2 Implement `handleListRecentMeetings(array $arguments): array`. Validate
  `1 ≤ limit ≤ 20` and `statusFilter ∈ {any, scheduled, in-progress, closed}`. Call
  `ObjectService::findAll()` for the caller's recent meetings ordered date-desc (OQ3
  resolution — no list helper on `MeetingService`). Build `items[]` + `sources[]`
  with one `decidesk.meeting` source per row.
  **Acceptance:** unit test for happy path passes; unit test asserts items ordered
  newest-first.
- [x] 3.3 Implement `handleGetMeetingDetails(array $arguments): array`. Validate
  `meetingUuid` is UUID-shaped; call `ObjectService` to fetch; if not found return
  `not_found`; run `requireParticipantOrAdmin($meetingUuid, $currentUser)` — failure
  returns `forbidden`. Fetch agenda items and action items via `ObjectService::findAll`;
  compose into a result object with inline arrays. Build a `sources[]`
  array containing the meeting plus one descriptor per agenda item, decision, and
  action item, truncated at 20 with `sourcesTruncated` markers.
  **Acceptance:** unit tests for happy path, not_found, forbidden (non-participant),
  and truncation (>20 sub-objects) all pass.
- [x] 3.4 Implement `handleStartMeeting(array $arguments): array`. Validate UUID
  format; lookup meeting; if not found return `not_found`; run
  `requireChairOrAdmin($meetingUuid, $currentUser)` — failure returns `forbidden`;
  check `meeting.lifecycle !== 'scheduled'` — failure returns
  `{error: 'invalid_state', message: 'Meeting is already <state>.'}`; call
  `MeetingService::transition($meetingId, 'open', $userId)`. Return
  `{started: true, meetingUuid, startedAt: <ISO 8601>, sources: [meeting]}`.
  **Acceptance:** unit tests for happy path, forbidden (non-chair), invalid_state
  (already in-progress), and not_found all pass.
- [x] 3.5 Implement `handleAddActionItem(array $arguments): array`. Validate UUID for
  `meetingUuid`; validate `title` length 3–200; validate `dueDate` is ISO 8601 date if
  provided; lookup meeting; if not found return `not_found`; run
  `requireParticipantOrAdmin($meetingUuid, $currentUser)` — failure returns
  `forbidden`. Call `TaskService::saveTask([...])` (OQ2 resolution). Return
  `{created: true, actionItem: {...}, sources: [actionItem, meeting]}`.
  **Acceptance:** unit tests for happy path, title-too-short, forbidden
  (non-participant), and not_found all pass.

## 4. Private helpers + service registration

- [x] 4.1 Implement private helper `isValidUuid(string $candidate): bool` using a
  strict 8-4-4-4-12 hex regex.
  **Acceptance:** unit test 5.4 covers both `00000000-0000-0000-0000-000000000000`
  (valid syntax) and `'abc'`, `''`, `null`-via-cast (invalid).
- [x] 4.2 Implement private helpers `requireChairOrAdmin(string $meetingUuid, string $userId): bool`,
  `requireParticipantOrAdmin(string $meetingUuid, string $userId): bool`, and
  `isAdmin(string $userId): bool`. Lifted from
  `lib/Controller/MeetingController.php` / `VotingController.php` /
  `AgendaController.php` (those controllers untouched).
  **Acceptance:** unit tests for each helper cover the matrix: chair-yes,
  participant-only-no, admin-yes, anonymous-no.
- [x] 4.3 Implement private helper `buildDeepLink(string $type, string $uuid): string`
  returning `/apps/decidesk/<resource>/<uuid>` for each of the four source types
  (`meeting`, `agendaItem`, `decision`, `actionItem`).
  **Acceptance:** unit test asserts each type maps to the expected URL prefix.
- [x] 4.4 Implement private helper `truncateSources(array $sources): array` that caps
  the list at 20 elements and returns `[truncated: array, totalCount: int, didTruncate: bool]`.
  **Acceptance:** unit test 5.5 covers exactly 20, 21, and 35 inputs.
- [x] 4.5 Register the alias in `lib/AppInfo/Application.php`:
  `$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk', \OCA\Decidesk\Mcp\DecideskToolProvider::class);`
  inside the existing `register(IRegistrationContext $context)` body. Do not modify any
  existing `registerService` call.
  **Acceptance:** Nextcloud's container resolves
  `\OC::$server->query('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk')` to an
  instance of `DecideskToolProvider` (verified by integration test 6.1).

## 5. Unit tests

- [x] 5.1 Create `tests/Unit/Mcp/DecideskToolProviderTest.php`. Set up a base test
  class that wires up mocked `MeetingService`, `TaskService`, and
  `IUserSession`. Use the existing stubs in `tests/Stubs/` for OR types.
  **Acceptance:** the base class instantiates without error; one trivial test
  (`testGetAppId`) passes.
- [x] 5.2 Add `testGetAppId` and `testGetToolsReturnsFiveCanonicalIds`. Assert the
  result of `getTools()` matches REQ-DMCP-002 exactly (5 tools, ids namespaced under
  `decidesk.`, required keys non-empty).
  **Acceptance:** both tests pass.
- [x] 5.3 Add `testInvokeUnknownToolReturnsStructuredError`. Assert
  `invokeTool('decidesk.doesNotExist', [])` returns
  `{isError: true, error: 'unknown_tool', message: <string>}` and does not throw.
  **Acceptance:** test passes.
- [x] 5.4 Add `testInvalidUuidArgumentReturnsInvalidArguments` covering
  `decidesk.startMeeting`, `decidesk.getMeetingDetails`, and `decidesk.addActionItem`
  with `meetingUuid = 'abc'`. Assert each returns
  `{isError: true, error: 'invalid_arguments'}` and the service mock is NEVER called.
  **Acceptance:** test passes; service mock assertions verify zero invocations.
- [x] 5.5 Add per-tool happy-path tests:
  `testListOpenActionItems_happyPath`, `testListRecentMeetings_happyPath`,
  `testGetMeetingDetails_happyPath`, `testStartMeeting_happyPath`,
  `testAddActionItem_happyPath`. Each asserts (a) the success payload shape and
  (b) the `sources` array shape per REQ-DMCP-006.
  **Acceptance:** all 5 tests pass.
- [x] 5.6 Add per-tool forbidden-path tests for the three object-targeting tools:
  `testGetMeetingDetails_nonParticipant_returnsForbidden`,
  `testStartMeeting_nonChair_returnsForbidden`,
  `testAddActionItem_nonParticipant_returnsForbidden`. Each must verify the underlying
  service mutation method (e.g. `MeetingService::transition`) is NEVER called.
  **Acceptance:** all 3 tests pass; mock assertions confirm no service mutation.
- [x] 5.7 Add `testStartMeeting_alreadyInProgress_returnsInvalidState`. Set up a
  meeting fixture in `in-progress` state, caller is the chair. Assert the result is
  `{isError: true, error: 'invalid_state', message: contains 'in progress'}`.
  **Acceptance:** test passes.
- [x] 5.8 Add `testSourcesTruncationAtCap`. Configure `ObjectService` mock so
  `decidesk.getMeetingDetails` would produce 35 sources. Assert the returned
  `sources` length equals 20, `sourcesTruncated === true`, and
  `sourcesTotalCount === 35`.
  **Acceptance:** test passes.
- [x] 5.9 Add `testNotFoundReturnsNotFoundEnvelope`. Configure `ObjectService` mock to
  return null for the UUID `00000000-0000-0000-0000-000000000000`. Assert
  `getMeetingDetails`, `startMeeting`, and `addActionItem` each return
  `{isError: true, error: 'not_found'}`.
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
  **Acceptance:** assertion passes when openregister is present (skipped in dev
  environments lacking openregister).
- [ ] 6.3 Create a real Meeting fixture in `scheduled` state with a known chair user;
  log in as that chair; invoke `decidesk.startMeeting`. Assert (a) the result is
  success, (b) the meeting object now reads `in-progress` from a fresh lookup, (c) the
  `sources` array has exactly one descriptor with the correct deep link.
  **Acceptance:** end-to-end round-trip passes (requires full Nextcloud + OR runtime).

## 7. Quality gates

- [x] 7.1 Run `composer phpcs`. Fix any new PHPCS warnings introduced by this change.
  Do not modify existing baselines.
  **Acceptance:** PHPCS exits 0 for `lib/Mcp/` and `tests/Unit/Mcp/` (and any modified
  existing files).
- [x] 7.2 Run `composer phpmd`. Fix any new PHPMD findings (or add an explicit
  baseline entry only if the finding is a known false positive — note the rationale in
  the baseline).
  **Acceptance:** PHPMD reports zero new findings.
- [x] 7.3 Run `composer psalm`. Fix any new Psalm errors at level baseline.
  **Acceptance:** Psalm exits 0.
- [x] 7.4 Run `composer phpstan`. Fix any new PHPStan errors.
  **Acceptance:** PHPStan exits 0.
- [x] 7.5 Run `composer test:unit` and `composer test:all`. All tests must pass.
  **Acceptance:** PHPUnit exits 0; no skipped tests except the integration test in
  environments without openregister.
- [x] 7.6 Run `composer check:strict`. The whole pipeline (lint + phpcs + phpmd +
  psalm + phpstan + test:all) must exit 0.
  **Acceptance:** `check:strict` prints `ALL CHECKS PASSED`.

## 8. Documentation

- [x] 8.1 Create `docs/features/mcp-tools.md` (or extend an existing docs page if the
  apply step finds a more natural home) with sections: Overview, Enabling the
  Companion, Tool Reference (one subsection per tool with description + input fields
  + output shape + auth requirement), Troubleshooting.
  **Acceptance:** Markdown lints clean; every tool from REQ-DMCP-002 has its own
  subsection.
- [x] 8.2 Cross-link the docs page from `README.md` (Features section) and from
  `openspec/architecture/` (if a related ADR exists; otherwise skip — no related ADR
  exists at this time).
  **Acceptance:** the link exists and resolves.

## 9. Final spec validation

- [x] 9.1 Run `openspec validate decidesk-mcp-tools --strict` from
  `/tmp/worktrees/decidesk-mcp-tools`. Fix any issues surfaced.
  **Acceptance:** strict validation passes.
- [x] 9.2 Run `openspec status --change decidesk-mcp-tools`. All four artifacts must
  read `[x]`.
  **Acceptance:** 4/4 complete.
