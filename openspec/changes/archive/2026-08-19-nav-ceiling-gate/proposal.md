---
kind: code
depends_on: [ia-six-clusters]
---

# Proposal: nav-ceiling-gate

## Summary

Add a mechanical CI gate that keeps decidesk's top-level navigation at the
ADR-004 six-item ceiling. The gate rebuilds the effective menu the same way
`src/main.js`'s `buildManifest` pipeline does — base `src/manifest.json` +
every `src/manifest.d/*.json` fragment + `src/menu-layout.json` — and fails
the build if the resulting primary-nav entry count exceeds the ceiling, or if
any fragment declares a top-level menu entry that `menu-layout.json` never
places (via a relocation, a removal, or a lift into the settings section).
The gate carries its own positive control: a fixture proving it can fail
before it is trusted to say "pass".

## Motivation

ADR-004 fixed a 6-item top-level navigation in May 2026 and change
`ia-six-item-nav` shipped it. Nothing enforced the ceiling mechanically. Over
the following months, 22 independent `src/manifest.d/*.json` fragments each
added their own top-level menu entry — every one individually reasonable,
none of them coordinated — and the rendered navigation grew back to 44
entries with no single commit that "broke" the ADR. `src/menu-layout.json`
already exists as the declared mechanism for re-homing fragment entries
(relocations / removals / settingsSection, consumed by
`@conduction/nextcloud-vue`'s `buildManifest`/`applyMenuLayout`), and a
parallel change, `ia-six-clusters`, uses it to re-collapse the nav back to
six. But `menu-layout.json` is just data — nothing stops fragment #23 from
reintroducing the same defect the day after `ia-six-clusters` merges. This
change adds the enforcement `ia-six-item-nav` never got: every new fragment
menu entry must be explicitly placed, and the merged nav must never exceed
the ceiling again.

## Affected Projects

- [ ] Project: `decidesk` — new gate script (`scripts/check-nav-ceiling.js`),
  new npm script (`check:nav-ceiling`), CI wiring in
  `.github/workflows/code-quality.yml`'s `frontend-checks` list, and a
  positive-control unit test proving the gate can fail.

No other apps change — the gate reads only decidesk's own manifest files.

## Scope

### In Scope

1. A self-contained Node script (`scripts/check-nav-ceiling.js`, no
   `node_modules` dependency at runtime — mirrors the merge/relocation logic
   in `@conduction/nextcloud-vue`'s `src/utils/buildManifest.js` rather than
   importing it, following the precedent set by
   `scripts/check-integration-parity.js`) that:
   - Loads `src/manifest.json`, every `src/manifest.d/*.json` fragment
     (sorted, mirroring `require.context('./manifest.d/', false, /\.json$/)`),
     and `src/menu-layout.json`.
   - Builds the effective top-level menu using the same merge → relocate →
     remove → lift-to-settings pipeline as `buildManifest`/`applyMenuLayout`.
   - Fails if the number of primary top-level entries (menu entries with no
     `section`, i.e. neither footer nor settings) exceeds the ADR-004
     ceiling of 6.
   - Fails if any fragment declares a top-level menu entry that is not
     placed — i.e. not self-scoped to `section: "footer"`/`"settings"`, not
     a key in `menu-layout.json#relocations`, not listed in
     `menu-layout.json#removals`, and not listed in
     `menu-layout.json#settingsSection`.
2. A new `npm run check:nav-ceiling` script and its addition to the
   `frontend-checks` array already wired into
   `.github/workflows/code-quality.yml` (the same mechanism that runs
   `check:manifest`, `test:l10n`, `format` — each a self-contained Node
   script in its own CI leg).
3. A positive-control unit test (`tests/vitest/navCeilingGate.spec.js`)
   exercising the gate's pure functions against small in-memory fixtures:
   one proving an unplaced fragment entry makes the gate fail (and names the
   offending id), one proving a properly-relocated/removed/lifted entry
   passes, and one proving a primary count over the ceiling fails even when
   every entry is individually "placed".

### Out of Scope

- Populating `menu-layout.json` itself (relocations/removals/settingsSection
  entries) — that is `ia-six-clusters`'s scope, which this change depends on.
- Changing `src/manifest.json`, any `src/manifest.d/*.json` fragment, or the
  `buildManifest`/`applyMenuLayout` implementation in
  `@conduction/nextcloud-vue`.
- Registering this check as a numbered gate in the fleet-wide
  `conduction/hydra-gates` package (a separate repo) — this change wires the
  check into decidesk's own `frontend-checks` CI tier only, which does not
  require a cross-repo change.
- Enforcing a numeric cap on the footer allowance (`section: "footer"`
  entries) — ADR-004 caps the primary nav at 6 and does not specify a footer
  limit; the gate reports the footer count but does not fail on it.

## Approach

The gate is a pure-function core (`buildEffectiveMenu`, `evaluateCeiling`,
`evaluateFragmentPlacement`) wrapped by a thin CLI (`main()`) that reads the
three real files from `process.cwd()` (or an override root passed as
`argv[2]`, matching `check-integration-parity.js`'s convention) and reports
`✗`-prefixed failure lines plus a final exit code. The merge/relocate/remove/
settings-lift logic is a deliberately small, vendored mirror of
`@conduction/nextcloud-vue/src/utils/buildManifest.js`'s `mergeMenuItems`,
`applyMenuRelocations`, `applyMenuRemovals`, and `applySettingsSection` —
scoped to menu entries only (no page merging, no page-template expansion,
neither of which affects the nav count). Vendoring instead of importing from
`node_modules` follows the precedent already documented in
`scripts/check-integration-parity.js`'s header comment: a hydra-gates CI
context is not guaranteed to have run `npm ci`, so a check that depends on
`node_modules` existing can silently report a pass having checked nothing.
The `frontend-checks` CI leg this gate is wired into DOES run its own
`npm ci`, so the dependency-free design is a safety margin, not a strict
requirement of that particular wiring — but it means the script also runs
correctly from a bare checkout, a developer's pre-commit hook, or a future
hydra-gates adoption without changes.

## New Dependencies

None. Pure Node (`fs`, `path`) — same footprint as
`check-integration-parity.js` and `tests/l10n/check-l10n.js`.

## Impact

- New file: `scripts/check-nav-ceiling.js`.
- New file: `tests/vitest/navCeilingGate.spec.js`.
- Modified: `package.json` (`scripts.check:nav-ceiling`).
- Modified: `.github/workflows/code-quality.yml` (`frontend-checks` array
  gains `"check:nav-ceiling"`).
- Read-only with respect to `src/manifest.json`, `src/manifest.d/*.json`,
  and `src/menu-layout.json` — the gate never writes these files.

## Cross-Project Dependencies

Depends on `ia-six-clusters` (frontmatter `depends_on`): that change is what
actually re-populates `menu-layout.json`'s relocations/removals/
settingsSection to bring the merged nav back under the ceiling. This gate
will legitimately fail CI on any commit merged before `ia-six-clusters`
lands, which is intentional — it is the enforcement mechanism, not a bug —
and is why the dependency is declared rather than left implicit.

## Risks

### Risk 1: Ceiling gate false-fails on a legitimate structural change

**Severity:** Medium — **Mitigation:** The gate's placement rule accepts
three explicit escape hatches (relocation, removal, settingsSection) plus
self-declared `section: "footer"`/`"settings"` on the fragment entry itself
(the pattern `src/manifest.d/user-settings.json` already uses). A spec that
legitimately needs a 7th top-level item still requires the ADR-004 amendment
process described in the ADR ("Adding a 7th top-level requires an ADR
amendment...") — the gate enforcing that is the intended behaviour, not a
false failure. If ADR-004 is amended to raise the ceiling, the ceiling
constant in `scripts/check-nav-ceiling.js` is a one-line change.

### Risk 2: Vendored merge logic drifts from `buildManifest.js`

**Severity:** Low — **Mitigation:** The vendored functions are a strict
subset (menu-only, no page/template handling) of a small, stable module
(`nextcloud-vue/src/utils/buildManifest.js`, ~245 lines, last touched for
the manifest-v2 migration). The gate script's header comment cites the exact
source file and function names so a future change to the canonical
implementation is easy to cross-check. This mirrors the existing precedent
in `tests/validate-manifest.js`, which vendors the app-manifest schema for
the same reason (CI must not depend on a fresh `node_modules`).

## Rollback Strategy

Remove `"check:nav-ceiling"` from the `frontend-checks` array in
`.github/workflows/code-quality.yml` to stop CI enforcement immediately
(no other change is coupled to it). Deleting `scripts/check-nav-ceiling.js`,
its npm script, and its test is a clean revert with no follow-on cleanup —
the gate never mutates any file, so there is no state to unwind.

## Open Questions

None — the placement rule and ceiling value are both directly derived from
ADR-004's text and the codebase's own existing `section: "footer"`/
`"settings"` conventions. See `design.md`'s "Declarative-vs-imperative
decision" section for the one judgment call (whether the footer allowance
carries its own numeric cap) recorded as a deferred question for the human
reviewer.
