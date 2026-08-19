# Test Plan: ux-debt-rendering

## Test Cases

### TC-1: Reference columns resolve to a name, not a raw id
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-001-reference-columns-resolve-to-a-readable-label`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — reads governance registers day to day and needs names, not ids
- **preconditions**: Logged in as admin on the shared decidesk instance; at least one of Oral questions, Interpellations, Commitments, Regulations, Governing documents, Delegations & mandates, Confidentiality register, VvE configurations, Kascommissie has real seeded data
- **steps**: Navigate to each affected index page; observe the reference-bound columns (submitter, portefeuillehouder, madeBy, determiningBody, governingBody, delegans, imposedByBody, body, governanceBody)
- **expected result**: Every cell shows a resolved name (e.g. "Gemeenteraad Amsterdam"), not a raw UUID or slug — except cells whose underlying value is the literal nil UUID placeholder, which are documented residual debt, not a failure
- **test command**: `/test-functional`

### TC-2: Year and financial-year columns render without a thousands separator
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-002-integer-yearfinancial-year-columns-render-without-a-thousands-separator`
- **type**: functional
- **preconditions**: P&C cycles and Kascommissie index pages have seeded objects with `year`/`boekjaar` values ≥ 1000
- **steps**: Navigate to P&C cycles index and Kascommissie index; read the Year/Boekjaar column
- **expected result**: Values render as plain 4-digit years ("2026"), never grouped ("2,026")
- **test command**: `/test-functional`

### TC-3: Date/datetime columns render formatted, never as raw strings
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-003-date-and-datetime-columns-render-through-the-shared-date-formatter`
- **type**: functional
- **preconditions**: Each of the 11 fragments touched by Task 3 has at least one seeded object with the affected date field populated
- **steps**: Navigate to each of the 11 affected index pages; read the fixed date columns
- **expected result**: No cell shows a raw ISO/SQL-style string (e.g. "2025-03-01 00:00:00"); all render through the relative/localized `NcDateTime` format
- **test command**: `/test-functional`

### TC-4: Delegations & mandates and Proxy authorizations always reach a terminal loading state
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-004-an-index-page-always-reaches-a-terminal-loading-state`
- **type**: regression
- **preconditions**: `subscribe: false` applied per Task 4
- **steps**: Load `/apps/decidesk/bevoegdheidstoedelingen` and the Proxy authorizations page 5 times each (fresh navigation each time, not just a client-side route change); capture the network trace for each load
- **expected result**: Every load shows the table (or empty state) within 5 seconds; no bare `?_facets=extend` (without `_limit`) request appears in any trace
- **test command**: `/test-regression`

### TC-5: Quick-filter label integrity regression guard
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-005-quick-filter-chipdropdown-labels-render-intact`
- **type**: regression
- **preconditions**: Urgent decisions page reachable
- **steps**: Run the new Playwright assertion from Task 6 at 375px, 900px, and 1280px viewport widths
- **expected result**: `textContent` of the quick-filter's selected-value label equals `"All urgent"` exactly, with no embedded newline, at every tested width
- **test command**: `/test-regression`

### TC-6: Walkthrough steps resolve against the current navigation
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-006-the-first-run-walkthrough-targets-resolvable-current-elements`
- **type**: functional
- **persona**: Sem (Young Digital Native) — a first-time user who would actually see the onboarding tour
- **preconditions**: A fresh user account (or `walkthrough_completed_version` preference cleared) so the tour triggers on `first-visit`
- **steps**: Log in as a new user; let the `decidesk:getting-started` tour run through all four steps (welcome → go-meetings → create-meeting → done)
- **expected result**: Every step's coachmark anchors to a real, visible element (no centered fallback due to an unresolved target); the tour completes without getting stuck
- **test command**: `/test-persona-sem`

### TC-7: Seeded governing documents never show an empty title
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-007-seedexample-objects-carry-their-required-display-fields`
- **type**: functional
- **preconditions**: Task 8's correction/removal applied
- **steps**: Navigate to the Governing documents index; read every row's title column
- **expected result**: No row shows "—" for its title
- **test command**: `/test-functional`

### TC-8: E2E specs that create objects clean up after themselves
- **spec_ref**: `openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-008-e2e-specs-that-create-objects-on-the-shared-instance-are-namespaced-and-cleaned-up`
- **type**: regression
- **preconditions**: Task 9's marker + cleanup hooks applied to the meeting/body/consultation/governing-document creating specs
- **steps**: Run the updated spec files against the shared instance twice in a row; after each run, query the relevant OpenRegister collections for objects whose name/title carries the `[e2e]` marker
- **expected result**: After each run completes, zero `[e2e]`-marked objects remain from that run (the run's own creates were deleted by its own `afterEach`/`afterAll`)
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Test case | Covered |
|---|---|---|
| REQ-001 | TC-1 | Yes |
| REQ-002 | TC-2 | Yes |
| REQ-003 | TC-3 | Yes |
| REQ-004 | TC-4 | Yes |
| REQ-005 | TC-5 | Yes (regression guard only — see design.md Decision 5; no reproduced defect to verify a fix against) |
| REQ-006 | TC-6 | Yes |
| REQ-007 | TC-7 | Yes |
| REQ-008 | TC-8 | Yes |

## Out of Scope

- The nc-vue `liveUpdatesPlugin` fix and the OpenRegister unbounded-facets-hang fix (Task 5's filed issues) have no test case here — they are not implemented in this repo, and their eventual fix will carry its own test coverage in the owning repos.
- No test case exercises the nil-UUID fallback behavior of `CnFkResolveCell` as a *pass* condition — TC-1 explicitly treats it as documented residual debt, not something this change verifies as fixed.
