# Tasks: insight-decisions-report-by-period-and-body

## Implementation tasks

### Task 1: The deciding body on a decision
- **spec_ref**: `openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it`
- **files**: `lib/Settings/register.d/92-decisions-report-cut.json`, `lib/Listener/DecisionDecidingBodyListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `lib/Repair/FillDecisionDecidingBody.php`, `appinfo/info.xml`, `tests/Unit/Listener/DecisionDecidingBodyListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a decision created from a council meeting WHEN saved THEN `decidingBody` is the council's uuid
  - GIVEN a decision with a body set by hand WHEN its meeting changes THEN the hand-set body stays
  - GIVEN existing decisions with a meeting WHEN the repair step runs THEN each gets its meeting's body, and running it twice changes nothing
- [ ] Implement
- [ ] Test (real OpenRegister events)

### Task 2: The aggregation endpoint takes the period
- **spec_ref**: `openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-002-every-figure-on-the-decisions-report-follows-the-chosen-period`
- **files**: `tests/newman/decisions-report-cut.json`
- **acceptance_criteria**:
  - GIVEN three decisions dated 2025 and two dated 2026 WHEN the aggregation endpoint counts with `decisionDate` gte 2026-01-01 THEN it answers 2
- [ ] Implement
- [ ] Test (Newman; this is the proof the page note was wrong)

### Task 3: Period picker on the report
- **spec_ref**: `openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-002-every-figure-on-the-decisions-report-follows-the-chosen-period`
- **files**: `src/manifest.json` (`DecisionsReport` dateRange, every source filter, new `_note`)
- **acceptance_criteria**:
  - GIVEN the report WHEN the griffier picks last year THEN every tile and chart counts only decisions dated last year
  - GIVEN `tests/validate-manifest.js` WHEN run THEN it passes
- [ ] Implement
- [ ] Test (Playwright compares the Adopted tile before and after the pick)

### Task 4: Body picker and By body chart
- **spec_ref**: `openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-003-the-decisions-report-can-be-cut-per-body`
- **files**: `src/components/reports/DecisionsReportBodyPicker.vue`, `src/registry.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN decisions of the council and the college WHEN the griffier picks the college THEN every figure counts the college's decisions only
  - GIVEN no body picked WHEN the page loads THEN all bodies count and the By body chart shows each
- [ ] Implement
- [ ] Test (vitest for the picker writing the workspace key, Playwright end to end)

### Task 5: Open the same decisions as a list
- **spec_ref**: `openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-004-the-report-opens-its-decisions-as-an-exportable-list`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the college and this quarter picked WHEN the griffier chooses Show these decisions THEN the Decisions list opens with the same body and dates applied and its export exports those rows
- [ ] Implement
- [ ] Test (Playwright)

## Verification

- `composer check:strict` and `npm run lint` once before push.
