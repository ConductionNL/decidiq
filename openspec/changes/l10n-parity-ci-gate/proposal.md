---
kind: code
---

## Why

`tests/l10n/check-l10n-parity.js` is a fully-implemented, dependency-free gate that asserts every
required locale carries a real translation for every English source key (both the frontend
`l10n/en.js` → `l10n/<locale>.js` set and the backend `l10n/en.json` → `l10n/<locale>.json` set),
per its own docblock: *"Without this, a new English string ships and the other languages silently
fall back to English with a green pipeline."* It is never invoked:

- `package.json:9-25` (`scripts`) only wires `test:l10n` → `tests/l10n/check-l10n.js` (the
  EN-extraction/drift check — asserts `t()` calls have a matching `en.json` key). There is no
  `test:l10n:parity` (or similar) script pointing at `check-l10n-parity.js`.
- `.forgejo/workflows/tests.yml:112` and `.forgejo/workflows/app-tests.yml:86` both run `node
  tests/l10n/check-l10n.js` — the drift check — never `check-l10n-parity.js`.
- `grep -rn check-l10n-parity package.json .forgejo` returns nothing.

Running `node tests/l10n/check-l10n-parity.js` directly against the current tree confirms the gap
this dead gate would have caught: `nl.json` (the flagship locale for a Dutch governance app
positioned on the NL Design System, per `openspec/specs/nl-design-theming/`) is missing 29 of
1,318 backend translation keys (e.g. `"Withdraw"`, `"Advice"`, `"None"`, `"Open in decidiq"` —
common, frequently-rendered UI strings), and every other required locale is missing 275+ keys.
Every one of these silently falls back to the English source in production with a fully green
CI pipeline — exactly the failure mode `check-l10n-parity.js` was written to prevent.

This is a genuine "tooling exists, isn't wired up" gap distinct from the underlying translation
debt itself (backfilling 275+ keys per locale for ~35 non-English locales is out of scope for a
single change — see "What Changes" for the scoped fix).

## What Changes

- Add a `test:l10n:parity` npm script pointing at `tests/l10n/check-l10n-parity.js`.
- Wire `test:l10n:parity` into both `.forgejo/workflows/tests.yml` and
  `.forgejo/workflows/app-tests.yml` alongside the existing `test:l10n` drift check.
- Scope the initial `L10N_REQUIRED_LOCALES` (env override the script already supports) to `nl`
  only for this change — the flagship / NL Design System locale — rather than the full ISO 639-1
  European-language set the script defaults to, since backfilling every locale in one change is
  disproportionate. Widening the required set to more locales is a follow-up, tracked as a
  cross-cutting note (see final report), not part of this change.
- Backfill the 29 missing `nl.json` keys identified above so the new gate passes at merge time
  (translation values only — a translator/native-Dutch-speaker pass, not a mechanical fill).
- Not BREAKING: this is a new CI gate plus a translation backfill; no runtime behavior changes for
  end users beyond previously-English strings now rendering in Dutch.
