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
- [ ] Implement
- [ ] Test

### Task 2: Declare the DecisionTemplate schema with the full built-in seed set
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/process-configuration/spec.md#requirement-unified-decisiontemplate-schema-declaration`, `#requirement-decision-template-checklist`, `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-010-vvedecisiontemplate-and-modelreglementpreset-superseded-by-decisiontemplate`
- **files**: `lib/Settings/register.d/67-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `decision-template` schema exists with `decisionType`, `context`, `templateCategory`, `initialState`, `stateMachine`, `votingRule`, `quorumRequired`, `quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`, `proposedText`, `regulationSource`, `checklist[]` per design.md
  - GIVEN the seed data WHEN listed THEN 13 built-in objects exist: 5 ported from `ProcessTemplate` (`decisionType` absent), 2 ported from the urgency delta (`builtIn: false`, `decisionType` absent), 6 ported from `VveDecisionTemplate` (`context=association`, `decisionType=resolution`, `templateCategory` set, `votingRule`/`quorumRule` for `machtiging-boven-drempel` and `wijziging-huishoudelijk-reglement` folded from the 2017 modelreglement's `categoryRules`) — see design.md Seed Data for the exact per-object field table
  - GIVEN any ported seed WHEN compared to its source object THEN every carried-forward field (`stateMachine`, `votingRule`, `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`, `proposedText`, `regulationSource`) is byte-identical to the source
- [ ] Implement
- [ ] Test

### Task 3: Supersede the three legacy template schemas, non-destructively
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/process-configuration/spec.md#requirement-legacy-template-schemas-superseded-non-destructively`, `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-010-vvedecisiontemplate-and-modelreglementpreset-superseded-by-decisiontemplate`
- **files**: `lib/Settings/register.d/67-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the fragment's patch to `ProcessTemplate`, `VveDecisionTemplate`, `ModelreglementPreset` WHEN each schema is inspected THEN `x-openregister.active` is `false` and the `description` names `decision-template` as the successor
  - GIVEN the patch WHEN `ProcessTemplateService::list()`/`::get()`/`::resolvePolicyForBody()` are exercised (unit tests) THEN every existing test still passes unmodified — confirms `active: false` is a create-time guard only, never a read guard
  - GIVEN the fragment is removed (manual rollback check) WHEN the register reloads THEN all three schemas revert to `active: true` with no other change needed
- [ ] Implement
- [ ] Test

### Task 4: Retarget VveConfiguration.modelRegulation to a version enum
- **spec_ref**: `openspec/changes/unified-decision-templates/specs/vve-alv-pack/spec.md#requirement-req-vve-011-vveconfiguration-modelregulation-retargeted-to-a-version-enum`
- **files**: `lib/Settings/register.d/67-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN the `VveConfiguration` patch WHEN inspected THEN `modelRegulation` ($ref to `ModelreglementPreset`) is replaced by `modelReglementVersion` (string enum `1992`/`2006`/`2017`) and `majorityOverrides[].decisionCategory` is renamed to `majorityOverrides[].templateCategory` (same enum values, no value-set change)
  - GIVEN the seeded `vve-zeewaarts-configuratie` object WHEN migrated/re-seeded THEN `modelReglementVersion` reads `"2017"` and `majorityOverrides[0].templateCategory` reads `"amendment-internal-regulations"`
  - GIVEN `VveConfiguration` has zero PHP/Vue consumers today (verified by grep before this change) WHEN the retarget lands THEN no other file requires an edit
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (register import produces the 13 seeds + 3 superseded-schema patches + the VveConfiguration retarget; repair migration produces the expected migrated objects on a fixture install with pre-existing `process-template`/`vve-decision-template` custom objects)
- [ ] Code review against spec requirements — including the two Open Questions in proposal.md/design.md (the `decisionType=resolution` mapping for VvE seeds, and whether to scaffold the two dependent chain changes now)

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit test for `MigrateLegacyTemplatesToDecisionTemplate` (`tests/Unit/Migration/`): covers create, skip-on-rerun, and non-destruction of source objects (mock `ObjectServiceInterface`, mirroring the existing `ProcessTemplateServiceTest` mocking pattern)
- [ ] Newman/Postman: N/A — no new or modified API endpoint in this change (schema-declaration only; `ProcessTemplateController`'s routes are untouched)
- [ ] Browser tests (Playwright MCP): N/A — no UI change in this change
- [ ] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- [ ] `docs/ARCHITECTURE.md`'s `ProcessTemplate` entity section updated to note `decision-template` as the schema going forward for new template work, with `process-template` marked legacy/superseded (matches the `.claude/CLAUDE.md` fix-pre-existing-issues-when-encountered convention — a reader hitting this section next should not be misled)
- [ ] N/A — no new screenshot; no UI surface changes in this slice

## i18n (company-wide ADR-005)
- [ ] N/A — no new user-facing strings (schema `title`/`description` fields are developer/admin-API-facing metadata, not end-user UI strings, matching the existing `ProcessTemplate`/`VveDecisionTemplate` schemas' own posture)
