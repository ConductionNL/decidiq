# Test Plan: nav-ceiling-gate

All test cases are unit tests over the gate's pure functions
(`buildEffectiveMenu`, `evaluateCeiling`, `evaluateFragmentPlacement`) in
`tests/vitest/navCeilingGate.spec.js`, run via `npm run test:unit`. None
require a browser, a live Nextcloud instance, or OpenRegister data — the
gate only reads three JSON files. `type: regression` is the closest fit in
the taxonomy below: this whole change exists to prevent the nav-ceiling
regression from recurring, and each test case guards one way it could.

## Test Cases

### TC-1: Merged menu at or under the ceiling passes
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **type**: regression
- **persona**: n/a (developer-facing CI check)
- **preconditions**: A fixture base manifest with ≤6 primary top-level menu entries, no fragments adding new top-level entries, empty `menu-layout.json`
- **steps**: Call `evaluateCeiling(buildEffectiveMenu(base, [], menuLayout), 6)`
- **expected result**: `failures` is empty; reported primary count matches the fixture
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-2: Merged menu over the ceiling fails, naming the offending count
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **type**: regression
- **persona**: n/a
- **preconditions**: A fixture base manifest with 7 primary top-level entries, all individually "placed" (no unplaced-entry failures)
- **steps**: Call `evaluateCeiling(...)`
- **expected result**: `failures` contains one entry naming the ceiling (6) and the actual count (7)
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-3: Footer and settings entries are excluded from the primary count
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **type**: regression
- **persona**: n/a
- **preconditions**: A fixture merged menu with 6 primary entries + 2 `section: "footer"` entries + 1 `section: "settings"` entry (9 total)
- **steps**: Call `evaluateCeiling(...)`
- **expected result**: `failures` is empty; `primary.length === 6`, `footer.length === 2`
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-4: Positive control — an unplaced fragment entry fails the check
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-009-the-nav-ceiling-check-carries-a-positive-control`
- **type**: regression
- **persona**: n/a
- **preconditions**: A minimal fixture: base with 2 primary entries, one fragment declaring one new top-level entry (e.g. id `NewThing`), empty `menu-layout.json` (all three arrays empty/absent)
- **steps**: Call `evaluateFragmentPlacement([fragment], menuLayout)`
- **expected result**: `failures.length === 1`, and the failure text contains `NewThing`
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-5: Placing the same entry (relocation) clears the failure
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-008-every-fragment-top-level-menu-entry-must-be-explicitly-placed`
- **type**: regression
- **persona**: n/a
- **preconditions**: The TC-4 fixture, with `menu-layout.json.relocations = { NewThing: "SomeExistingGroup" }`
- **steps**: Call `evaluateFragmentPlacement([fragment], menuLayout)`
- **expected result**: `failures` is empty
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-6: Placing the same entry (removal) clears the failure
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-008-every-fragment-top-level-menu-entry-must-be-explicitly-placed`
- **type**: regression
- **persona**: n/a
- **preconditions**: The TC-4 fixture, with `menu-layout.json.removals = ["NewThing"]`
- **steps**: Call `evaluateFragmentPlacement([fragment], menuLayout)`
- **expected result**: `failures` is empty
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-7: Placing the same entry (settingsSection, or self-declared section) clears the failure
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-008-every-fragment-top-level-menu-entry-must-be-explicitly-placed`
- **type**: regression
- **persona**: n/a
- **preconditions**: Two variants of the TC-4 fixture: (a) `menu-layout.json.settingsSection = ["NewThing"]`, (b) the fragment's own entry carries `section: "settings"` with an untouched empty `menu-layout.json`
- **steps**: Call `evaluateFragmentPlacement([fragment], menuLayout)` for each variant
- **expected result**: `failures` is empty for both variants
- **test command**: `npm run test:unit -- navCeilingGate`

### TC-8: The CLI script runs end-to-end against real files and exits with the correct code
- **spec_ref**: `openspec/changes/nav-ceiling-gate/specs/app-navigation/spec.md#requirement-req-nav-007-the-primary-top-level-navigation-is-mechanically-capped-at-the-adr-004-ceiling`
- **type**: regression
- **persona**: n/a
- **preconditions**: `node scripts/check-nav-ceiling.js` runnable from the repo root with no `node_modules` present (simulating a bare checkout)
- **steps**: Run the script against a temp directory containing a minimal `src/manifest.json` + `src/manifest.d/` + `src/menu-layout.json` fixture (via `argv[2]` root override), for one passing and one failing case
- **expected result**: exit code 0 for the passing fixture, exit code 1 for the failing fixture, with `✗`-prefixed lines identifying the failure
- **test command**: `npm run test:unit -- navCeilingGate` (spawns the CLI as a subprocess or calls `main()` directly with a mocked `process.exit`)

## Coverage Summary

- REQ-NAV-007 (ceiling): TC-1, TC-2, TC-3 — covered
- REQ-NAV-008 (fragment placement): TC-4, TC-5, TC-6, TC-7 — covered
- REQ-NAV-009 (positive control): TC-4, TC-5 — covered

## Out of Scope

- Running the gate against the *real*, current `src/manifest.d/*.json` /
  `src/menu-layout.json` state as a committed assertion (pass or fail) — the
  real state legitimately changes shape once `ia-six-clusters` lands (this
  change's `depends_on`), so coupling a committed test to that moving target
  would either be vacuously true after the dependency merges or require
  updating in lockstep with an unrelated change. TC-8 exercises the CLI
  machinery against a synthetic fixture instead of the real repo tree.
