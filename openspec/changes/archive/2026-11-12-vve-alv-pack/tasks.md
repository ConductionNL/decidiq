# Tasks: vve-alv-pack

## Implementation Tasks

### Task 1: Register fragment 57 — VveConfiguration, VveDecisionTemplate, ModelreglementPreset, KascommissieVerklaring schemas
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-001-vve-statutory-schemas-on-openregister`
- **files**: `lib/Settings/register.d/57-vve-alv-pack.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `vve-configuration`, `vve-decision-template`, `modelreglement-preset`, and `kascommissie-verklaring` schemas exist with all required fields, property titles, `x-schema-org` annotations, and `x-openregister-relations`, and no existing schema in `decidesk_register.json` is modified (fragment number 57 exclusively — 40–56 and 58–65 belong to siblings)
  - GIVEN a `vve-configuration` WHEN saved without body, preset, or denominator THEN OpenRegister schema validation rejects it; the `splitsingsakteDocument` field is a plain reference (governing-documents-register boundary, never a document registration)
  - GIVEN the fragment WHEN inspected THEN no schema carries lifecycle/notification dialects or financial ledger properties, and no writeOnly/secret fields exist
- [ ] Implement
- [ ] Test

### Task 2: Seed data — presets 1992/2006/2017, six built-in templates, and the VvE Zeewaarts demo set
- **spec_ref**: `openspec/changes/vve-alv-pack/design.md#seed-data`, `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-002-built-in-vve-statutory-decision-templates`, `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-003-modelreglement-presets-with-splitsingsakte-override`
- **files**: `lib/Settings/register.d/57-vve-alv-pack.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN the three `builtIn: true` modelreglement presets (category rules with article sources) and six `builtIn: true` decision templates (default majorities/quorum per the design table) exist per the design tables, with nil-UUID placeholders only for unresolved refs
  - GIVEN the VvE Zeewaarts demo set WHEN inspected THEN the configuration (modelreglement-2017, denominator 10.000, one akte majorityOverride), 24 Person+Membership breukdelen records summing to 10.000, two kascommissie verklaringen (one with a FileService verslag attachment), two template-instantiated Decisions, and the ALV meeting whose agenda misses the MJOP item all exist — making the warning, override, and decharge paths demoable on install
- [ ] Implement
- [ ] Test

### Task 3: Majority resolver + built-in guard + template instantiation
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-003-modelreglement-presets-with-splitsingsakte-override` (+ REQ-VVE-002)
- **files**: `lib/Service/VveMajorityResolver.php`, `lib/Controller/VveController.php`, `appinfo/routes.php`, `tests/Unit/Service/VveMajorityResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a round-open for a decision in a VvE decision category without explicit rules WHEN defaults resolve THEN precedence is caller > splitsingsakte override > preset category rule > template default > existing behaviour, the applied value feeds the existing voting-rule defaults (no parallel enum/tally/quorum path), the source tier is recorded, and a body without a `vve-configuration` is entirely unaffected (fail-soft; PHPUnit covers every precedence pair)
  - GIVEN a built-in preset or template WHEN edit/delete is attempted THEN it is refused server-side while duplicate yields an editable copy with `builtIn` cleared (ProcessTemplateService mechanism); instantiating a template creates an ordinary pre-filled Decision
  - GIVEN the two controller actions WHEN gates run THEN `#[NoAdminRequired]` + per-object governance guard pass no-admin-idor/semantic-auth, routes are reachable, and saves are PUT-semantic (all fields carried forward)
- [ ] Implement
- [ ] Test

### Task 4: Breukdelen presentation — fractions in attendees, quorum, tally, and results
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-004-breukdelen-presentation-over-existing-votingweight`
- **files**: `src/services/breukdelen.js`, attendee/quorum/voting/results surfaces in `src/`
- **acceptance_criteria**:
  - GIVEN a body with a `vve-configuration` WHEN attendee list, meeting detail, quorum display, live tally, and closed-round results render THEN `votingWeight` shows as `<weight>/<denominator>` (e.g. `150/10.000`), meeting totals and required/present quorum are expressed in breukdelen, and vote results show breukdelen alongside head-count with accessible labels
  - GIVEN the implementation WHEN reviewed THEN it is a pure formatter over already-fetched data (vitest-covered, no extra API calls), the voting-system tally/threshold/quorum engines are untouched, and a body without a configuration keeps the existing plain-number display
- [ ] Implement
- [ ] Test

### Task 5: Breukdelen sum validation warning
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-005-breukdelen-sum-validation-warning`
- **files**: `src/services/breukdelen.js`, membership management + quorum display wiring in `src/`
- **acceptance_criteria**:
  - GIVEN active memberships summing to 9.850 against denominator 10.000 WHEN membership management or the meeting quorum display renders THEN a non-blocking icon+text warning names the sum and expected denominator, expired memberships are excluded from the sum, and saving/meeting conduct proceeds
  - GIVEN the sum matches the denominator WHEN the display refreshes THEN the warning is gone (pure function, vitest-covered)
- [ ] Implement
- [ ] Test

### Task 6: Kascommissie flow — verslag upload, verklaring recording, decharge feed
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-006-kascommissie-verslag-and-verklaring-feed-the-decharge`
- **files**: `src/dialogs`/`src/modals` (KascommissieVerklaring dialog per modal-isolation gate), agenda-item + decision detail wiring in `src/`
- **acceptance_criteria**:
  - GIVEN the jaarrekening agenda item of a VvE ALV WHEN the kascommissie verslag is uploaded and a verdict recorded THEN a `kascommissie-verklaring` object exists for the boekjaar with the file attached via FileService and linked to the agenda item
  - GIVEN a decharge decision instantiated from the template WHEN it renders THEN it references the boekjaar's verklaring and shows its verdict; with no verklaring or verdict `afkeurend` a visible warning appears while the decision remains decidable (never a transition guard)
- [ ] Implement
- [ ] Test

### Task 7: VvE statutory agenda-items completeness warning
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md#requirement-req-vve-007-vve-statutory-alv-agenda-items-completeness`
- **files**: `src/services/agendaRules.js`, AgendaBuilder/MeetingAgendaTab wiring
- **acceptance_criteria**:
  - GIVEN a `general_assembly` meeting of a body with a `vve-configuration` WHEN the agenda misses kascommissieverslag, jaarrekening, begroting, or MJOP-status THEN the existing warning surface additionally lists the missing VvE items (additive `STATUTORY_VVE_ITEMS` with en+nl synonyms, same matching behaviour)
  - GIVEN a non-VvE `general_assembly` WHEN evaluated THEN only the existing `STATUTORY_ALV_ITEMS` apply — the base list, its behaviour, and the agenda-management spec are unchanged (vitest covers both branches)
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for the VvE pack
- **spec_ref**: `openspec/changes/vve-alv-pack/specs/vve-alv-pack/spec.md`
- **files**: `tests/e2e/vve-alv-pack.spec.ts`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude`
  - GIVEN the seeded environment WHEN the suite runs THEN breukdelen rendering + sum warning, template duplicate (read-only built-in), kascommissie verklaring recording → decharge warning/verdict display, and the VvE agenda completeness warning pass end-to-end against VvE Zeewaarts
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) and vitest (breukdelen, agendaRules)
- New/changed API endpoints (instantiate/duplicate) covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/vve-alv-pack.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 28/30/51/52 manifest/slug checks, redundant-controller, modal-isolation, no-admin-idor/semantic-auth on the two actions)
- WCAG 2.1 AA verified on the breukdelen voting/quorum surfaces (icon+text warnings, accessible fraction labels)
- `openspec validate` passes
