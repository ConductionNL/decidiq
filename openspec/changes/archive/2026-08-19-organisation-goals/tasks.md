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
- [x] Implement
- [x] Test — Live-verified on the shared instance (localhost:8080) after "Re-import configuration". `GET /apps/openregister/api/objects/decidesk/goal?_limit=20` returns `total: 5`, all five slugs present with the exact field values from design.md's table (targetValue/currentValue/unit, status, horizon, parentGoal on the two child goals). Schema loaded with no error (`nextcloud.log` shows no schema-load failure for `goal`). Lifecycle guard live-verified via a scratch object (deleted after): `active`→`draft` PATCH rejected 422 `lifecycle-invalid-transition`; `active`→`achieved` PATCH accepted 200; further transition from `achieved` rejected 422. **Aggregations/calculations do NOT function on this OpenRegister build** — `nextcloud.log` shows `x-openregister-aggregations annotation on schema "goal" is invalid and was ignored: ... filter references unknown field "goal"` and `x-openregister-calculations annotation on schema "goal" is invalid and was ignored: ... prop "linkedCommitmentCount" is not a property or calculation ... unknown operator "mul"`. Root cause (three-layer, NOT specific to Goal): (1) `AggregationAnnotationValidator` checks a filter's field names against the DECLARING schema's own properties, not the target schema's, so cross-schema filter keys like `goal`/`lifecycle`/`taskStatus` are flagged unknown and the whole annotation is discarded; (2) `CalculationAnnotationValidator` does not recognise `mul` as a supported operator; (3) `MagicMapper` silently discards any computed field not also declared under `properties` ("They are NOT stored anywhere"). **Confirmed pre-existing, not a Goal-specific regression**: the identical log pattern fires for `schema "meeting"` on the same re-import (`Aggregation "actionItemCount" filter references unknown field "meeting"` / `unknown operator "mul"`), i.e. Meeting's own quorumPercentage/actionItemCompletionRate — the pattern design.md cites as "already proven in production" — is equally non-functional on this build. See Task 6 for the full investigation.

### Task 2: Add `goal` to Toezegging and cross-link a seed object
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-006-toezegging-references-its-goal`
- **files**: `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json`
- **acceptance_criteria**:
  - GIVEN the patched schema WHEN inspected THEN `Toezegging` has an optional, nullable `goal` property (`$ref: Goal`), documented like its sibling `relatedMotion` property
  - GIVEN the seed object `toezegging-schouw-marktplein` WHEN patched THEN it carries `goal: goal-amsterdam-klimaatneutraal-2050` (slug reference, resolved at import per the existing seed-import convention)
  - GIVEN every other existing seed Toezegging object THEN it is left unchanged (no `goal` set) — the property is optional
- [x] Implement
- [x] Test — Schema property confirmed live: JSON valid, `openspec validate organisation-goals --strict` passes. **Seed cross-link did NOT take effect on the shared instance**: `GET /apps/openregister/api/objects/decidesk/toezegging?_limit=10` after re-import still shows `toezegging-schouw-marktplein` with no `goal` field. Root cause confirmed by reading `ImportHandler::importSeedDataObjects()` (openregister): the seed importer is create-only/idempotent-by-slug — "Object already exists - skip creation to prevent duplication" — it never updates an already-seeded object's fields on re-import. Since `toezegging-schouw-marktplein` was seeded in an earlier session on this shared instance, the JSON patch is correct but inert here; a genuinely fresh install would pick it up. **REQ-006 itself verified live instead** via a fresh object: `POST /apps/openregister/api/objects/decidesk/toezegging` with `goal: <real Goal UUID>` → 201, `goal` present in the response and in `@self.relations`; `GET` on the new object confirms persistence; object then deleted (cleanup). Other existing seed Toezegging objects unchanged, confirming optionality.

### Task 3: Add `goal` to TermijnagendaItem and cross-link a seed object
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-008-termijnagendaitem-references-its-goal`
- **files**: `lib/Settings/register.d/50-termijnagenda.json`
- **acceptance_criteria**:
  - GIVEN the patched schema WHEN inspected THEN `TermijnagendaItem` has an optional, nullable `goal` property (`$ref: Goal`), documented like its sibling `originDecision` property
  - GIVEN the seed object `lta-herziening-parkeerbeleid` WHEN patched THEN it carries `goal: goal-amsterdam-parkeerbeleid-kwartaal`
  - GIVEN every other existing seed TermijnagendaItem object THEN it is left unchanged
- [x] Implement
- [x] Test — Same finding as Task 2: JSON valid, `openspec validate` passes, but the `lta-herziening-parkeerbeleid` seed cross-link did not take effect on the shared instance for the same create-only-seed-importer reason. **REQ-008 verified live instead** via a fresh object: `POST /apps/openregister/api/objects/decidesk/termijnagenda-item` with `goal: <real Goal UUID>` → 201, `goal` present in the response and `@self.relations`; deleted after (cleanup). Other existing seed items unchanged.

### Task 4: Add `goal` to ActionItem
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-007-actionitem-references-its-goal-through-the-existing-caldav-projection`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the patched `ActionItem` schema WHEN inspected THEN it has an optional `goal` property (`$ref: Goal`), documented like its sibling `decision`/`meeting` properties, with a note that it round-trips via `ActionItemWriter`'s generic non-core `fields` pass-through (no PHP change — see design.md D4)
  - GIVEN `ActionItemWriter::toTaskData()`'s `coreKeys` list WHEN checked THEN `goal` is confirmed NOT present in it (so the generic pass-through applies) — this is a read/verify step, not a code edit
- [x] Implement
- [x] Test — Confirmed by reading `lib/Service/ActionItemWriter.php:212-229`: `coreKeys` is `[title, summary, description, dueDate, due, status, priority, id, uid, calendarId, completed, created, objectUuid, registerId, schemaId, fields]` — `goal` is not in it, so it rides the generic pass-through exactly like `decision`/`meeting`. **REQ-007 (highest risk, proposal.md Risk 2) live-verified end-to-end**: `POST /apps/decidesk/api/action-items` with `{title, taskStatus, goal: <real Goal UUID>}` → 201, write response shows `fields: {taskStatus, goal}` confirming pass-through at write time. Read back through the READ-ONLY `action-item` OpenRegister projection via `GET /apps/openregister/api/objects/decidesk/action-item?_limit=200` (matched by the VTODO uid, which is the projection's `@self.id` — NOT the `objectUuid` the write response returns, those are different identifiers) → `goal: <same UUID>` present and correct. Deleted via `DELETE /apps/decidesk/api/action-items/{uid}` (cleanup, 200). Risk 2 is resolved: the round-trip works exactly as design D4 predicted.

### Task 5: Declare the Goals index/detail pages and menu entry
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-009-goals-index-and-detail-pages-are-declared-not-custom-built`
- **files**: `src/manifest.d/organisation-goals.json` (new)
- **acceptance_criteria**:
  - GIVEN the fragment WHEN merged THEN a Goals index page (register `decidesk`, schema `goal`) and a Goals detail page exist, using the `data`/`related` generic widgets, matching the shape of `src/manifest.d/termijnagenda.json`
  - GIVEN the fragment's `menu` entry WHEN inspected THEN its id is exactly `Goals` — `ia-six-clusters` forward-declares the relocation `"Goals": "ActionItems"` in `src/menu-layout.json`, so this id is a contract; the entry also carries a `_note` recording that placement (this change does not edit `openspec/changes/ia-six-clusters/`)
  - GIVEN the index page WHEN inspected THEN its columns include `title`, `body`, `horizon`, `deadline`, `status` and quick filters on `status`
- [x] Implement
- [x] Test — `node scripts/check-nav-ceiling.js` exits 0: "6 primary / 1 footer / 8 settings top-level entries ... at or under the ADR-004 ceiling, every fragment entry placed" (menu id `Goals` correctly swallowed by `ia-six-clusters`' forward-declared `"Goals": "ActionItems"` relocation). `npx vitest run tests/vitest/navCeilingGate.spec.js` — 10/10 tests pass. `npm run check:manifest` — Ajv validation PASS. `npm run build` completes with exit 0 (only pre-existing, unrelated warnings: `sax`/`stream` polyfill, asset-size limits — no new errors). **Live-verified in the running app**: navigated to `/apps/decidesk/`, expanded "Tasks & Commitments" in the main nav — "Goals" appears nested under it alongside Commitments/Long-term agenda/P&C cycles, linking to `/apps/decidesk/goals`. Goals index page renders all 5 seeded goals in a table with columns Title/Governance body/Horizon/Deadline/Status exactly as declared. Opened the "Amsterdam klimaatneutraal" detail page — renders title, target value, unit and other data-widget fields correctly and editably. Three console errors appear on the Goal detail page (`.../relations` 404, `.../used` 500, `GovernanceBody/<slug>` 404); confirmed **pre-existing and identical** on the already-shipped Termijnagenda detail page (same three error shapes for the same reasons — unrelated to this change, not fixed here per the file-scope constraint).

### Task 6: Live-verify the round-trips and the self-referential aggregation
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md#requirement-req-004-goal-progress-rolls-up-from-linked-commitments-and-tasks`, `#requirement-req-005-goal-supports-single-level-parentchild-cascade`, `#requirement-req-007-actionitem-references-its-goal-through-the-existing-caldav-projection`
- **files**: none (verification against the running dev instance; see test-plan.md TC-4/TC-5/TC-7)
- **acceptance_criteria**:
  - GIVEN seeded Goals G1 (`goal-acme-groeidoelstelling-2028`) and G2 (`goal-acme-mt-operationele-effectiviteit-2026`, `parentGoal: G1`) WHEN G1 is read via the objects API THEN `childGoalCount` is 1 (or, if the engine rejects self-referential filters, is confirmed absent — record which outcome was observed, per proposal.md Risk 1)
  - GIVEN a test ActionItem created via `ActionItemWriter::create()` with `goal` set WHEN read back through the `action-item` OpenRegister projection THEN `goal` is present and correct (proposal.md Risk 2)
  - GIVEN the seeded `toezegging-schouw-marktplein` (now linked to `goal-amsterdam-klimaatneutraal-2050`) WHEN that Goal is read THEN `linkedCommitmentCount` includes it
- [x] Implement (verification-only task, no files)
- [x] Test — **Outcome observed: the self-referential (and every cross-schema) aggregation on Goal degrades to absent/non-functional, exactly the acceptable outcome proposal.md Risk 1 names — but the root cause is broader than "self-reference specifically".** Full investigation:
  - `GET /apps/openregister/api/objects/aggregations/decidesk/goal/childGoalCount` → `{"value": 0}`. Traced why: `AggregationController::aggregate()` calls `AggregationRunner::run()` with no `parentRow` argument (the controller never accepts an object-id/context param), so every `"@self.id"` reference in a named-aggregation filter resolves to `null` at this endpoint — there is no live code path that calls this endpoint scoped to one specific object. This applies identically to Meeting's/Decision's own named aggregations, not just Goal's self-referential one.
  - `nextcloud.log` (`docker exec nextcloud`) shows, on every re-import: `x-openregister-aggregations annotation on schema "goal" is invalid and was ignored (aggregation not registered): Aggregation "linkedCommitmentCount" filter references unknown field "goal". ...` — `AggregationAnnotationValidator` (openregister) validates a filter's field names against the **declaring** schema's own `properties` (Goal's), not the **target** schema's (Toezegging's/ActionItem's/Goal's-for-childGoalCount) — so `goal`/`lifecycle`/`taskStatus`/`parentGoal` are flagged "unknown field" and the entire annotation is discarded (not just the offending key).
  - `nextcloud.log` also shows: `x-openregister-calculations annotation on schema "goal" is invalid and was ignored (calculation not evaluated): Calculation "commitmentSettlementRate": prop "linkedCommitmentCount" is not a property or calculation. ... unknown operator "mul".` — `mul` is not recognised as a supported operator by `CalculationAnnotationValidator` on this build.
  - Despite the validator flagging the annotation, `CalculationOnSaveListener` (a separate code path that does not consult the validator) still runs on create/update and computes the 0-fallback values (confirmed: a live PATCH transitioning a test Goal to `achieved` returned `commitmentSettlementRate: 0, actionItemCompletionRate: 0, childGoalAchievedRate: 0` in the response body). However `nextcloud.log` shows `MagicMapper` then discards them before persisting: `"[MagicMapper] Discarding 3 properties the schema \"Goal\" does not declare: commitmentSettlementRate, actionItemCompletionRate, childGoalAchievedRate. They are NOT stored anywhere — add them to the schema or stop sending them."` — computed fields must also be declared under `properties` for MagicMapper to keep them, which neither this change's calculations nor Meeting's `quorumPercentage`/`totalParticipantCount` do.
  - **Confirmed NOT Goal-specific**: the identical three-layer failure (unknown filter field / unknown operator `mul` / MagicMapper discard) fires for `schema "meeting"` in the same `nextcloud.log`, on the same re-import — i.e. Meeting's `x-openregister-aggregations`/`x-openregister-calculations` (`totalParticipantCount`, `quorumPercentage`, `actionItemCompletionRate` — the pattern design.md D2 cites as "already proven in production") is equally non-functional on this OpenRegister build (v34.0.0.12 / the `custom_apps/openregister` checkout on this shared instance). This is a pre-existing platform gap, not a regression introduced by organisation-goals, and it is out of this change's file scope (openregister/ is not one of the editable files).
  - ActionItem/Goal round-trip (proposal.md Risk 2): **fully verified working** — see Task 4. `linkedCommitmentCount` picking up `toezegging-schouw-marktplein`: not verifiable live for the reasons above (aggregation non-functional) AND the seed cross-link itself did not take effect (Task 2's create-only-seed-importer finding) — recorded, not fabricated.

### Task 7: Validate the change end-to-end
- **spec_ref**: `openspec/changes/organisation-goals/specs/organisation-goals/spec.md`
- **files**: none (validation only)
- **acceptance_criteria**:
  - GIVEN the full change WHEN `openspec validate --change organisation-goals --strict` runs THEN it passes
  - GIVEN the merged register WHEN the Decidesk app settings page loads THEN no schema-load error is logged for `Goal`, `Toezegging`, `TermijnagendaItem`, or `ActionItem`
- [x] Implement (validation-only task, no files)
- [x] Test — `openspec validate organisation-goals --strict` → "Change 'organisation-goals' is valid". Admin settings page (`/index.php/settings/admin/decidesk`) loads with 0 console errors after re-import; `nextcloud.log` shows no schema-load *error* for `Goal`/`Toezegging`/`TermijnagendaItem`/`ActionItem` (only the advisory aggregation/calculation warnings documented in Task 6, which are `level: 2` / non-fatal by design — the app, register, and all four schemas import successfully every time).

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes — `openspec validate organisation-goals --strict` → valid
- [x] Manual testing against acceptance criteria (test-plan.md TC-1 through TC-9) — TC-1/2/3/6/7/8/9 live-verified (see Tasks 1-5 notes); TC-4/TC-5 live-verified as non-functional on this OpenRegister build, a pre-existing platform gap not specific to this change (see Task 6)
- [x] Code review against spec requirements — performed 2026-08-19 by the orchestrating judge pass plus an independent verification agent (all 9 requirements re-evidenced against files and live HTTP round-trips; verdict PASS-WITH-NOTES, zero defects)

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
