# Tasks: platform-accessibility-audit-report

## Implementation tasks

### Task 1: The structured sample
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-001-the-evaluated-pages-are-a-structured-sample`
- **files**: `tests/e2e/a11y/sample.json`, `scripts/wcag-sample-check.mjs`, `tests/vitest/wcagSampleCheck.spec.js`
- **acceptance_criteria**:
  - GIVEN a fixture manifest with an unsampled page type WHEN the check runs THEN it fails naming the page (vitest, red then green)
  - GIVEN the current manifests WHEN the check runs THEN it passes
- [x] Implement
- [x] Test

### Task 2: The axe-core scan with owners
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding`
- **files**: `tests/e2e/a11y/wcag-audit.spec.ts`, `tests/e2e/a11y/owner.js`, `tests/vitest/wcagOwner.spec.js`, `.gitignore` (keep `tests/axe/report.json` out of commits)
- **acceptance_criteria**:
  - GIVEN a fixture page with an unnamed button in the app root WHEN scanned THEN a serious `decidiq` violation fails the run (Playwright)
  - GIVEN fixture nodes in the header, the app root and a portal page WHEN attributed THEN `nextcloud`, `decidiq` and `portaliq` (vitest)
  - GIVEN a run WHEN it ends THEN `tests/axe/report.json` validates against the shape the hydra axe gate reads
- [x] Implement
- [x] Test

### Task 3: The manual checklist
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-003-criteria-axe-cannot-decide-are-checked-by-hand-and-never-assumed`
- **files**: `docs/compliance/wcag-manual-checks.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN validated THEN every WCAG 2.1 A and AA criterion axe does not decide has an entry with a plain check text
  - GIVEN a tester's first pass on the dev instance WHEN recorded THEN each entry carries result, tester and date (live check)
- [x] Implement
- [x] Test

### Task 4: The report generator
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report`
- **files**: `scripts/wcag-audit-report.mjs`, `tests/vitest/wcagAuditReport.spec.js`, `package.json` (`a11y:report`), `docs/compliance/wcag-2.1-aa-audit.md`, `docs/sidebars.js`
- **acceptance_criteria**:
  - GIVEN twelve empty checklist entries WHEN generated THEN twelve `not-tested` in the summary (vitest, red then green)
  - GIVEN a scan and a full checklist WHEN generated THEN every A and AA criterion has one row
  - GIVEN the docs build WHEN it runs THEN the report is linked under compliance
- [x] Implement
- [x] Test

### Task 5: The scan step in the shared quality workflow (ConductionNL/.github)
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report`
- **files**: ConductionNL/.github `.github/workflows/quality.yml` (new input `wcag-audit-command`, default empty; in the Playwright job, after the instance is up and only when the input is set and the ref is a tag, run the command and upload `tests/axe/report.json` and `docs/compliance/wcag-2.1-aa-audit.md` as the artefact `wcag-audit-report`)
- **status**: open. The scan needs the running Nextcloud that only the shared workflow's Playwright job has (Ruben, DECISIONS row 28, 30 Sep). The existing `enable-axe` input scans routes for gate 33; it does not run an app's own sample or write the report, so this is a separate input.
- **acceptance_criteria**:
  - GIVEN a caller without `wcag-audit-command` WHEN the workflow runs THEN nothing changes for it
  - GIVEN a caller with the input WHEN a tag build runs THEN the artefact `wcag-audit-report` holds both files
  - GIVEN the command exits non-zero WHEN the step ends THEN the artefact is still uploaded and the step reports the failure
- [ ] Implement
- [ ] Test

### Task 6: decidiq's caller runs the audit on tags
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report`
- **files**: `.github/workflows/code-quality.yml` (pass `wcag-audit-command: npm run a11y:audit` to the shared workflow), `package.json` (`a11y:audit` already exists)
- **depends_on**: Task 5 on ConductionNL/.github main
- **acceptance_criteria**:
  - GIVEN the next tag WHEN its workflow run ends THEN the report is an artefact of that run (read once at the end, not polled)
- [ ] Implement
- [ ] Test

### Task 7: The first tester pass on the manual checklist
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-003-criteria-axe-cannot-decide-are-checked-by-hand-and-never-assumed`
- **files**: `docs/compliance/wcag-manual-checks.json` (result, tester and date per entry), `docs/compliance/wcag-2.1-aa-audit.md` (regenerated with `npm run a11y:report`)
- **acceptance_criteria**:
  - GIVEN the checklist WHEN the pass is recorded THEN no entry is left without result, tester and date
  - GIVEN the regenerated report WHEN read THEN its summary counts no `not-tested` criteria
- [ ] Implement
- [ ] Test
