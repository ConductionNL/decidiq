---
kind: config
---

# Proposal: info-licence-eupl

## Summary
Correct the App-Store licence metadata in `appinfo/info.xml` from `<licence>agpl</licence>` to
`<licence>EUPL-1.2</licence>` so the declared licence matches the licence the app is actually
shipped under. Every other licence signal in the repository already says EUPL-1.2 — the `info.xml`
`<licence>` element is the **only** place that still says AGPL, which misrepresents the licence to
users and the App Store. Pure metadata; no code, no behaviour change.

## Motivation
The `info.xml` `<licence>agpl</licence>` value contradicts the rest of the repository, which is
uniformly EUPL-1.2 (verified at HEAD):

- `LICENSE` — "EUROPEAN UNION PUBLIC LICENCE v. 1.2".
- `REUSE.toml` — `SPDX-License-Identifier = "EUPL-1.2"`.
- `publiccode.yml` — `license: EUPL-1.2`.
- Every `lib/**/*.php` docblock — `@license EUPL-1.2` + `SPDX-License-Identifier: EUPL-1.2`.
- Both `info.xml` `<description>` blocks (en + nl) — "Free and open source under the EUPL-1.2
  license" / "Vrij en open source onder de EUPL-1.2-licentie".

So the app **describes itself** as EUPL-1.2 two paragraphs above the machine-readable field that
declares AGPL. This is a product-readiness honesty defect: the App-Store licence badge and any
downstream licence tooling would report the wrong licence.

Nextcloud's `app-info.xsd` accepts the SPDX value `EUPL-1.2` for NC ≥ 31 (verified in the fleet
rollout), and the sibling app **pipelinq already ships `<licence>EUPL-1.2</licence>` at the same
`min-version="28"`** — so this is a proven, low-risk metadata correction, not a new pattern.
decidesk's `min-version` (28) and `max-version` (34) are unchanged.

## Affected Projects
- [x] Project: `decidesk` — one-line `appinfo/info.xml` metadata correction; no other file changes.

## Scope

### In Scope
- Change `appinfo/info.xml` `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`.
- Bump the `info.xml` `<version>` per the immutable-cache-bust rule so the corrected metadata is
  picked up.

### Out of Scope
- Any change to the actual licence, `LICENSE`, `REUSE.toml`, `publiccode.yml`, or SPDX headers —
  those are already correct; only the `info.xml` field is wrong.
- Any change to `min-version` / `max-version` / dependencies.

## Approach
Edit the single `<licence>` element to the SPDX value `EUPL-1.2` and bump the app version. Mirror
pipelinq's already-published `info.xml`.

## New Dependencies
None.

## Impact
- **App Store / packaging**: the licence badge and licence tooling report EUPL-1.2, matching reality.
- **No** code, schema, or runtime impact.

## Cross-Project Dependencies
None. (Follows the same fleet convention pipelinq already ships.)

## Risks

### Risk 1: App-store schema rejects the value on an older target
**Severity:** Low — **Mitigation:** pipelinq already ships `EUPL-1.2` at `min-version="28"`; the
value is accepted. No `min-version` change is made.

## Rollback Strategy
Revert the one-line `info.xml` edit (and the version bump). Metadata-only; nothing to migrate.

## Open Questions
None.
