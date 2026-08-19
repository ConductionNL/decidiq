# Tasks: organisation-goals

## Implementation Tasks

### Task 1: Create the Goal schema register fragment
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-001-goal-schema`, `#requirement-req-002-goal-owner-and-body-reach-every-organisational-level`, `#requirement-req-003-goal-lifecycle-is-declarative`, `#requirement-req-004-goal-progress-rolls-up-from-linked-commitments-and-tasks`, `#requirement-req-005-goal-supports-single-level-parentchild-cascade`
- **files**: `lib/Settings/register.d/66-organisation-goals.json` (new)
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register loads THEN a `Goal` schema (slug `goal`) exists with required `title`/`description`/`horizon`/`body`/`deadline`/`status` and optional `owner`/`startDate`/`targetValue`/`currentValue`/`unit`/`parentGoal`, per design.md's schema sketch
  - GIVEN the fragment WHEN inspected THEN `x-openregister-lifecycle` declares states `draft`/`active`/`at-risk`/`achieved`/`abandoned` with `achieved`/`abandoned` terminal, per design.md's Decision D2/table
  - GIVEN the fragment WHEN inspected THEN `x-openregister-aggregations`/`x-openregister-calculations` declare `linkedCommitmentCount`, `settledCommitmentCount`, `linkedActionItemCount`, `completedActionItemCount`, `childGoalCount`, `achievedChildGoalCount` and their percentage calculations, per design.md
  - GIVEN the fragment WHEN inspected THEN the five seed `Goal` objects from design.md's Seed Data section are present with the exact field values in that table
- [ ] Implement
- [ ] Test

### Task 2: Add `goal` to Toezegging and cross-link a seed object
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-006-toezegging-references-its-goal`
- **files**: `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json`
- **acceptance_criteria**:
  - GIVEN the patched schema WHEN inspected THEN `Toezegging` has an optional, nullable `goal` property (`$ref: Goal`), documented like its sibling `relatedMotion` property
  - GIVEN the seed object `toezegging-schouw-marktplein` WHEN patched THEN it carries `goal: goal-amsterdam-klimaatneutraal-2050` (slug reference, resolved at import per the existing seed-import convention)
  - GIVEN every other existing seed Toezegging object THEN it is left unchanged (no `goal` set) — the property is optional
- [ ] Implement
- [ ] Test

### Task 3: Add `goal` to TermijnagendaItem and cross-link a seed object
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-008-termijnagendaitem-references-its-goal`
- **files**: `lib/Settings/register.d/50-termijnagenda.json`
- **acceptance_criteria**:
  - GIVEN the patched schema WHEN inspected THEN `TermijnagendaItem` has an optional, nullable `goal` property (`$ref: Goal`), documented like its sibling `originDecision` property
  - GIVEN the seed object `lta-herziening-parkeerbeleid` WHEN patched THEN it carries `goal: goal-amsterdam-parkeerbeleid-kwartaal`
  - GIVEN every other existing seed TermijnagendaItem object THEN it is left unchanged
- [ ] Implement
- [ ] Test

### Task 4: Add `goal` to ActionItem
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-007-actionitem-references-its-goal-through-the-existing-caldav-projection`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the patched `ActionItem` schema WHEN inspected THEN it has an optional `goal` property (`$ref: Goal`), documented like its sibling `decision`/`meeting` properties, with a note that it round-trips via `ActionItemWriter`'s generic non-core `fields` pass-through (no PHP change — see design.md D4)
  - GIVEN `ActionItemWriter::toTaskData()`'s `coreKeys` list WHEN checked THEN `goal` is confirmed NOT present in it (so the generic pass-through applies) — this is a read/verify step, not a code edit
- [ ] Implement
- [ ] Test

### Task 5: Declare the Goals index/detail pages and menu entry
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-009-goals-index-and-detail-pages-are-declared-not-custom-built`
- **files**: `src/manifest.d/organisation-goals.json` (new)
- **acceptance_criteria**:
  - GIVEN the fragment WHEN merged THEN a Goals index page (register `decidesk`, schema `goal`) and a Goals detail page exist, using the `data`/`related` generic widgets, matching the shape of `src/manifest.d/termijnagenda.json`
  - GIVEN the fragment's `menu` entry WHEN inspected THEN its id is exactly `Goals` — `ia-six-clusters` forward-declares the relocation `"Goals": "ActionItems"` in `src/menu-layout.json`, so this id is a contract; the entry also carries a `_note` recording that placement (this change does not edit `openspec/changes/ia-six-clusters/`)
  - GIVEN the index page WHEN inspected THEN its columns include `title`, `body`, `horizon`, `deadline`, `status` and quick filters on `status`
- [ ] Implement
- [ ] Test

### Task 6: Live-verify the round-trips and the self-referential aggregation
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-004-goal-progress-rolls-up-from-linked-commitments-and-tasks`, `#requirement-req-005-goal-supports-single-level-parentchild-cascade`, `#requirement-req-007-actionitem-references-its-goal-through-the-existing-caldav-projection`
- **files**: none (verification against the running dev instance; see test-plan.md TC-4/TC-5/TC-7)
- **acceptance_criteria**:
  - GIVEN seeded Goals G1 (`goal-acme-groeidoelstelling-2028`) and G2 (`goal-acme-mt-operationele-effectiviteit-2026`, `parentGoal: G1`) WHEN G1 is read via the objects API THEN `childGoalCount` is 1 (or, if the engine rejects self-referential filters, is confirmed absent — record which outcome was observed, per proposal.md Risk 1)
  - GIVEN a test ActionItem created via `ActionItemWriter::create()` with `goal` set WHEN read back through the `action-item` OpenRegister projection THEN `goal` is present and correct (proposal.md Risk 2)
  - GIVEN the seeded `toezegging-schouw-marktplein` (now linked to `goal-amsterdam-klimaatneutraal-2050`) WHEN that Goal is read THEN `linkedCommitmentCount` includes it
- [ ] Implement
- [ ] Test

### Task 7: Validate the change end-to-end
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md`
- **files**: none (validation only)
- **acceptance_criteria**:
  - GIVEN the full change WHEN `openspec validate --change organisation-goals --strict` runs THEN it passes
  - GIVEN the merged register WHEN the Decidesk app settings page loads THEN no schema-load error is logged for `Goal`, `Toezegging`, `TermijnagendaItem`, or `ActionItem`
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (test-plan.md TC-1 through TC-9)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
<!-- Plain-text reminders, not tracked checkboxes (SKILL.md: quality checklist items stay plain text to keep the Hydra 20-checkbox cap). -->
- Newman/Postman tests for the Goal objects API surface (generic OpenRegister CRUD — extend the existing collection, no new endpoint)
- Browser test (Playwright MCP) for the Goals index/detail pages (test-plan.md TC-9)
- N/A PHPUnit unit tests — this change adds no PHP business logic (design.md D2/D3/D4); the round-trip/aggregation behaviour is verified live per Task 6, not unit-testable in isolation since it lives in OpenRegister's declarative engine, not app code

## Documentation (company-wide ADR-010)
- Feature documentation updated in `docs/` describing the Goal object and its links from Toezegging/ActionItem/TermijnagendaItem
- Screenshot of the Goals index/detail page captured and committed to `docs/images/`

## i18n (company-wide ADR-005)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the `horizon`/`status` enum labels and the Goals page/menu titles
