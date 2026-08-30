---
kind: config
depends_on: []
---

# Manifest version bump (0.3.0 → 0.4.0)

## Problem

Decidesk's `src/manifest.json` is at `version: "0.3.0"`. Per ADR-024 §7,
`manifest.version` tracks semver of content; an app bumps the minor while
iterating. The manifest has accumulated meaningful content evolution since
0.3.0 landed (full Tier 4 adoption, declarative business-logic specs in
flight, ADR-032 chain pattern adopted). A minor-version bump records that
maturation as a content-versioning waypoint.

This change is also the **smallest possible Hydra config-only spec** —
deliberately scoped to validate the Stage A pipeline end-to-end (build →
quality → review → security → applier) on a one-line diff with zero
semantic risk. If this fails, the pipeline has bugs unrelated to spec
content; if it passes, the bigger ADR-031 chain specs can run with
confidence.

## Proposed Solution

Edit `src/manifest.json`: change `"version": "0.3.0"` → `"version": "0.4.0"`.

That is the entire change. No code, no schemas, no tests, no documentation
updates beyond this proposal/design/tasks set.

## Capabilities

### Modified Capabilities

- `app-manifest` — manifest.version increments per ADR-024 §7.

### New Capabilities

(none)

## Stakeholders

- **Decidesk maintainers** — own the bump.
- **Hydra reviewers** — validates the config-only flow end-to-end on the
  smallest possible diff.

## References

- ADR-024 (hydra) — App Manifest, §7 versioning rules
- ADR-032 (hydra) — Spec sizing taxonomy; this spec is the canonical
  `kind: config` minimal example
- `decidesk/src/manifest.json` — target file
