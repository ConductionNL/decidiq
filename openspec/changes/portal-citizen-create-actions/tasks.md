# Tasks: portal-citizen-create-actions

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## Prerequisites (apply-time confirmations)

- [ ] T01 — Confirm portaliq's shared create receiver server-stamps the scope from `subjectRef`, enforces the client-writable whitelist, and enforces (or lets the app enforce) the open-parent constraint; confirm where the per-subject create rate-limit lives (design.md Open Q1/Q2).

## Implementation

- [ ] T02 — Extend `lib/Portal/PortalContributionProvider.php` — `createReaction` (REQ-DKPCA-001). Declare a `type: create` action against `consultation-reaction`: client whitelist `{consultation, body}`; server `set` `{submitterId: subjectRef, moderationStatus: 'pending', submittedAt: now}`; parent constraint `PublicConsultation.status == 'open'`; `minTrust: low`. EUPL-1.2/SPDX docblock + `@spec` tags.

- [ ] T03 — Extend the provider — `createBudgetProposal` (REQ-DKPCA-002). Declare a `type: create` action against `budget-proposal`: client whitelist `{participatoryBudget, title, description, requestedAmount, category}`; server `set` `{submitter: subjectRef, status: 'submitted'}` (no `submittedAt` — the schema has none); parent constraint `ParticipatoryBudget.status == 'submission'`; `minTrust: low`.

- [ ] T04 — Update `getContribution()` / `citizenContribution()` to return the two actions (REQ-DKPCA-004). Keep every scope/lifecycle/staff field OUT of the client whitelists and in the `set` (REQ-DKPCA-003); the four read/inbox collections keep no write action; class stays plain/dependency-free.

## Testing & quality

- [ ] T05 — Unit tests `tests/unit/Portal/PortalContributionProviderTest.php` (extend): pin `createReaction` + `createBudgetProposal` (ids, `type: create`, `minTrust: low`, exact client whitelists, exact server `set` incl. subjectRef scope-stamp + intake state, parent constraints); assert no `submittedAt` is stamped on `budget-proposal`.

- [ ] T06 — Unit tests for the write-IDOR / lifecycle invariant (REQ-DKPCA-003): assert `submitterId`/`submitter` and `moderationStatus`/`status` are in the `set`, never the client whitelist; assert no staff-only field (`moderationReason`, `publicatiedatum`, `depublicatiedatum`, `voteCount`, `votesFor`, `votesAgainst`) is client-writable; a non-`citizen` audience returns null.

- [ ] T07 — Register-drift pin: assert every whitelisted + stamped field exists on the shipped `decidesk_register.json` schemas (`ConsultationReaction`, `BudgetProposal`, and the parent `status` enums include `open` / `submission`), so a schema rename breaks the test not production.

- [ ] T08 — Quality gates: `php -l`, `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the unit suite pass with zero new violations; relevant Hydra gates (spec-coverage, notification-dialect, or-objectservice-api) green; `openspec validate portal-citizen-create-actions --strict` exits 0.

## Quality checklist

- Every MUST in the spec has a unit test; the write-IDOR invariant (scope stamped from subjectRef, never client) and the moderation-stays-internal invariant are explicitly asserted.
- Field names are bound to the REAL schema (`consultation`, `submitterId`, `moderationStatus`, `requestedAmount`, `submitter`, `participatoryBudget`) verified against HEAD — not the brief's generic names; `budget-proposal` has no `submittedAt` so none is stamped.
- Manifest labels ship in English source (i18n policy); portaliq owns portal-side translation.
- No register JSON change; the receiver/scope-stamping is portaliq's shared path (not re-implemented here).
- No Decidesk UI ships (portaliq owns the SPA) — no Playwright; covered by PHPUnit.
