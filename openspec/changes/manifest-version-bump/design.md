# Design: Manifest version bump

## Status
proposed

## Spec kind & sizing (ADR-032)

- `kind: config` — touches only `src/manifest.json`. Zero `lib/`,
  zero `src/components/`, zero `src/views/`, zero `tests/` edits.
- Smallest possible diff: 1 character changed (`3` → `4` in version string).
- Scope: 1 file, 1 task, ~2 builder turns expected.

## Declarative-vs-imperative decision (ADR-031)

Not applicable to this change — manifest versioning is pure declarative
content (no behaviour change, no service-vs-schema choice).

## Impact on existing code

- `src/manifest.json`: `"version": "0.3.0"` → `"version": "0.4.0"`.
- **Nothing else.** Frontend bundle still rebuilds the same manifest;
  `useAppManifest()` reads the same shape; `CnAppRoot` renders identically.
- No backend impact (the manifest is frontend-only).
- No test impact (manifest validation runs via `npm run check:manifest`,
  which validates schema-shape, not version semantics).

## Reuse Analysis (ADR-001)

Not applicable — no new behaviour, no abstraction reuse to evaluate.

## Deduplication Check (ADR-001)

Not applicable — this is a single-field metadata bump, no duplication
risk.

## Risks

1. **Caching**: a bumped manifest version invalidates any client-side
   manifest cache. Acceptable — Tier 4 already loads the bundled manifest
   on every page load.
2. **Reviewer false-positive on "no functional change"**: the reviewer
   might flag this as low-value and request more context. The proposal
   explicitly names the test-of-pipeline rationale.

## Out of scope

- Any other manifest field change.
- Any non-manifest file edit.
- Documentation updates beyond this spec set.
