# Tasks: hermiq-ai-tooling

Sequencing: `decidesk-mcp-adoption` MUST be merged first — this change extends its
scannable-services opt-in and continues its `mcp-tools` capability (REQ-DMCP-015+). All
`@spec` tags added to code MUST point at the canonical spec
(`openspec/specs/mcp-tools/spec.md`), never at a change directory — change dirs evaporate on
archive.

## Implementation Tasks

### Task 1: Constrain the `meetingScheduled` rule before any draft-create tool exists
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-020-draft-only-creates-shall-exist-and-shall-not-fan-out`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `Meeting` schema's `meetingScheduled` rule (today: `trigger {type: created}`, no lifecycle filter) WHEN this task lands THEN the rule fires only for `lifecycle: scheduled` (condition dialect as used by the Decision `voteRequested` rule) and the register `version` is bumped
  - GIVEN a dev instance after re-import WHEN a meeting is created with `lifecycle: draft` THEN zero notifications are produced, and WHEN one is created with `lifecycle: scheduled` THEN exactly one fan-out fires (measure the notification store before/after — the positive control proves the rule still can fire)
  - AND `python3 -m json.tool lib/Settings/decidesk_register.json` succeeds after every edit
- [ ] Implement
- [ ] Test

### Task 2: Draft-only creates — `scheduleDraftMeeting` and `addItemToDraftAgenda`
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-020-draft-only-creates-shall-exist-and-shall-not-fan-out`
- **files**: `lib/Service/MeetingService.php`, `lib/Service/AgendaService.php`
- **acceptance_criteria**:
  - GIVEN `MeetingService::scheduleDraftMeeting()` WHEN called THEN the created meeting has `lifecycle: draft` regardless of arguments (the pin is server-side, not caller-supplied)
  - GIVEN `AgendaService::addItemToDraftAgenda()` WHEN the meeting's agenda is published THEN the call is refused with a domain error and no item is created
  - AND both carry `#[McpTool(scope: 'create', readOnlyHint: false, destructiveHint: false, idempotentHint: false)]` with agent-facing descriptions
- [ ] Implement
- [ ] Test

### Task 3: Annotate the existing write actions per the D2 matrix
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-016-every-write-tool-shall-carry-honest-grant-model-annotations`
- **files**: `lib/Service/MeetingService.php`, `lib/Service/AgendaService.php`, `lib/Service/MotionService.php`, `lib/Service/VotingRoundOpener.php`, `lib/Service/VotingRoundCloser.php`, `lib/Service/MinutesDraftService.php`, `lib/Service/MinutesWorkflowService.php`, `lib/Service/ActionItemWriter.php`, `lib/Service/ConflictOfInterestService.php`, `lib/Service/ProxyDelegationService.php`, `lib/Service/DecisionPublicationService.php`
- **acceptance_criteria**:
  - GIVEN every ♻ row of the action inventory (design.md D1) WHEN the owning method is inspected THEN it (or a thin agent-facing wrapper delegating to it) carries a `#[McpTool]` whose `scope`/`destructiveHint`/`idempotentHint` match the D2 matrix exactly
  - GIVEN the ten approval-gated tools of REQ-DMCP-017 WHEN their attributes are read THEN each declares `destructiveHint: true`
  - GIVEN every pre-existing domain guard (`MeetingRoleGate`, `AgendaAuthorizationGuard`, `VotingRoundGuard`, `MinutesAccessGuard`, participant/chair guards) WHEN the tool path executes THEN the guard runs unchanged — no tool bypasses or duplicates a guard
  - AND no tool method is added to any class under `lib/Mcp/`
- [ ] Implement
- [ ] Test

### Task 4: Read tools — `checkQuorum` and `previewMinutesActionItems`
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-015-every-user-action-shall-be-dispositioned-on-the-agent-surface`
- **files**: `lib/Service/VotingRoundOpener.php`, `lib/Service/MinutesWorkflowService.php`
- **acceptance_criteria**:
  - GIVEN `VotingRoundOpener::checkQuorum()` and `MinutesWorkflowService::extractActionItems()` WHEN annotated THEN each carries `#[McpTool(scope: 'read', readOnlyHint: true, destructiveHint: false, idempotentHint: true)]`
  - GIVEN `decidesk.previewMinutesActionItems` WHEN invoked THEN zero objects are created (measure counts); creation happens only via `decidesk.saveMinutesActionItems` (`MinutesWorkflowService::saveExtractedActionItems()`)
- [ ] Implement
- [ ] Test

### Task 5: Extend `DecideskScannableServices` — two-way agreement with the annotations
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-013-scannable-services-opt-in`
- **files**: `lib/Mcp/DecideskScannableServices.php`
- **acceptance_criteria**:
  - GIVEN `getScannableServiceClasses()` WHEN compared against a scan of `#[McpTool]` under `lib/` THEN the two sets are identical (a unit test enforces both directions)
  - GIVEN the booted app WHEN the MCP catalogue is listed THEN every tool id from the inventory's ✅/♻ rows is present
- [ ] Implement
- [ ] Test

### Task 6: Audit attribution for agent writes
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-019-every-agent-write-shall-land-in-the-tamper-evident-audit-chain`
- **files**: `lib/Service/AuditLogService.php` (entry shape only if needed), the services from Task 3
- **acceptance_criteria**:
  - GIVEN any curated write tool invocation WHEN it mutates state THEN exactly one `AuditLogService::append()` entry records tool id, agent identity, acting user and target object ids (chain grows by exactly one — count entries, do not trust the tool's own report)
  - GIVEN an action whose service already appends (proxy grant/revocation) WHEN invoked via the tool THEN the chain grows by exactly one entry carrying the agent attribution — no double entry
  - GIVEN any read tool WHEN invoked THEN the chain length is unchanged
- [ ] Implement
- [ ] Test

### Task 7: Withdrawal guards — no ballot, no signature, no vote reads
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-018-ballots-and-qualified-signatures-shall-never-be-agent-tools`
- **files**: `tests/Unit/Mcp/` (assertion-only; no production change)
- **acceptance_criteria**:
  - GIVEN a scan of every `#[McpTool]` in `lib/` WHEN receiving methods are resolved THEN none is `VoteCastingService::castVote()` nor any `EIDASSignatureService` method (a unit test pins this so a future change must consciously delete the test)
  - GIVEN the register JSON WHEN `x-openregister-mcp` blocks are collected THEN `vote` still declares none and no schema declares a write verb (reaffirms REQ-DMCP-011/014 — the derived surface is untouched by this change)
- [ ] Implement
- [ ] Test

### Task 8: Live grant-model verification + chat flows + OR issue
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/mcp-tools/spec.md#requirement-req-dmcp-021-chat-shall-be-able-to-command-decidesk-end-to-end`
- **files**: `CHANGELOG.md`, e2e material under `tests/`
- **acceptance_criteria**:
  - GIVEN a dev instance with hermiq WHEN an agent holds no grants THEN every write tool from this change is absent from its catalogue (default-deny observed, not assumed), and reads remain present
  - GIVEN a grant for `decidesk.transitionMeeting` WHEN invoked THEN the call queues in hermiq's approval flow showing the resolved meeting; approve → `openedAt` written; reject → no write, no notifications (both branches exercised)
  - GIVEN a grant `decidesk.publishDecision#noapproval` WHEN invoked THEN the approval gate still engages (waiver-proof, REQ-DMCP-017)
  - GIVEN the three REQ-DMCP-021 chat flows WHEN run end-to-end THEN each completes with the specified tool sequence and citations
  - AND the OpenRegister issue requesting a `reach` parameter on the `McpTool` attribute is filed and linked in the PR body
- [ ] Implement
- [ ] Test

## Verification

- [ ] Grep checks: every `#[McpTool]` under `lib/` declares an explicit `scope` (`grep -rn "McpTool(" lib/ | grep -vc "scope:"` = 0); no `McpTool` attribute exists under `lib/Mcp/`; the D2 matrix row count equals the number of write attributes.
- [ ] `php -l` on every touched PHP file; `python3 -m json.tool lib/Settings/decidesk_register.json`.
- [ ] `composer check:strict` exits zero.
- [ ] Hydra gates pass (`hydra-gates` skill / shared runner) — semantic-auth and no-admin-idor must show no new findings on the annotated services.
- [ ] `openspec validate hermiq-ai-tooling --type change --strict` passes.
- [ ] Live catalogue check on `/api/mcp`: inventory ✅/♻ tool ids present; `decidesk.castVote` absent; derived surface unchanged (still 10 schemas × search/get).

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests — attribute/matrix conformance (Task 3), scannable-services two-way agreement (Task 5), withdrawal pins (Task 7), audit exactly-one-entry (Task 6), draft-pin and published-agenda refusal (Task 2), notification-rule condition (Task 1, JSON-level).
- [ ] Newman/Postman tests — N/A: no decidesk HTTP endpoint changes; the MCP surface is served by OpenRegister's `/api/mcp`.
- [ ] Browser tests (Playwright MCP) — the hermiq approval flow for `transitionMeeting` (approve + reject branches) and chat flow (1); N/A for decidesk's own UI (unchanged).
- [ ] All tests pass (`composer test`), zero new failures against a self-measured baseline.

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — the full tool table with scope × reach × approval, the withdrawn actions and their reasons, and the granting walkthrough ("what your assistant may do, per agent").
- [ ] Screenshot captured and committed to `docs/images/` — the approval card for a meeting transition.

## i18n (company-wide ADR-005)

- [ ] N/A for tool `description` fields (agent-facing prose, not UI copy — same ruling as decidesk-mcp-adoption). Approval-card strings live in hermiq, not decidesk.
