# Decidesk Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that decidesk's quality gates have
some legacy debt absorbed via exclude patterns and a small PHPMD
baseline. Burning these down keeps PR diffs honest — gates catch
real regressions rather than silently absorbing already-broken code.

Decidesk has 3 phpcs.xml exclude-patterns and a 51-line
phpmd.baseline.xml. PHPStan has no baseline yet. The work is small:
clear PHPCS excludes, burn down the modest PHPMD baseline, and run
PHPStan unified.

This is a tracking change so the burn-down can be picked up later.
It is spec-only; no code changes are proposed in this change.

## What Changes

- Inventory and clear the 3 phpcs.xml exclude-patterns. For each:
  add proper docblocks + named-parameter call audits, then drop
  the exclude.
- Burn down the 51-line phpmd.baseline.xml. Small enough to clear
  in 1 PR. Categories per the baseline file (likely a mix of
  ElseExpression / Cyclomatic / MissingImport).
- Run PHPStan for the first time as a unified gate. Capture
  surfacing errors as a baseline OR fix outright depending on
  volume.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate.

## Problem

Exclude-patterns and the PHPMD baseline exist because the audit
captured legacy files / violations that predated the current
quality conventions. Decidesk is a small app — the entire burn-
down should fit in 1-2 PRs.

PHPStan baseline doesn't exist yet because the gate hasn't been
run as part of unified `check:strict`. Capturing it (or fixing
outright) is a Phase 1 activity.

Note: per recent Hydra reviews (decidesk#44, #45, #47, #60),
decidesk has been the test bed for new auth-related Hydra gates.
This change is purely about absorbing the existing quality
baseline — the auth/security work is owned by separate Hydra-
gate-driven PRs.

## Proposed Solution

File-by-file cleanup. Phase 2 lists each excluded file; Phase 3
walks the PHPMD baseline categories.

Estimated effort: 1-2 PRs over 1 sprint.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Auth / authorization fixes (owned by Hydra gate PRs)
- Test additions (separate test-coverage spec change if needed)

## See also

- The canonical audit lives in openregister at
  `.claude/audit-2026-05-03/03-repo-hygiene.md`. Decidesk references
  it from there.
- `phpcs.xml` (the legacy-debt baseline section)
- `phpmd.baseline.xml` (the PHPMD baseline file)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)
