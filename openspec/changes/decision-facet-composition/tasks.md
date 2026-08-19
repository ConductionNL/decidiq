# Tasks: decision-facet-composition

## Implementation Tasks

### Task 1: Consultation widgets on DecisionDetail
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md#requirement-decision-detail-surfaces-referencing-consultations-req-dfc-001`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `PublicConsultation` whose `decision` equals Decision D WHEN D's detail page opens THEN the "Public consultations" widget lists it, row links to `ConsultationDetail`
  - GIVEN a `MemberConsultation` whose `decision` equals Decision D WHEN D's detail page opens THEN the "Member consultations" widget lists it, row links to `RaadplegingDetail`; with none, the widget shows its empty-state text
  - GIVEN a `ConsultationRequest` whose `relatedDecision` equals Decision D WHEN D's detail page opens THEN the "Works council (WOR)" widget lists it, row links to `WorTrajectDetail`
- [ ] Implement
- [ ] Test

### Task 2: Advisory-opinion widget on DecisionDetail
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md#requirement-decision-detail-surfaces-advisory-opinion-requests-req-dfc-002`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an `adviceRequest` (Adviesaanvraag) whose `relatedDecision` equals Decision D WHEN D's detail page opens THEN the "Advisory opinions" widget lists it with subject + lifecycle, row links to `AdviesaanvraagDetail`
- [ ] Implement
- [ ] Test

### Task 3: Zienswijzeronde and Zienswijze widgets on DecisionDetail
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md#requirement-decision-detail-surfaces-zienswijzerondes-and-zienswijzen-req-dfc-003`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `Zienswijzeronde` whose `decision` equals Decision D WHEN D's detail page opens THEN the "Zienswijzerondes" widget lists it
  - GIVEN a `Zienswijze` whose `decision` equals Decision D WHEN D's detail page opens THEN the "Zienswijzen" widget lists it, row links to `ZienswijzerondeDetail`
- [ ] Implement
- [ ] Test

### Task 4: Commitments widget on DecisionDetail
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md#requirement-decision-detail-surfaces-commitments-req-dfc-004`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `Toezegging` whose `relatedMotion` equals Decision D WHEN D's detail page opens THEN the "Commitments" widget lists it with deadline + lifecycle, row links to `ToezeggingDetail`
- [ ] Implement
- [ ] Test

### Task 5: Confidentiality status widget on DecisionDetail
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md#requirement-decision-detail-surfaces-confidentiality-status-req-dfc-005`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `Geheimhouding` in state `opgelegd` whose `targetDecision` equals Decision D WHEN D's detail page opens THEN the "Confidentiality" widget shows one row with ground, lifecycle, and `ratificationDeadline`, and offers no add action (`allowCreate: false`)
  - GIVEN Decision D has no `Geheimhouding` referencing it as `targetDecision` WHEN D's detail page opens THEN the widget shows its empty-state text
- [ ] Implement
- [ ] Test

### Task 6: Layout placement, i18n, and browser-test coverage
- **spec_ref**: `openspec/changes/decision-facet-composition/specs/decision-management/spec.md`
- **files**: `src/manifest.json`, `src/l10n/nl.json`, `src/l10n/en.json` (or equivalent translation source), `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN the 8 new widgets WHEN `DecisionDetail`'s `layout` array is checked THEN they occupy 3 grid rows below the existing 9 widgets with no gridX/gridY overlap
  - GIVEN a Playwright spec WHEN it opens a decision seeded with at least one referencing object per new widget THEN every widget listed in Task 1–5's acceptance criteria is asserted present with its row link
  - GIVEN the widget titles/empty-state strings WHEN the app loads in `nl_NL` THEN Dutch strings render (no raw English fallback) per ADR-005/025
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Quality checklist

- All 8 widgets use `type: "object-list"` — no new Vue component, no new route, no PHP change (design.md D1/Declarative-vs-imperative table)
- Filter field names (`decision`, `relatedDecision`, `relatedMotion`, `targetDecision`) match the shipped `lib/Settings/register.d/*.json` fragments exactly (design.md D3) — re-verify against those files if any sibling schema changed since this proposal was written
- `hydra-gate-e2e-coverage`: every ADDED scenario in the delta spec is covered by the Task 6 Playwright spec or carries a reason-bearing `@e2e exclude`
- `hydra-gate-manifest-validation` / gates 28/30/51/52 pass on the `src/manifest.json` diff
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all 8 widget titles + empty-state strings (ADR-005/025)
- Feature documentation updated in `docs/` with a screenshot of the composed DecisionDetail page (ADR-010)
- `openspec validate` passes
