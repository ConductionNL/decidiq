# Tasks: Manifest version bump

> **`kind: config`** — single 1-character edit in `src/manifest.json`.
> No PHP, no Vue, no tests added. The smallest possible Hydra spec.

## 1. Bump manifest version

- [ ] Edit `src/manifest.json`: change `"version": "0.3.0"` to
      `"version": "0.4.0"`.
- [ ] Verify the diff is exactly one line, exactly one character.

## 2. Verification (mechanical)

- [ ] `jq '.version' src/manifest.json` returns `"0.4.0"`.
- [ ] `npm run check:manifest` exits 0 (schema validates).
- [ ] `git diff --stat origin/development...HEAD` shows
      `src/manifest.json | 2 +-` (one line removed, one line added —
      jq doesn't preserve formatting around the bump, but the net
      change is one field).

## 3. Commit + PR

The Hydra builder commits and the wrapper auto-creates the draft PR.
No additional manual action expected from the implementer.

## Deduplication Check

- [ ] Confirmed: no duplication. This is a single-field metadata bump,
      no abstractions involved.

---

## Notes for the implementer

This spec is the canonical `kind: config` minimal example, drafted
2026-05-08 to validate the Hydra config-only flow end-to-end on the
smallest possible diff. Expected wall time: 15-25 minutes for the full
pipeline (build → quality → reviewer → security → applier).

If you find yourself touching anything other than `src/manifest.json`,
**stop** — that's out of scope for this spec.
