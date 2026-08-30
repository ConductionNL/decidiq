# Design: Decidesk Legacy Quality Cleanup

## Status
proposed

## Background

The OR-abstraction audit (2026-05-03, stream 3) flagged that decidesk's
quality gates absorb legacy debt via exclude patterns and a PHPMD
baseline. Gates should catch real regressions, not silently absorb
already-broken code.

Current state: `phpcs.xml` has 3 `<exclude-pattern>` entries in a
legacy-debt block; `phpmd.baseline.xml` is 51 lines; PHPStan has no
baseline because it has never run as part of unified `check:strict`.

This is a **spec-only tracking change** — burn-down happens in
follow-up PRs that each peel off one item and re-run gates green.

## Scope

**In scope:**

1. PHPCS exclude-pattern burn-down — fix sniffs in 3 excluded files
   (docblocks, named-parameter audits, sniff fixes), drop each
   `<exclude-pattern>`, then delete the legacy-debt block.
2. PHPMD baseline burn-down — clear the 51-line baseline by category
   (`ElseExpression`, `Cyclomatic`/`NPath`, `MissingImport`,
   `ExcessiveMethodLength`, `StaticAccess`, variable-naming). Delete
   the file and drop `--baseline-file` from composer scripts.
3. PHPStan first-run — inventory errors, decide fix-outright vs.
   baseline-capture, then include phpstan in unified `check:strict`.
4. CI wiring — `composer check:strict` on every PR plus a weekly
   smoke-test cron on `development`.
5. Documentation — update README quality-gates section, mark cleanup
   done in `app-config.json`.

**Out of scope:** refactoring beyond what each sniff requires; new
features (adoption specs); auth fixes (Hydra-gate PRs decidesk#44,
#45, #47, #60); test additions (separate test-coverage change).

## Execution order

Phases run sequentially so each PR re-baselines cleanly:

1. **Inventory** — capture counts, decide PHPStan strategy.
2. **PHPCS** — three excluded files, one micro-PR each so regressions
   bisect cleanly.
3. **PHPMD** — clear the baseline in one PR clustered by sniff
   category; too small to split per-file.
4. **PHPStan** — fix-outright if volume permits, else capture and
   burn down a baseline the same way.
5. **CI integration** — wire the unified gate once all three tools
   are clean (or against documented baselines).
6. **Documentation** — single cleanup PR after the gate is green for
   two consecutive weeks on `development`.

## Relationship to other cleanup changes

The quorum-* series (`quorum-schema-declaration`, `quorum-guard-rewrite`,
`quorum-service-deletion`, `quorum-declarative-migration`) moves logic
from `QuorumService` into schema-declarative aggregations per ADR-031.
Those are scoped to a domain capability and do **not** touch quality
configuration.

This change is the inverse — scoped to quality configuration only.
Parallel execution is safe; file sets do not overlap. New sniff
violations from quorum-* refactors are caught by the unified gate
once Phase 5 wires it in. The canonical audit lives in openregister
at `.claude/audit-2026-05-03/03-repo-hygiene.md`.

## Risk assessment

- **Burn-down cascades** — each PR is scoped to one file or one sniff
  category; overflow becomes a follow-up change.
- **PHPStan first-run overwhelming** — Phase 1 budgets a decision
  between fix-outright and baseline-capture before code changes start.
- **Unified gate flips red** — existing per-tool CI jobs continue
  running until Phase 5; the unified gate switches on only when
  tools are green.
- **Parallel quorum-* work** — non-overlapping file sets; no expected
  merge conflicts.

## Success criteria

- `phpcs.xml` has zero `<exclude-pattern>` entries and no legacy-debt
  block.
- `phpmd.baseline.xml` is deleted; `composer phpmd` runs baseline-less.
- PHPStan is part of `composer check:strict` and reports zero errors
  (or against a documented baseline with a burn-down owner).
- `composer check:strict` runs in CI on every PR and as a weekly
  smoke-test cron on `development`.
- README quality-gates section reflects the unified gate;
  `app-config.json` notes legacy cleanup as done.
- No regressions in domain capability changes (quorum-*, adoption
  specs) attributable to this work.
