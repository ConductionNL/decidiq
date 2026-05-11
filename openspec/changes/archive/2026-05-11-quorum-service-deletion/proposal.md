---
kind: code
depends_on:
  - quorum-guard-rewrite
chain:
  - quorum-schema-declaration   # head (closed before this builds)
  - quorum-guard-rewrite         # predecessor (closed before this builds)
  - quorum-service-deletion      # this spec (last)
---

# Quorum — Service deletion (chain spec 3 of 3)

## Problem

After chain spec 2 (`quorum-guard-rewrite`) lands,
`lib/Service/QuorumService.php` exists but has no callers. Dead code.

This spec deletes the service file, its DI registration, and its
covering test. Closes the chain.

## Proposed Solution

1. Delete `lib/Service/QuorumService.php`.
2. Delete `tests/Unit/Service/QuorumServiceTest.php` (if it exists).
3. In `lib/AppInfo/Application.php`, remove the QuorumService
   registration (constructor injection / Container::register / etc.).
4. Verify no remaining references via `grep -rn QuorumService lib/ src/ tests/`.

## Why this is `kind: code` (small)

- One file deletion, one test deletion, one DI line removal.
- ~10 LOC removed, no LOC added.
- No design judgment — purely "remove the code that has no callers".

Per ADR-032 small-code-spec rules: default Hydra budget plenty;
expected ~30-60 turns.

## Capabilities

### Modified Capabilities

- `meeting-management` — QuorumService removed from the codebase. No
  external API change (the service was internal; its sole consumer
  was migrated to declarative reads in chain spec 2).

## Stakeholders

- **Decidesk maintainers** — own the deletion.
- **Hydra reviewers** — validates the chain-tail "deletion" pattern.

## References

- ADR-031 (hydra) — closes a service migration started in chain spec 1
- ADR-032 (hydra) — chain pattern, last spec
- Predecessors in chain: `quorum-schema-declaration` (closed),
  `quorum-guard-rewrite` (closed before this builds)
- Original superseded spec: `quorum-declarative-migration` (closed
  as superseded; chain replaces it)
