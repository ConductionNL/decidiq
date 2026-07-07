# Design: consume-or-rbac-authorization

## Context
decidesk is a thin client over OpenRegister: it owns no database tables and routes every object
read/write through `ObjectService`, whose per-object RBAC already enforces access. Three pockets of
**imperative, app-local authorization** remain — they decide *who may act* on an OR object in PHP
instead of letting OpenRegister own that decision. The OR-abstraction gate flags this as
`consume-or-rbac-fleet-wide` (WARN → BLOCK 2026-08-13). ADR-022 (apps consume OR abstractions) and
ADR-051 §4 (an OR-owned capability MUST NOT be duplicated app-locally) require the migration.

### The three app-local authorization pockets (current state, verified at HEAD)
| # | Location | What it decides | How it decides |
|---|----------|-----------------|----------------|
| 1 | `MinutesAuthorizationService::canInitiateSigning()` | May this user initiate QES signing on a Minutes record? | Loads Minutes → Meeting → GovernanceBody, then loads Participants and matches the actor against `SIGNATORY_ROLES = [chair, chairman, vice-chairman, secretary]`. Fails closed. |
| 2 | `WorkflowService::requiresChairAuthorization()` + `MeetingService` | May this user perform a chair-only lifecycle transition? | `requiresChairAuthorization()` returns true when `"$from:$to"` ∈ the workflow's `chairOnlyTransitions`; `MeetingService` then compares the session UID to the chair's resolved NC UID. |
| 3 | `requireAdmin()` ×4 (`GovernanceReportController`, `MultilingualReconciliationController`, `AuditLogController`, `RegulatorExportController`) | Is the caller an admin? | Each controller has its own private ~14-line copy calling the group manager. |

Pocket 1 is the file the gate names; pockets 2 and 3 are the same anti-pattern (imperative
authorization for OR objects) and are folded in so the migration is complete rather than cosmetic.

## Goals / Non-Goals
**Goals**
- Move the *owner* of each authorization decision from decidesk PHP to OpenRegister RBAC.
- Preserve the exact *who-may-act* outcome (chair/secretary/vice-chair may sign; only the chair may
  run chair-only transitions; only admins may hit the admin-gated controllers).
- Leave the gate with zero `consume-or-rbac-fleet-wide` findings for decidesk.

**Non-Goals**
- No change to OpenRegister (consume existing RBAC only).
- No change to *data-access* RBAC (already delegated to `ObjectService`).
- No change to workflow *policy* (allow/forbid a transition per domain) — that is process
  configuration and stays in `WorkflowService`.

## Decisions

### D1 — Governance roles project into per-body OR RBAC scopes
OpenRegister RBAC is group/scope-based at the object and property level (`PropertyRbacHandler`
supports `canUpdateProperty` keyed on the resolved user's scopes). decidesk's authorization is
*role-on-a-body* ("chair of body B"). To make that an OR-enforceable fact, decidesk projects each
body's signatory roster into an **OR RBAC scope**:

- `decidesk:body:{bodyId}:chair` — members whose Participant/Membership role is `chair`/`chairman`.
- `decidesk:body:{bodyId}:signatory` — members whose role is `chair`/`chairman`/`vice-chairman`/
  `secretary` (superset used by the signing gate).

A **role-projection hook** (invoked on Participant/Membership create/update/delete for the body)
reconciles the two scopes to the body's current roster. The reconcile is idempotent and fails
**closed** — a missing scope membership denies, never over-grants. Existing bodies are backfilled by
a one-shot idempotent reconciler (repair step). *Open question resolved in tasks:* two scopes, not
one, because the lifecycle gate wants `chair` only while the signing gate wants the `signatory`
superset.

### D2 — The signing gate becomes a property-RBAC write rule; the service is deleted
`minutes` (and resolution `decision`) schemas in `decidesk_register.json` declare that the
signing-request property (the field the QES flow writes to start signing) is **write-gated to
`decidesk:body:{bodyId}:signatory`**. The `EIDASSignatureController` stops calling
`MinutesAuthorizationService::canInitiateSigning()`; it writes the signing-request via
`ObjectService`, and OpenRegister returns 403/`null` for a non-signatory exactly as it already does
for object-access RBAC. `MinutesAuthorizationService` and its DI registration are **deleted** — this
is the file the gate names, so its removal clears the finding.

### D3 — Chair-only transitions become a property-RBAC write rule; the actor branch is removed
The `meeting.lifecycle` property (and the lifecycle-transition endpoint) is **write-gated to
`decidesk:body:{bodyId}:chair`** for the transitions currently listed in `chairOnlyTransitions`. The
`requiresChairAuthorization()` call + the NC-UID-vs-chair comparison in `MeetingService` are
**removed**. `WorkflowService` keeps the *policy* predicates (`isTransitionAllowed`'s
`allowPause`/`allowAdjourn` domain flags, `isQuorumRequired`) because those answer "is this
transition permitted at all in this domain", not "may *this actor* do it". The quorum data-predicate
(`DecisionTransitionGuard::isOpenAllowed`) also stays — it is not actor authorization.

> Note: `chairOnlyTransitions` remains a *workflow-template value* consumed to decide **which**
> `meeting.lifecycle` writes carry the chair scope; it is no longer read to run an imperative
> actor check. The template stays declarative; the enforcement moves to OR.

### D4 — One shared admin guard consuming OR's admin decision
The four private `requireAdmin()` copies are replaced by a single `OrAdminGuard` (or trait) that
consumes OpenRegister's admin determination (`PropertyRbacHandler::isAdmin()` / the OR authorization
decision). Each controller calls the shared guard; semantics (deny non-admin → 403) are identical.
This removes ~56 lines of duplicated authorization code and centralises the admin decision on OR.

### D5 — Fail-closed is preserved end to end
Every migrated path denies on ambiguity: an unresolved scope, a missing body, or an OR error yields
no write (403/`null`). This matches `MinutesAuthorizationService`'s existing fail-closed contract and
avoids the `unsafe-auth-resolver` anti-pattern (no `catch → return null → treat as skipped`).

## Migration / Sequencing
1. Add the two per-body scopes + the idempotent role-projection hook (no behaviour change yet;
   scopes populated in parallel with the still-live PHP guards).
2. Add the write-gating RBAC rules on `minutes`/`decision`/`meeting.lifecycle` in the register.
3. Backfill existing bodies (one-shot reconciler).
4. Delete `MinutesAuthorizationService` + its call site; remove the chair-auth branch; consolidate
   `requireAdmin()`.
5. Confirm the anti-pattern gate is clean and the signing/transition/admin e2e tests still pass.

Steps 1–3 are additive and reversible; the imperative removal (4) lands only once the OR rules are
proven equivalent by the tests.

## Risks & Mitigations
- **Projection drift** → idempotent reconcile on every roster write + one-shot backfill; fails
  closed (can only deny).
- **A missed chair-only transition or signing edge** → enumerate `chairOnlyTransitions` + the signing
  gate; one write-gated scope per case; tests mirror the pre-deletion assertions.
- **Admin-guard semantics change** → consume the *same* admin determination; de-duplication only.

## Test Strategy
- Unit: role-projection reconcile is idempotent and fails closed; the shared admin guard denies a
  non-admin and allows an admin.
- e2e (Playwright, UI): a non-signatory cannot start signing (403 surfaced), a signatory can; a
  non-chair cannot run a chair-only transition, the chair can; a non-admin is denied on each of the
  four previously-`requireAdmin` surfaces.
- Gate: `lint-or-abstraction-anti-patterns.sh <worktree>` reports no `consume-or-rbac-fleet-wide`.
