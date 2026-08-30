# Test Plan: retire-board-portal

## Test Cases

### TC-1: Board schemas removed from the register
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-parallel-corporate-board-entity`
- **type**: regression
- **persona**: n/a
- **preconditions**: GIVEN the edited `lib/Settings/decidesk_register.json`
- **steps**: WHEN parsing `components.schemas`
- **expected result**: THEN `Board`, `BoardMember`, `BoardMeeting`, `BoardVote`, `BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry` are absent; the universal schemas remain; JSON is valid
- **test command**: `/test-regression`

### TC-2: No dangling references to deleted schemas/views/routes
- **spec_ref**: `openspec/changes/retire-board-portal/specs/resolution-minutes/spec.md#requirement-parallel-corporate-resolution-entity`
- **type**: regression
- **persona**: n/a
- **preconditions**: GIVEN the cleaned codebase (lib/, src/, appinfo/)
- **steps**: WHEN grepping for `board-meeting|boardMeeting|board-member|board-vote|board-material|board-audit-log-entry|'resolution'|BoardList|BoardDetail|ResolutionList`
- **expected result**: THEN only legitimate domain prose remains (e.g. `board-elections` agenda topic); no live schema query, route, import, or component registration references a deleted artifact; `php -l` passes on touched PHP files
- **test command**: `/test-regression`

### TC-3: App boots and nav renders without the parallel board items
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-req-gbd-003-meeting-creation-from-governance-body`
- **type**: functional
- **persona**: Noor (functional admin)
- **preconditions**: GIVEN Decidesk is installed with the change applied
- **steps**: WHEN opening the Decidesk root and inspecting the left nav
- **expected result**: THEN the app loads without console errors AND no "Boards", "Board meetings", "Resolutions", or "Board dashboard" nav items appear
- **test command**: `/test-functional`

### TC-4: Corporate scenario survives via mode=corp seeds
- **spec_ref**: `openspec/changes/retire-board-portal/specs/meeting-management/spec.md#requirement-meeting-creation-and-scheduling`
- **type**: functional
- **persona**: Noor (functional admin)
- **preconditions**: GIVEN the register synced with the three corp seeds
- **steps**: WHEN browsing governance bodies, meetings, and minutes
- **expected result**: THEN the corporate `governance-body` (`Raad van Commissarissen ACME B.V.`, `bodyType=corporate-board`), the corporate `meeting` (`RvC-vergadering Q2 2025`), and the corporate `minutes` (`Notulen RvC Q2 2025`) are present on the universal surfaces
- **test command**: `/test-functional`

### TC-5: Creating a meeting from a corporate body uses the universal path
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-req-gbd-003-meeting-creation-from-governance-body`
- **type**: functional
- **persona**: Noor (functional admin)
- **preconditions**: GIVEN the corporate governance body detail page
- **steps**: WHEN clicking "Add meeting"
- **expected result**: THEN the router navigates to `/meetings/new?governanceBodyId={bodyId}` (the universal path) with the body pre-filled, and no `Board`-schema object is created
- **test command**: `/test-functional`

### TC-6: Unified search returns decisions and meetings, not deleted resolution schema
- **spec_ref**: `openspec/changes/retire-board-portal/specs/resolution-minutes/spec.md#requirement-resolution-generation`
- **type**: api
- **persona**: n/a
- **preconditions**: GIVEN `DecideskSearchProvider` with the `resolution` entry removed and `decision`/`meeting` retained
- **steps**: WHEN issuing a unified search query that previously matched resolutions
- **expected result**: THEN results return as `decision` objects (`decisionType=resolution`) and `meeting` objects; the search provider does not query the deleted `resolution` schema and does not error
- **test command**: `/test-api`

### TC-7: Retargeted board-coupled endpoints keep their authorization guards
- **spec_ref**: `openspec/changes/retire-board-portal/specs/resolution-minutes/spec.md#requirement-parallel-corporate-board-audit-log-entity`
- **type**: security
- **persona**: n/a
- **preconditions**: GIVEN the flagged board-coupled controllers (conflict-of-interest, audit-log, eIDAS, proxy-vote, governance-report, regulator-export, multilingual) retargeted onto unified entities
- **steps**: WHEN an unauthorised user calls each retained/retargeted endpoint with an arbitrary object id
- **expected result**: THEN the per-object / admin authorization guard rejects the request (no IDOR regression, no orphaned auth introduced by the move)
- **test command**: `/test-security`

### TC-8: Backend unit suite green after deletions
- **spec_ref**: `openspec/changes/retire-board-portal/specs/meeting-management/spec.md#requirement-parallel-corporate-board-meeting-entity`
- **type**: regression
- **persona**: n/a
- **preconditions**: GIVEN board-only services and their tests removed and flagged services retargeted
- **steps**: WHEN running `composer test`
- **expected result**: THEN the suite is green with no references to deleted classes (`BoardService`, `ResolutionService`, `BoardMeetingService`, etc.) and no dead-test failures
- **test command**: `/test-regression`

## Coverage Summary

- governance-bodies REQ-GBD-003 (MODIFIED, corporate path) — covered by TC-3, TC-4, TC-5.
- governance-bodies "Parallel corporate Board entity" (REMOVED) — covered by TC-1, TC-2.
- meeting-management "Meeting Creation and Scheduling" (MODIFIED) — covered by TC-4, TC-5.
- meeting-management "Parallel Board Meeting / Board Material" (REMOVED) — covered by TC-1, TC-2, TC-8.
- resolution-minutes "Resolution Generation" (MODIFIED) — covered by TC-6.
- resolution-minutes "Parallel Resolution / BoardMinutes / BoardVote / BoardAuditLogEntry" (REMOVED) — covered by TC-1, TC-2, TC-6, TC-7.
- Authorization integrity on retargeted endpoints — covered by TC-7.

## Out of Scope

- Building the eIDAS `signature` decision method — deferred to Cycle 2
  (`decision-methods`); this change only cleans its dangling references.
- The six-item mode-aware nav UI — delivered by `ia-six-item-nav` (C7).
- Splitting `GovernanceBody.bodyType` into `supervisory-board`/`executive-board`
  — deferred (see DEFERRED_QUESTIONS); corp seed uses `corporate-board`.
