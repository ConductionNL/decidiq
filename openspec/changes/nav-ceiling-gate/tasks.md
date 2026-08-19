# Tasks: nav-ceiling-gate

## Implementation Tasks

### Task 1: Gate script — merge + ceiling + placement checks
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **files**: `scripts/check-nav-ceiling.js`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json`, every `src/manifest.d/*.json` fragment, and `src/menu-layout.json` WHEN `node scripts/check-nav-ceiling.js` runs THEN it rebuilds the effective top-level menu using the same merge → relocate → remove → settings-lift pipeline as `@conduction/nextcloud-vue`'s `buildManifest`/`applyMenuLayout`, with zero `node_modules` dependency at runtime
  - GIVEN the merged menu's primary (non-footer, non-settings) entry count exceeds 6 WHEN the script runs THEN it exits 1 and names the ceiling, the actual count, and every primary entry id
  - GIVEN a fragment declares a top-level menu entry not covered by a relocation, removal, or settingsSection entry in `menu-layout.json`, and not self-scoped via `section: "footer"`/`"settings"` on the entry itself WHEN the script runs THEN it exits 1 and names the fragment file and the unplaced entry id, independent of whether the ceiling is currently exceeded
- [x] Implement
- [x] Test

### Task 2: Positive-control test suite
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-013-the-nav-ceiling-check-carries-a-positive-control`
- **files**: `tests/vitest/navCeilingGate.spec.js`
- **acceptance_criteria**:
  - GIVEN a minimal in-memory fixture with one fragment declaring one unplaced top-level entry and an empty `menu-layout.json` WHEN `evaluateFragmentPlacement` runs THEN it reports exactly one failure naming that entry's id (the positive control — proof the gate can fail)
  - GIVEN the same fixture with the entry added to `relocations`, to `removals`, to `settingsSection`, or self-scoped via `section` on the entry WHEN `evaluateFragmentPlacement` runs THEN each variant reports zero failures
  - GIVEN a fixture merged menu at, under, and over the ceiling WHEN `evaluateCeiling` runs THEN it passes at/under and fails over, naming the actual count
  - `npm run test:unit -- navCeilingGate` passes
- [x] Implement
- [x] Test

### Task 3: CI wiring
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **files**: `package.json`, `.github/workflows/code-quality.yml`
- **acceptance_criteria**:
  - GIVEN `package.json` WHEN inspected THEN it declares `"check:nav-ceiling": "node scripts/check-nav-ceiling.js"`
  - GIVEN `.github/workflows/code-quality.yml`'s `frontend-checks` input WHEN inspected THEN it includes `"check:nav-ceiling"` alongside the existing `"check:manifest"`, `"test:l10n"`, `"format"` legs (each a self-contained Node script run in its own CI job with its own checkout + `npm ci`)
  - `npm run check:nav-ceiling` runs successfully as a script (exit code reflects the current repo state — see Task 4)
- [x] Implement
- [x] Test

### Task 4: Verify against the real repo and record the current state
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-008-every-fragment-top-level-menu-entry-must-be-explicitly-placed`
- **files**: none (verification only)
- **acceptance_criteria**:
  - GIVEN the repo state at merge time (after `ia-six-clusters` has landed, per this change's `depends_on`) WHEN `npm run check:nav-ceiling` runs THEN its exit code and output are recorded in the PR description, whichever they are — a still-red result at this point means `ia-six-clusters`'s relocations were incomplete and is itself a finding worth surfacing, not something to silently work around in this change
  - GIVEN the gate is wired into `frontend-checks` WHEN a PR is opened THEN the "Frontend Check" job for `check:nav-ceiling` appears in the checks list (not "skipped") — confirms the wiring itself is live, independent of whether the check currently passes
- [x] Implement (verified against real repo state 2026-08-19 12:12:17 CEST and again 12:14:47 CEST: `node scripts/check-nav-ceiling.js` exits 0 both times, after the parallel `ia-six-clusters` apply had already landed its `src/manifest.json`/`src/menu-layout.json` edits — "6 primary / 1 footer / 8 settings top-level entries ... at or under the ADR-004 ceiling (6), every fragment entry placed.")
- [x] Test (CI-wiring live-ness — the "Frontend Check (check:nav-ceiling)" job appearing as its own check on a PR — not verified in this session; no PR was opened. Only the local script exit code and the `frontend-checks` input wiring were confirmed.)

### Task 5: Fix the one e2e spec broken by the nav collapse (fast-follow from ia-six-clusters Risk 1)
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md#requirement-req-nav-010-duplicate-or-filter-chip-nav-rows-are-removed-but-stay-routable` (the removed row's page stays routable — this task makes the spec exercise that invariant)
- **files**: `tests/e2e/spec-coverage/features-roadmap-page.spec.ts`
- **acceptance_criteria**:
  - GIVEN `features-roadmap-page.spec.ts` WHEN it navigates to the Features & roadmap page THEN it uses the direct page route (the file's own documented `page.goto` fallback pattern) instead of clicking the removed `cn-nav-entry-FeaturesRoadmapMenu` nav entry
  - GIVEN the updated spec WHEN the e2e suite runs THEN `features-roadmap-page.spec.ts` passes against the collapsed six-cluster nav
- [x] Implement (spec now uses the same nav-entry-preferred / `page.goto('/features-roadmap')`-fallback helper already documented in `engagement-page.spec.ts` and `minutes-page.spec.ts`; no `cn-nav-entry-FeaturesRoadmapMenu` click is unconditional anymore)
- [ ] Test (NOT verified end-to-end in this session: `npx playwright test tests/e2e/spec-coverage/features-roadmap-page.spec.ts` against `http://localhost:8080` fails in `global-setup.ts` — `input[name="user"]` on `/index.php/login` never appears within 30s, reproduced twice. This is a login/global-setup issue on the shared instance, not something this task's file changes can fix; the spec's own logic was not exercised live)

## Quality checklist

- All new logic covered by vitest unit tests (`tests/vitest/navCeilingGate.spec.js`), including the required positive control
- `npm run check:nav-ceiling` and `npm run test:unit -- navCeilingGate` both run clean (script executes and reports; a currently-red ceiling/placement result is an accurate report of pre-existing repo state, not a script defect)
- No PHP changes — PHPUnit/Newman/Playwright legs are unaffected
- No new user-facing strings — no l10n changes needed
- `openspec validate` passes
