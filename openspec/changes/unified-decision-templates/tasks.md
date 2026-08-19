# Tasks: unified-decision-templates

## Implementation Tasks

### Task 1: Repair migration — migrate live templates into DecisionTemplate objects
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/process-configuration/spec.md#requirement-live-legacy-template-objects-are-repaired-into-decisiontemplate-objects`
- **files**: `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a live `process-template` or `vve-decision-template` object WHEN the repair step runs THEN an equivalent `decision-template` object is created carrying `migratedFrom: {sourceSchema, sourceUuid}` and every field mapped per migration.md steps 3–4
  - GIVEN the repair step has already migrated an object WHEN it runs again THEN no duplicate `decision-template` object is created (idempotency check on `migratedFrom`)
  - GIVEN the repair step runs WHEN it completes THEN every source `process-template`/`vve-decision-template` object is unchanged (read-only against sources)
  - GIVEN `appinfo/info.xml` WHEN inspected THEN the new step is listed under `<repair-steps><post-migration>` AFTER `OCA\Decidesk\Repair\InitializeSettings`
- [x] Implement
- [x] Test

### Task 2: Declare the DecisionTemplate schema with the full built-in seed set
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/process-configuration/spec.md#requirement-unified-decisiontemplate-schema-declaration`, `#requirement-decision-template-checklist`, `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-010-vvedecisiontemplate-and-modelreglementpreset-superseded-by-decisiontemplate`
- **files**: `lib/Settings/register.d/68-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `decision-template` schema exists with `decisionType`, `context`, `templateCategory`, `initialState`, `stateMachine`, `votingRule`, `quorumRequired`, `quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`, `proposedText`, `regulationSource`, `checklist[]` per design.md
  - GIVEN the seed data WHEN listed THEN 13 built-in objects exist: 5 ported from `ProcessTemplate` (`decisionType` absent), 2 ported from the urgency delta (`builtIn: false`, `decisionType` absent), 6 ported from `VveDecisionTemplate` (`context=association`, `decisionType=resolution`, `templateCategory` set, `votingRule`/`quorumRule` for `machtiging-boven-drempel` and `wijziging-huishoudelijk-reglement` folded from the 2017 modelreglement's `categoryRules`) — see design.md Seed Data for the exact per-object field table
  - GIVEN any ported seed WHEN compared to its source object THEN every carried-forward field (`stateMachine`, `votingRule`, `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`, `proposedText`, `regulationSource`) is byte-identical to the source
- [x] Implement
- [x] Test

### Task 3: Supersede the three legacy template schemas, non-destructively
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/process-configuration/spec.md#requirement-legacy-template-schemas-superseded-non-destructively`, `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-010-vvedecisiontemplate-and-modelreglementpreset-superseded-by-decisiontemplate`
- **files**: `lib/Settings/register.d/68-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the fragment's patch to `ProcessTemplate`, `VveDecisionTemplate`, `ModelreglementPreset` WHEN each schema is inspected THEN `x-openregister.active` is `false` and the `description` names `decision-template` as the successor
  - GIVEN the patch WHEN `ProcessTemplateService::list()`/`::get()`/`::resolvePolicyForBody()` are exercised (unit tests) THEN every existing test still passes unmodified — confirms `active: false` is a create-time guard only, never a read guard
  - GIVEN the fragment is removed (manual rollback check) WHEN the register reloads THEN all three schemas revert to `active: true` with no other change needed
- [x] Implement
- [x] Test

### Task 4: Retarget VveConfiguration.modelRegulation to a version enum
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-011-vveconfiguration-modelregulation-retargeted-to-a-version-enum`
- **files**: `lib/Settings/register.d/68-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the `VveConfiguration` patch WHEN inspected THEN `modelRegulation` ($ref to `ModelreglementPreset`) is replaced by `modelReglementVersion` (string enum `1992`/`2006`/`2017`) and `majorityOverrides[].decisionCategory` is renamed to `majorityOverrides[].templateCategory` (same enum values, no value-set change)
  - GIVEN the seeded `vve-zeewaarts-configuratie` object WHEN migrated/re-seeded THEN `modelReglementVersion` reads `"2017"` and `majorityOverrides[0].templateCategory` reads `"amendment-internal-regulations"`
  - GIVEN `VveConfiguration` has zero PHP/Vue consumers today (verified by grep before this change) WHEN the retarget lands THEN no other file requires an edit
- [x] Implement
- [x] Test
- **DEVIATION (builder note):** "replaced"/"renamed" in the acceptance criteria is implemented additively, not as a schema-level removal — ADR-037 fragment deep-merges are additive-only (key union; a list like `required` is concatenated, never replaced), so a fragment cannot literally delete `modelRegulation`/`majorityOverrides[].decisionCategory`. `modelReglementVersion` and `majorityOverrides[].templateCategory` are declared as new properties alongside the old ones, with the old properties' descriptions marked superseded (design.md itself calls this "informational only... a safe, additive-shaped retarget", consistent with this reading). Second deviation: the pre-existing `vve-zeewaarts-configuratie` seed object (from `57-vve-alv-pack.json`) is **not** re-declared with the new field names — OR seed import is create-only, and re-declaring the same slug risks either a silent no-op or (unverified) a duplicate object row. Instead, following the precedent already established in `67-model-debt-cleanup.json` ("demonstrate every new/retargeted property on a FRESHLY-CREATED object, never a patch to an existing seeded row"), a new demo object `vve-parkstaete-configuratie` (bound to the existing `vve-parkstaete` governance-body seed from `55-governing-documents-register.json`) demonstrates `modelReglementVersion: "2017"` and `majorityOverrides[0].templateCategory: "amendment-internal-regulations"` on a fresh install. `vve-zeewaarts-configuratie` itself keeps its original `modelRegulation`/`decisionCategory` shape until a live-data repair (out of scope here — `VveConfiguration` has zero consumers, so this carries no runtime risk).

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (register import produces the 13 seeds + 3 superseded-schema patches + the VveConfiguration retarget; repair migration produces the expected migrated objects on a fixture install with pre-existing `process-template`/`vve-decision-template` custom objects) — **deferred**: the shared dev instance (:8080) is mid-programme with other agents' work in flight; live re-import verification not run in this pass. `python3 -m json.tool` confirms the fragment is valid JSON and the seed/schema counts were verified statically (see builder report).
- [ ] Code review against spec requirements — including the two Open Questions in proposal.md/design.md (the `decisionType=resolution` mapping for VvE seeds, and whether to scaffold the two dependent chain changes now) — pending human review

## Tests (company-wide ADR-009)
- [x] PHPUnit unit test for `MigrateLegacyTemplatesToDecisionTemplate` (`tests/Unit/Migration/`): covers create, skip-on-rerun, and non-destruction of source objects (mock `ObjectServiceInterface`, mirroring the existing `ProcessTemplateServiceTest` mocking pattern)
- [x] Newman/Postman: N/A — no new or modified API endpoint in this change (schema-declaration only; `ProcessTemplateController`'s routes are untouched)
- [x] Browser tests (Playwright MCP): N/A — no UI change in this change
- [x] All tests pass (`composer test`) — full suite run confirmed by the builder (see report); no new failures introduced

## Documentation (company-wide ADR-010)
- [x] `docs/ARCHITECTURE.md`'s `ProcessTemplate` entity section updated to note `decision-template` as the schema going forward for new template work, with `process-template` marked legacy/superseded (matches the `.claude/CLAUDE.md` fix-pre-existing-issues-when-encountered convention — a reader hitting this section next should not be misled)
- [x] N/A — no new screenshot; no UI surface changes in this slice

## i18n (company-wide ADR-005)
- [x] N/A — no new user-facing strings (schema `title`/`description` fields are developer/admin-API-facing metadata, not end-user UI strings, matching the existing `ProcessTemplate`/`VveDecisionTemplate` schemas' own posture)

## Verification Notes (opsx-verify 2026-08-19)
VERDICT: PASS-WITH-NOTES

- File-naming drift: proposal.md/design.md/migration.md/tasks.md all name the fragment `68-unified-decision-templates.json`; the file that was actually created is `lib/Settings/register.d/68-unified-decision-templates.json:1` (67 was already taken by `67-model-debt-cleanup.json`, which landed concurrently). `appinfo/info.xml:149-155` and `docs/ARCHITECTURE.md:707` correctly reference `68-`. `SettingsService.php:358` globs `register.d/*.json` with no hard-coded numbering dependency, so this is cosmetic doc drift, not a functional bug — noted for a follow-up doc fix, not blocking.
- Requirement "Unified DecisionTemplate schema declaration" (process-configuration): OK — `lib/Settings/register.d/68-unified-decision-templates.json:375-703` declares `decision-template` with `decisionType`/`context`/`templateCategory`/`stateMachine`/`votingRule`/`quorumRequired`/`quorumRule`/`allowDecideWithoutVote`/`urgencyPolicy`/`proposedText`/`regulationSource`/`checklist`. `decisionType` enum (lines 411-422) is byte-identical to the live `Decision.decisionType` enum in `lib/Settings/decidesk_register.json` (`motion…meeting-outcome`, verified by direct comparison).
- Requirement "Decision template checklist": OK — `68-unified-decision-templates.json:649-680` declares `checklist[]` (`sequence`/`label`/`required`/`description`); all 13 built-in seeds carry `"checklist": []` (verified programmatically — no seed omits the key).
- Requirement "Legacy template schemas superseded, non-destructively": OK — `68-unified-decision-templates.json:709,720,731` set `x-openregister.active: false` for `ProcessTemplate`/`VveDecisionTemplate`/`ModelreglementPreset` respectively, each `description` naming `decision-template` as successor (lines 706,717,728); `active: true` on the new schema itself at line 387. No PHP/Vue file under `lib/Controller`, `lib/Service`, `lib/Lifecycle`, `src/` is touched by this change (grep-confirmed, only `info.xml` + `docs/ARCHITECTURE.md` reference the new schema/fragment).
- Requirement "Live legacy template objects are repaired into DecisionTemplate objects": OK — `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php:60-426` implements `IRepairStep`, reads `process-template`/`vve-decision-template` via `ObjectService::findAll()`, idempotency guard via `buildMigratedIndex()` (lines 185-226) keyed on `migratedFrom.sourceSchema|sourceUuid`, never calls `deleteObject`/edits a source. Registered `appinfo/info.xml:155`, confirmed positioned after `InitializeSettings` (`info.xml:128`). `tests/Unit/Migration/MigrateLegacyTemplatesToDecisionTemplateTest.php` covers verbatim-field migration, VvE field mapping, skip-on-rerun, and non-destruction (asserts `deleteCalls === 0`).
- Requirement REQ-VVE-010 (VveDecisionTemplate/ModelreglementPreset superseded): OK — `68-unified-decision-templates.json:271-349` re-seeds all 6 VvE templates (`decharge-bestuur`, `vaststelling-jaarrekening`, `dotatie-reservefonds`, `vaststelling-mjop`, `machtiging-boven-drempel`, `wijziging-huishoudelijk-reglement`) with `context=association`, `decisionType=resolution`, `templateCategory` set from the former `decisionCategory`, `proposedText`/`regulationSource` byte-identical to `lib/Settings/register.d/57-vve-alv-pack.json`'s `vve-decision-template` seeds (verified by direct field comparison, zero mismatches). The 2017 `categoryRules` folding is numerically correct: `machtiging-boven-drempel.quorumRule` = `"2/3 (MR 2017 art. 56 lid 5)"` matches `modelreglement-2017.categoryRules[authorisation-above-threshold]` (`quorumFraction:"2/3"`, `article:"MR 2017 art. 56 lid 5"`) in `57-vve-alv-pack.json`; `wijziging-huishoudelijk-reglement` correctly has no `quorumRule` (source `quorumFraction: null`).
- Requirement REQ-VVE-011 (VveConfiguration.modelRegulation retargeted): PARTIAL — schema-level retarget is genuinely additive as claimed: `modelRegulation` ($ref, required) is kept unchanged (`68-unified-decision-templates.json:741-747`), `modelReglementVersion` is added alongside it (748-758); `majorityOverrides[].decisionCategory` remains declared in the base `57-vve-alv-pack.json` fragment (verified — not removed), `majorityOverrides[].templateCategory` is added via deep-merge (767-780), consistent with ADR-037's additive-only merge semantics. **However**, the spec-delta's own scenario "VveConfiguration seed carries the plain version enum" (`specs/vve-alv-pack/spec.md:81-88`) explicitly names `vve-zeewaarts-configuratie` as the object that should carry `modelReglementVersion: "2017"` after this delta — that object was NOT touched (confirmed: no `vve-zeewaarts-configuratie` object appears in `68-unified-decision-templates.json`'s seed data). Task 4's own DEVIATION note explains why (OR seed import is create-only) and substitutes a new demo object `vve-parkstaete-configuratie` (`68-unified-decision-templates.json:352-368`, carries both old and new field names correctly), but the spec-delta scenario text itself was never updated to match this deviation — a reader of `specs/vve-alv-pack/spec.md` alone would expect `vve-zeewaarts-configuratie` to have been updated and would be wrong. Recommend updating the scenario in `specs/vve-alv-pack/spec.md` to name `vve-parkstaete-configuratie` (or documenting the live-data gap explicitly) before archiving.
- `openspec validate unified-decision-templates --strict`: **passes** (`Change 'unified-decision-templates' is valid`).
- All `[x]` boxes in Tasks 1-4 checked against actual files: no phantom-ticked box found — every claimed file/behavior was independently verified present as described, modulo the REQ-VVE-011 spec-scenario gap noted above.
