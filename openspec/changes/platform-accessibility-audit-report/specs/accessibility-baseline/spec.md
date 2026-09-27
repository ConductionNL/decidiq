# accessibility-baseline Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-accessibility-audit-report](../../) (this delta)

## Purpose

Adds the evidence to the existing baseline: a structured sample, an automated
axe-core scan, a manual checklist and a generated WCAG 2.1 AA audit report per
release. Closes matrix row plt-22.

**Standards**: WCAG 2.1 level AA, WCAG-EM 1.0 (Website Accessibility
Conformance Evaluation Methodology), EN 301 549, Besluit digitale
toegankelijkheid overheid.

## ADDED Requirements

### Requirement: REQ-AAR-001 The evaluated pages are a structured sample

decidiq SHALL keep a sample of pages to evaluate that holds one page of every
page type its manifests declare, the member processes of opening a meeting,
casting a vote and reading the minutes, the live meeting page, the projection
screen and the personal settings, and, when portaliq is installed, the portal
pages built from decidiq's contribution. A page type added to a manifest
without a sample entry SHALL fail the sample check.

#### Scenario: A new page type cannot be skipped

- GIVEN a manifest fragment that adds the first page of type `settings` in a new area
- WHEN the sample check runs
- THEN it fails and names the page that has no sample entry
- @e2e exclude build check; covered by a vitest on the sample script

### Requirement: REQ-AAR-002 axe-core scans every sampled page and attributes each finding

An automated scan SHALL run axe-core with the WCAG 2.1 A and AA rules on every
sampled page and write `tests/axe/report.json`. Each violation SHALL carry an
owner: `decidiq` inside decidiq's app root, `nextcloud` in Nextcloud's own
chrome, `portaliq` on a portal page. A serious or critical violation owned by
decidiq SHALL fail the scan.

#### Scenario: A decidiq violation fails the scan

- GIVEN a button without an accessible name on the meeting page
- WHEN the scan runs
- THEN the report holds a serious violation owned by `decidiq` on that page and the scan fails

#### Scenario: A violation in Nextcloud's header does not fail decidiq

- GIVEN a violation inside Nextcloud's own header on the dashboard page
- WHEN the scan runs
- THEN the report records it with owner `nextcloud` and the scan does not fail on it
- @e2e exclude attribution logic; covered by a vitest on the owner resolver with fixture nodes

### Requirement: REQ-AAR-003 Criteria axe cannot decide are checked by hand and never assumed

decidiq SHALL keep a checklist with one entry per WCAG 2.1 A and AA success
criterion that axe does not decide, each with the check in plain words and per
release a result of `pass`, `fail`, `not-applicable` or `not-tested`, the
tester and the date. An entry without a result SHALL count as `not-tested`.

#### Scenario: An unfilled entry is not a pass

- GIVEN the checklist entry for 1.4.10 (reflow) with no result for this release
- WHEN the report is generated
- THEN 1.4.10 shows `not-tested`
- @e2e exclude report generation; covered by a vitest on the generator

### Requirement: REQ-AAR-004 Each release has a readable WCAG 2.1 AA audit report

decidiq SHALL generate `docs/compliance/wcag-2.1-aa-audit.md` from the scan and
the checklist, stating the version, the date, the sample, that it is a supplier
self-evaluation following WCAG-EM, and per WCAG 2.1 A and AA success criterion
the outcome with each failure's page, rule and owner. The report SHALL be
uploaded with every tagged build and linked from the documentation site.

#### Scenario: A buyer reads the report for a release

- GIVEN release 1.9.0 was built with a completed checklist
- WHEN a buyer opens the audit report from the documentation site
- THEN they see version 1.9.0, its date, the sample, and every WCAG 2.1 A and AA criterion with pass, fail, not applicable or not tested

#### Scenario: A partial evaluation cannot read as complete

- GIVEN twelve checklist entries without a result
- WHEN the report is generated
- THEN the summary shows twelve criteria not tested, next to the counts that passed and failed
- @e2e exclude report generation; covered by a vitest on the generator
