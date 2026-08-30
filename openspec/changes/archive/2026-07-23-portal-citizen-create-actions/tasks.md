# Tasks: portal-citizen-create-actions

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## Prerequisites (apply-time confirmations)

- [x] T01 — Confirm portaliq's shared create receiver server-stamps the scope from `subjectRef`, enforces the client-writable whitelist, and enforces (or lets the app enforce) the open-parent constraint; confirm where the per-subject create rate-limit lives (design.md Open Q1/Q2).
  **Findings (read `portaliq/lib/Controller/ContributionController.php` + `PortalObjectWriter.php` at HEAD):** the contract-v2.2 `create()` path stamps the scope via a dedicated `scopeField` key (writer sets `$data[$scopeField] = $subjectRef` unconditionally, after whitelisting) and stamps extra server values via a `defaults` map (applied over the whitelist) — NOT a combined `set` dict as the brief's shorthand implied; that `set` key exists only on the *update* path. The client whitelist is enforced (`$this->whitelist(fields: $action['fields'])`) and `minTrust` is re-checked before any write. **`parentConstraint` is NOT read anywhere in portaliq** — Open Q1 resolves to "the app enforces it": Decidesk's own `PortalCreateOpenParentGuardListener` (new, `ObjectCreatingEvent`) enforces the open-parent invariant fail-closed at the OpenRegister insert boundary. Open Q2 (rate-limit): no per-subject create throttle exists anywhere in portaliq today; filed as a follow-up (out of scope for this manifest-only + guard-listener change — see PR description).

## Implementation

- [x] T02 — Extend `lib/Portal/PortalContributionProvider.php` — `createReaction` (REQ-DKPCA-001). Declare a `type: create` action against `consultation-reaction`: client whitelist `{consultation, body}`; scope stamped via `scopeField: submitterId`; server `defaults` `{moderationStatus: 'pending', submittedAt: now}` (portaliq's real `defaults` mechanism, T01); parent constraint declared (`parentConstraint`) AND enforced by the new guard listener; `minTrust: low`. EUPL-1.2/SPDX docblock + `@spec` tags.

- [x] T03 — Extend the provider — `createBudgetProposal` (REQ-DKPCA-002). Declare a `type: create` action against `budget-proposal`: client whitelist `{participatoryBudget, title, description, requestedAmount, category}`; scope stamped via `scopeField: submitter`; server `defaults` `{status: 'submitted'}` (no `submittedAt` — the schema has none); parent constraint declared + enforced; `minTrust: low`.

- [x] T04 — Update `getContribution()` / `citizenContribution()` to return the two actions (REQ-DKPCA-004). Every scope/lifecycle/staff field stays OUT of the client whitelists — the scope field only ever appears in `scopeField` (never `fields`) and lifecycle fields only in `defaults` (REQ-DKPCA-003); the four read/inbox collections keep no write action; class stays plain/dependency-free (`citizenContribution()`/`citizenCollections()`/`citizenActions()` — extracted to stay under the PHPMD method-length gate — still no constructor, no `implements`, no portaliq import).

## Testing & quality

- [x] T05 — Unit tests `tests/Unit/Portal/PortalContributionProviderTest.php` (extended): pin `createReaction` + `createBudgetProposal` (ids, `type: create`, `minTrust: low`, exact client whitelists, exact `scopeField` + `defaults` incl. subjectRef scope-stamp + intake state, `parentConstraint`); assert no `submittedAt` is stamped on `budget-proposal`.

- [x] T06 — Unit tests for the write-IDOR / lifecycle invariant (REQ-DKPCA-003): assert `submitterId`/`submitter` (the `scopeField`) and every staff-only field (`moderationReason`, `publicatiedatum`, `depublicatiedatum`, `voteCount`, `votesFor`, `votesAgainst`) are never in the client whitelist; a non-`citizen` audience returns null. Plus a NEW test file `tests/Unit/Listener/PortalCreateOpenParentGuardListenerTest.php` (9 tests) pinning the open-parent guard's fail-closed behaviour: open/closed consultation, submission/draft budget round, both write-path shapes (scalar field vs `relations` array), missing parent, unrelated schema, non-`ObjectCreatingEvent`, and an infrastructure failure (deliberately fail-CLOSED, contrasting with `SubmissionDeadlineListener`'s fail-soft business-rule posture since this is a security invariant).

- [x] T07 — Register-drift pin: `testCreateActionsMatchShippedRegisterSchemas` asserts every whitelisted + stamped field exists on the shipped `decidesk_register.json` schemas (`ConsultationReaction`, `BudgetProposal`), and that each parent schema's status enum (`PublicConsultation`, `ParticipatoryBudget`) includes the required open state (`open` / `submission`), so a schema rename breaks the test not production.

- [x] T08 — Quality gates: `php -l`, phpcs, phpmd, psalm, phpstan (via `docker run nextcloud:34.0.0-apache`, host PHP too old) and the unit suite (812 tests) all pass with zero new violations on the touched/added files; `openspec validate portal-citizen-create-actions --strict` exits 0. (Hydra gate script itself was not invoked — not available in this worktree — but the mechanical checks it wraps were run directly: spdx headers present, no forbidden debug patterns, `@spec` tags present, route/auth N/A (no controller/route touched), no notification-dialect touched.)

## Quality checklist

- Every MUST in the spec has a unit test; the write-IDOR invariant (scope stamped from subjectRef, never client) and the moderation-stays-internal invariant are explicitly asserted.
- Field names are bound to the REAL schema (`consultation`, `submitterId`, `moderationStatus`, `requestedAmount`, `submitter`, `participatoryBudget`) verified against HEAD — not the brief's generic names; `budget-proposal` has no `submittedAt` so none is stamped.
- Manifest labels ship in English source (i18n policy); portaliq owns portal-side translation.
- No register JSON change; the receiver/scope-stamping is portaliq's shared path (not re-implemented here).
- No Decidesk UI ships (portaliq owns the SPA) — no Playwright; covered by PHPUnit.
