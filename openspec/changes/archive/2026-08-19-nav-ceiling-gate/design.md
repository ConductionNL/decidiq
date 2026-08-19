# Design: nav-ceiling-gate

## Architecture Overview

`src/main.js` builds the app's effective manifest at boot time:

```
bundledManifest (src/manifest.json)
  + fragments (src/manifest.d/*.json, require.context sorted)
  + menuLayout (src/menu-layout.json)
  = buildManifest(...)   // @conduction/nextcloud-vue
  → { ...base, pages, menu }
```

`buildManifest` merges menu entries by id (`mergeMenuItems`), then applies
`applyMenuLayout`: relocations (fold a fragment's top-level entry into an
existing group), removals (drop a duplicate leaf whose page stays routable),
then a settings-section lift (promote operator-only entries into the
`NcAppNavigationSettings` gear foldout, tagging them `section: "settings"`).
Base entries already carry a third escape hatch informally: `section:
"footer"` (see `Documentation`/`FeaturesRoadmapMenu` in `src/manifest.json`,
and `UserSettingsMenu` in `src/manifest.d/user-settings.json`, which
self-declares `section: "settings"` rather than going through
`menu-layout.json#settingsSection`).

This change adds a fourth stage that runs at CI time rather than boot time:
a Node script that performs the identical merge + layout computation offline
against the same three source files, then asserts two invariants ADR-004
requires but nothing previously checked mechanically:

1. The merged menu's primary (non-footer, non-settings) top-level entry
   count is at most 6.
2. Every fragment's top-level menu entries are individually accounted for in
   `menu-layout.json` (or self-scoped out of the primary count) — so a new
   fragment can never silently reintroduce an unplaced entry even while the
   total still happens to be at or under 6 that day.

```
scripts/check-nav-ceiling.js
  reads: src/manifest.json, src/manifest.d/*.json, src/menu-layout.json
  computes: buildEffectiveMenu() [vendored mini-buildManifest, menu-only]
  asserts: evaluateCeiling() + evaluateFragmentPlacement()
  reports: ✗-prefixed failures + counts, exit 0 or 1

package.json  "check:nav-ceiling": "node scripts/check-nav-ceiling.js"
  ↓
.github/workflows/code-quality.yml  frontend-checks: [..., "check:nav-ceiling"]
  ↓ (shared quality.yml workflow — self-contained node script, own checkout + npm ci)
CI "Frontend Check" job
```

## Nextcloud Integration

No OCP interfaces, controllers, services, or DI are involved — this is a
build-time/CI-time static check over JSON files already checked into the
repo. Nothing runs inside a Nextcloud request.

## Security Considerations

No security impact. The gate is read-only (never writes `manifest.json`,
`manifest.d/*.json`, or `menu-layout.json`) and has no runtime exposure — it
runs in CI and in a developer's local `npm run check:nav-ceiling`, never
inside the deployed app.

## File Structure

```
scripts/
  check-nav-ceiling.js        # new — gate script + vendored menu-merge core
package.json                  # modified — new "check:nav-ceiling" script
.github/workflows/
  code-quality.yml             # modified — frontend-checks gains "check:nav-ceiling"
tests/vitest/
  navCeilingGate.spec.js       # new — unit tests incl. positive control
```

## Decisions

### Decision 1: Vendor the menu-merge logic instead of importing `buildManifest`

**Choice:** `scripts/check-nav-ceiling.js` reimplements a menu-only subset of
`@conduction/nextcloud-vue/src/utils/buildManifest.js`
(`mergeMenuItems`, `applyMenuRelocations`, `applyMenuRemovals`,
`applySettingsSection`) rather than `require()`-ing the installed package.

**Alternatives considered:**
- **Import from `node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`.**
  Simplest, and would automatically track upstream changes. Rejected because
  `scripts/check-integration-parity.js`'s header comment documents a
  measured failure mode of exactly this pattern: a hydra-gates CI context is
  not guaranteed to have run `npm ci`, so a resolution that depends on
  `node_modules` existing silently reports a pass having checked nothing
  when the module is missing. This gate is wired into the `frontend-checks`
  tier today, which DOES run `npm ci` in its own checkout — but vendoring
  costs one small, stable, well-documented file and buys the gate the
  ability to run correctly from a bare checkout, a pre-commit hook, or a
  future hydra-gates adoption without a behavioural change.
- **Import from `dist/esm/utils/buildManifest.js`.** Same `node_modules`
  dependency risk as above, plus ties the gate to the build output format
  (ESM `import`/`export`) rather than plain CommonJS, complicating a
  synchronous `require()`-based CLI script matching the rest of `scripts/`.

**Trade-off accepted:** the vendored copy can drift from the canonical
implementation if `buildManifest.js`'s menu semantics change upstream. The
vendored functions are a small (~90 line), stable, well-tested subset (the
canonical file is ~245 lines total and covers page/template merging this
gate does not need); the header comment in `check-nav-ceiling.js` cites the
exact source file and function names for cross-checking.

### Decision 2: Placement rule accepts four escape hatches, not three

**Choice:** REQ-NAV-008 (specs/app-navigation) treats a fragment top-level
entry as "placed" if it is covered by a `menu-layout.json` relocation,
removal, or `settingsSection` entry, **or** if the fragment entry itself
declares `section: "footer"`/`"settings"`.

**Alternatives considered:**
- **Require every entry go through `menu-layout.json`, full stop.** This is
  the literal reading of the task's framing ("relocation, settingsSection,
  or removal"). Rejected because it does not match the codebase's own
  existing pattern: `src/manifest.d/user-settings.json` already
  self-declares `section: "settings"` on its one menu entry, and the base
  `src/manifest.json` self-declares `section: "footer"` on `Documentation`
  and `FeaturesRoadmapMenu`. A gate that only recognised the three
  `menu-layout.json` mechanisms would false-fail on the very entry the
  codebase already uses as the canonical "opt out of the main nav myself"
  pattern — a false positive on day one.

**Trade-off accepted:** none identified — self-declared `section` and
`menu-layout.json`-driven placement both produce the same observable
outcome (the entry does not count toward the primary ceiling), so treating
them as equivalent for placement purposes is not a weaker check, just a
more complete one.

### Decision 3: No numeric cap on the footer allowance

**Choice:** the gate counts and reports `section: "footer"` entries but does
not fail when that count grows.

**Alternatives considered:**
- **Cap the footer allowance too** (e.g. at 2, matching today's
  `Documentation` + `FeaturesRoadmapMenu`). Not adopted because ADR-004's
  text specifies only the 6-item primary ceiling and never numbers a footer
  limit — inventing one would be enforcing a rule the ADR does not state.

**This is the one genuinely open judgment call in this design** — recorded
as a deferred question below for human confirmation, since "should the
footer allowance also be capped" is a product/IA decision ADR-004 doesn't
resolve either way.

## Risks / Trade-offs

- [Vendored merge logic drifts from `buildManifest.js`] → Small, stable,
  well-documented source function set; header comment cites the exact
  upstream file/functions for cross-checking (see Decision 1).
- [Gate legitimately fails on every commit until `ia-six-clusters` merges] →
  Intentional; captured via the `depends_on: [ia-six-clusters]` proposal
  frontmatter, which blocks this change's own build until that dependency's
  issue closes (per hydra's dependency enforcement), so by the time this
  gate is wired into a live `development` branch, `menu-layout.json` already
  carries the collapsing relocations.
- [A future legitimate 7th top-level item needs the ADR-004 amendment
  process to land before it can pass CI] → By design; the gate enforcing
  the ADR's own stated amendment requirement is the point, not a defect.
  Raising the ceiling constant is a one-line change once the amendment is
  accepted.

## Migration Plan

No data migration. Deployment is: merge the gate script + npm script + CI
wiring + tests. Rollback is deleting the same four artifacts (script, test,
`package.json` entry, `frontend-checks` array entry) — the gate never
mutates repo state, so there is nothing to unwind beyond the files
themselves.

## Open Questions

- **Should the footer allowance (`section: "footer"` entries) also carry a
  numeric cap, and if so, what number?** ADR-004 does not specify one. This
  design leaves it uncapped (Decision 3) as the reading most faithful to the
  ADR's actual text, but a human reviewer with IA context may want to set an
  explicit footer cap (e.g. 2, matching today's count) in a follow-up ADR
  amendment rather than leaving it open-ended indefinitely.
