# Tasks: Decidesk Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs` and capture current baseline error count
      (target: starting from 3 exclude-patterns in phpcs.xml)
- [ ] Run `composer phpmd` and capture current violation count
      (target: starting from 51-line phpmd.baseline.xml)
- [ ] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories
- [ ] Decide PHPStan strategy: fix-outright or capture baseline
- [ ] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>`
entry, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude
- [ ] Excluded file 2 — fix sniffs + drop exclude
- [ ] Excluded file 3 — fix sniffs + drop exclude
- [ ] Once all excludes are gone, drop the legacy-debt block from
      phpcs.xml entirely

## Phase 3 — PHPMD burn-down (51 lines)

Single-PR cluster — small baseline, no need to phase further.

- [ ] ElseExpression — re-shape `if/else` chains to early-return
- [ ] CyclomaticComplexity / NPathComplexity — extract methods
- [ ] MissingImport — add `use` statements; remove inline FQCNs
- [ ] ExcessiveMethodLength — extract helpers (if present)
- [ ] StaticAccess — replace static calls with DI services (if present)
- [ ] Variable-naming sniffs (Long/Short/Undefined/UnusedFormalParameter)
- [ ] Regenerate baseline; confirm 0 lines
- [ ] Delete phpmd.baseline.xml and drop `--baseline-file` from
      composer.json's phpmd script

## Phase 4 — PHPStan burn-down

Contingent on Phase 1's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] Inventory phpstan errors by file/type
- [ ] Common patterns to fix:
  - [ ] Missing return-type / param-type declarations
  - [ ] Mixed types (specify generic / union)
  - [ ] Possibly-null dereferences
- [ ] Once baseline reaches 0 lines (or never created): confirm
      gate runs clean against current code

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR
- [ ] Once all baselines are empty:
  - [ ] Delete `phpmd.baseline.xml`
  - [ ] Delete `phpstan-baseline.neon` (if it was created)
  - [ ] Drop the legacy-debt section from `phpcs.xml`
- [ ] Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`

## Phase 6 — Documentation

- [ ] Update README quality-gates section
- [ ] Note in `app-config.json` that legacy quality cleanup is done
- [ ] Close the burn-down tracking issue once the last baseline
      line is removed
