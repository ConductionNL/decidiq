# Test Plan: organisation-goals

## Test Cases

### TC-1: Goal schema required fields
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-001-goal-schema`
- **type**: api
- **persona**: n/a
- **preconditions**: Decidesk register loaded with `66-organisation-goals.json` merged; a GovernanceBody seed object exists.
- **steps**: POST a Goal via the OpenRegister objects API with `title`/`description`/`horizon`/`body`/`deadline` set; repeat omitting each required field in turn.
- **expected result**: The complete payload creates successfully with `status` defaulted to `draft`. Each payload missing a required field is rejected by schema validation (422).
- **test command**: `/test-api`

### TC-2: Goal owner/body span every organisational level
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-002-goal-owner-and-body-reach-every-organisational-level`
- **type**: api
- **persona**: n/a
- **preconditions**: Seed GovernanceBody objects of at least two different `bodyType` values exist (e.g. `legislative`, `operational`).
- **steps**: Create one Goal per `bodyType`, one with `owner` set and one with `owner` omitted.
- **expected result**: All four combinations create successfully; `owner` is confirmed optional.
- **test command**: `/test-api`

### TC-3: Goal lifecycle guards transitions
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-003-goal-lifecycle-is-declarative`
- **type**: api
- **persona**: n/a
- **preconditions**: A Goal exists with `status: "active"`.
- **steps**: Attempt an undeclared transition (`active` → `draft`); attempt a declared transition (`active` → `achieved`); attempt any transition from `achieved`.
- **expected result**: Undeclared transition rejected; declared transition succeeds; no transition is possible once terminal.
- **test command**: `/test-api`

### TC-4: Progress rollup from linked commitments and tasks
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-004-goal-progress-rolls-up-from-linked-commitments-and-tasks`
- **type**: api
- **persona**: n/a
- **preconditions**: A Goal exists; two Toezegging objects and three ActionItem VTODOs reference it via `goal`, with known status mixes (per the spec scenarios).
- **steps**: Read the Goal object and inspect `linkedCommitmentCount`, `settledCommitmentCount`, `linkedActionItemCount`, `completedActionItemCount`.
- **expected result**: Counts match the spec scenarios exactly (2/1 commitments, 3/2 action items).
- **test command**: `/test-api`

### TC-5: Single-level parentGoal cascade (and its boundary)
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-005-goal-supports-single-level-parentchild-cascade`
- **type**: api
- **persona**: n/a
- **preconditions**: Three Goals G1 → G2 (`parentGoal: G1`) → G3 (`parentGoal: G2`), per the seeded ACME pair plus one extra test-only Goal for the 3-level check.
- **steps**: Read G1's `childGoalCount`.
- **expected result**: `childGoalCount` on G1 counts G2 only (1), never G3 — proves both the working single-level case and the documented boundary. If the aggregation engine cannot resolve a self-referential `Goal → Goal` filter at all, `childGoalCount` is absent/null rather than erroring — record which behaviour was observed (see proposal.md Risk 1 / DEFERRED_QUESTIONS).
- **test command**: `/test-api`

### TC-6: Toezegging goal reference
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-006-toezegging-references-its-goal`
- **type**: api
- **persona**: n/a
- **preconditions**: A Goal exists.
- **steps**: Create/update a Toezegging with `goal` set; read it back. Also read an existing (pre-change) Toezegging seed object with no `goal`.
- **expected result**: New Toezegging resolves `goal` to the target Goal; the pre-existing seed object remains valid with `goal` absent.
- **test command**: `/test-api`

### TC-7: ActionItem goal reference round-trips through the CalDAV projection
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-007-actionitem-references-its-goal-through-the-existing-caldav-projection`
- **type**: api
- **persona**: n/a
- **preconditions**: A Goal exists; a test user session with a Decidesk calendar.
- **steps**: Call `ActionItemWriter::create()` (via the existing action-item controller/MCP tool path) with `goal` in the payload; read the resulting object back through the read-only `action-item` OpenRegister projection.
- **expected result**: `goal` is present and correct on read-back, proving the generic `fields`-blob pass-through (D4) — this is the highest-risk requirement in the change (proposal.md Risk 2) and MUST be exercised live, not assumed from the schema declaration alone.
- **test command**: `/test-api`

### TC-8: TermijnagendaItem goal reference
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-008-termijnagendaitem-references-its-goal`
- **type**: api
- **persona**: n/a
- **preconditions**: A Goal exists.
- **steps**: Create/update a TermijnagendaItem with `goal` set; read it back.
- **expected result**: `goal` resolves to the target Goal.
- **test command**: `/test-api`

### TC-9: Goals index/detail pages render from the manifest fragment
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-009-goals-index-and-detail-pages-are-declared-not-custom-built`
- **type**: functional
- **persona**: n/a
- **preconditions**: `src/manifest.d/organisation-goals.json` is present; seed Goal objects exist.
- **steps**: Navigate to the Goals index route; open a Goal's detail page.
- **expected result**: Index lists the seeded Goals with the declared columns; detail page renders the `data` and `related` widgets, including the resolved `parentGoal`/`owner`/`body` references for the ACME pair.
- **test command**: `/test-functional`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 Goal schema | TC-1 |
| REQ-002 Owner/body reach every level | TC-2 |
| REQ-003 Declarative lifecycle | TC-3 |
| REQ-004 Progress rollup | TC-4 |
| REQ-005 Single-level cascade | TC-5 |
| REQ-006 Toezegging.goal | TC-6 |
| REQ-007 ActionItem.goal | TC-7 |
| REQ-008 TermijnagendaItem.goal | TC-8 |
| REQ-009 Goals pages via manifest | TC-9 |

## Out of Scope

- Accessibility (`/test-accessibility`): not re-run for this change — REQ-009's pages use only generic components already WCAG 2.2 AA audited elsewhere in Decidesk, and no new component is introduced.
- Multi-level (3+) cascade UI verification: TC-5 proves the API-level boundary; there is no UI surface for a cascade that does not exist (REQ-005 Scenario 2 is explicitly out of scope).
- Performance testing: Goal aggregation reuses the exact mechanism already carrying Meeting's quorum aggregation in production; no new performance budget is introduced.
