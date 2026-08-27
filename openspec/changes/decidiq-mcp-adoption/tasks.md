# Tasks: decidiq-mcp-adoption

All `@spec` tags added to code in these tasks MUST point at the **canonical** spec
(`openspec/specs/mcp-tools/spec.md`), never at a change directory — change dirs evaporate
on archive. The current provider's tags point at the already-archived
`openspec/changes/decidesk-mcp-tools/...` and are dangling; do not copy that pattern.

## Implementation Tasks

### Task 1: Declare the `x-openregister-mcp` dialect on the 10 curated schemas
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-011-declarative-crud-via-the-x-openregister-mcp-dialect`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN every schema's `x-openregister-mcp.enabled` is collected THEN exactly 10 are `true`: `meeting`, `decision`, `agenda-item`, `action-item`, `minutes`, `governance-body`, `person`, `membership`, `voting-round`, `conflict-of-interest`
  - GIVEN each opted-in schema WHEN its `tools` keys are read THEN only `search` and `get` are present, each with `scope: read`, `readOnlyHint: true`, and a hand-written agent-facing `description`
  - GIVEN any declared `search.filters` entry WHEN it is looked up in that schema's `properties` THEN the property exists (see design.md D1 for the verified lists)
  - GIVEN the block is placed at schema root WHEN the register is imported THEN it lands in `configuration['x-openregister-mcp']` via `Schema::hydrate()` folding
  - AND `python3 -m json.tool lib/Settings/decidesk_register.json` succeeds after every edit
- [ ] Implement
- [ ] Test

### Task 2: Move `getMeetingDetails` onto `MeetingService` as a curated read tool
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-012-curated-non-crud-tools-live-on-services-with-honest-annotations`
- **files**: `lib/Service/MeetingService.php`
- **acceptance_criteria**:
  - GIVEN `MeetingService::getMeetingDossier()` WHEN it is called for a meeting the caller participates in THEN it returns the meeting plus its agenda items, decisions, and action items
  - GIVEN a non-participant, non-admin caller WHEN they call it THEN it is refused with `forbidden` and no meeting content is returned (the `requireParticipantOrAdmin` guard moves across intact)
  - AND the method carries `#[McpTool(scope: 'read', readOnlyHint: true, destructiveHint: false, idempotentHint: true)]`
- [ ] Implement
- [ ] Test

### Task 3: Move `addActionItem` onto `ActionItemWriter` as the sole curated write tool
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-014-action-item-is-read-only-on-the-derived-surface`
- **files**: `lib/Service/ActionItemWriter.php`
- **acceptance_criteria**:
  - GIVEN `ActionItemWriter::addActionItemToMeeting()` WHEN a meeting participant calls it THEN a CalDAV VTODO action item is created and linked to the meeting
  - GIVEN a non-participant caller WHEN they call it THEN it is refused with `forbidden` and no VTODO is created
  - AND the method carries `#[McpTool(scope: 'create', readOnlyHint: false, destructiveHint: false, idempotentHint: false)]`
  - AND the write routes through the existing `ActionItemWriter::create()` CalDAV path, never `ObjectService::saveObject()` (the `action-item` schema is a read-only projection)
- [ ] Implement
- [ ] Test

### Task 4: Add the `IMcpScannableServices` opt-in
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-013-scannable-services-opt-in`
- **files**: `lib/Mcp/DecidiqScannableServices.php`, `lib/AppInfo/Application.php`, `tests/Stubs/Mcp/IMcpScannableServices.php`
- **acceptance_criteria**:
  - GIVEN `DecidiqScannableServices::getScannableServiceClasses()` WHEN it is called THEN it returns exactly `MeetingService::class` and `ActionItemWriter::class`
  - GIVEN the app is booted WHEN the MCP catalogue is listed THEN both curated tools appear
  - AND a runtime-autoloader stub exists at `tests/Stubs/Mcp/IMcpScannableServices.php` (replacing the `IMcpToolProvider` stub)
- [ ] Implement
- [ ] Test

### Task 5: Delete `DecidiqToolProvider` and its tests
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-011-declarative-crud-via-the-x-openregister-mcp-dialect`
- **files**: `lib/Mcp/DecidiqToolProvider.php`, `lib/AppInfo/Application.php`, `tests/Unit/Mcp/DecidiqToolProviderTest.php`, `tests/Integration/Mcp/DecidiqToolProviderIntegrationTest.php`, `tests/Stubs/Mcp/IMcpToolProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider has zero remaining tools WHEN the change is applied THEN `lib/Mcp/DecidiqToolProvider.php` is deleted outright (no empty seam) and its registration is removed from `Application.php`
  - GIVEN the MCP catalogue WHEN it is listed THEN `decidesk.startMeeting` is **absent** and is not replaced by any derived tool (no schema declares a write verb)
  - AND `MeetingService::transition()` is untouched, so the UI can still open a meeting
  - AND `lib/Mcp/` contains no authorisation helper, UUID validator, or deep-link builder
- [ ] Implement
- [ ] Test

### Task 6: Bump the register version, verify the live catalogue, update the CHANGELOG
- **spec_ref**: `openspec/specs/mcp-tools/spec.md#requirement-req-dmcp-011-declarative-crud-via-the-x-openregister-mcp-dialect`
- **files**: `lib/Settings/decidesk_register.json`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN schema re-import is version-gated WHEN the register version is bumped THEN the repair-step importer re-imports the dialect (an unbumped version silently no-ops)
  - GIVEN a live instance WHEN `/api/mcp` is queried THEN `decidesk.meeting.search`, `decidesk.action-item.search`, and `decidesk.decision.get` are present; `decidesk.startMeeting` is absent; and the curated add-action-item tool reports `scope: create`
  - AND `composer check:strict` exits zero
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate decidiq-mcp-adoption --type change --strict` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — dialect assertions (10-schema opt-in, filters resolve to real properties, no write verbs) + the two curated tools' happy/`forbidden` paths
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: no Decidiq HTTP endpoint changes; the MCP surface is served by OpenRegister's `/api/mcp`
- [ ] Browser tests (Playwright MCP) for UI changes — N/A: no frontend changes
- [ ] All tests pass (`composer test`), with zero new failures against a self-measured baseline

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` — record the agent-visible tool surface and, explicitly, that starting a meeting is a human-only action
- [ ] Screenshot captured and committed to `docs/images/` — N/A: no UI change

## i18n (company-wide ADR-005)
- [ ] N/A — no new user-facing strings. The dialect `description` fields are agent-facing prose read by the LLM, not UI copy, and are not translated.
