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

### Task 5: The report on every tagged build
- **spec_ref**: `openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report`
- **files**: `.github/workflows/code-quality.yml`
- **status**: open. The scan needs the running Nextcloud that only the shared workflow's Playwright job has, so the step belongs in ConductionNL/.github `quality.yml` (Ruben, DECISIONS row 28, 30 Sep). Drafted for his review; once it is on `.github` main, decidiq's caller adds `wcag-audit-command: npm run a11y:audit` and this task closes. Until then plt-22 stays `building`.
- **acceptance_criteria**:
  - GIVEN a tag WHEN the workflow runs THEN the report is an artefact of that run (read once at the end, not polled)
- [ ] Implement
- [ ] Test
