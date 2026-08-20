---
kind: code
---

# Proposal: consume-or-rbac-authorization

## Summary
Retire decidesk's **app-local authorization stack** for OpenRegister objects and re-express those
access decisions as **OpenRegister RBAC** (property-level RBAC scopes + a projected governance-role
scope), so authorization over decidesk objects is owned centrally by OpenRegister (ADR-022 /
ADR-051) rather than by imperative PHP in the leaf app. The three pieces migrated are:

1. `lib/Service/MinutesAuthorizationService.php` — walks Minutes → Meeting → GovernanceBody →
   Participant to decide whether the actor may initiate QES signing.
2. The chair-only lifecycle-transition branch in `lib/Service/WorkflowService.php`
   (`requiresChairAuthorization()`) + the NC-UID-vs-chair comparison in
   `lib/Service/MeetingService.php`.
3. Four copy-pasted per-controller `requireAdmin()` guards in `GovernanceReportController`,
   `MultilingualReconciliationController`, `AuditLogController`, and `RegulatorExportController`.

## Motivation
The OR-abstraction anti-pattern gate (`lint-or-abstraction-anti-patterns.sh`) flags decidesk with
**`consume-or-rbac-fleet-wide`**: an app-local `*AuthorizationService` for OpenRegister objects. The
gate is in WARN mode today and **flips to BLOCK on 2026-08-13**. Per ADR-022 (apps consume OR
abstractions) and ADR-051 §4 (exclusivity strengthening — an OR-owned capability MUST NOT be
duplicated app-locally), authorization over OR objects belongs to OpenRegister's
`PropertyRbacHandler` / rbac-scopes, not to a leaf-app service.

decidesk already delegates *data-access* RBAC correctly (every read/write goes through
`ObjectService`, whose per-object RBAC returns `null`/403 for unauthorized users — see the class
docblocks on `MeetingController`, `MemberImportService`, `DecideskSearchProvider`). The remaining
imperative authorization is:

- **Signatory gate.** `MinutesAuthorizationService::canInitiateSigning()` re-derives "is this user a
  chair/secretary/vice-chair of the body?" by loading three OR objects and matching a role enum — a
  domain-role authorization decision expressed in PHP.
- **Chair-only transitions.** `WorkflowService::requiresChairAuthorization()` returns true for
  restricted transitions and `MeetingService` then compares the session UID to the chair's UID — a
  second, parallel actor-authorization path.
- **Admin gate duplication.** Four controllers each carry a private ~14-line `requireAdmin()` copy.

decidesk schemas already declare OR RBAC predicates (e.g. the `public-group` published-predicate
rule `publicatiedatum <= $now` in `lib/Settings/decidesk_register.json`), so extending that
declarative surface with **write-gating scopes** is consistent with how the app already works — and
removes the imperative layer the gate rejects.

## Affected Projects
- [x] Project: `decidesk` — retire the app-local authorization stack; declare the equivalent OR RBAC
  scopes on the affected schemas; project governance roles into an OR scope; consolidate the admin
  guard. No new business capability; the *who-may-act* outcome is preserved, its owner changes.

## Scope

### In Scope
- **Project governance-body roles into an OR RBAC scope.** When a Membership/Participant with role
  `chair` / `chairman` / `vice-chairman` / `secretary` on a body is created or changed, decidesk
  maintains an OR-scoped group (the body's *signatory scope*) so "who may sign / advance this body's
  objects" is an OR-queryable, OR-enforceable fact rather than a runtime graph walk.
- **Signatory authorization becomes an OR property-RBAC rule.** The `minutes` (and resolution
  `decision`) schema declares that the signing-request property is write-gated to the body's
  signatory scope; the controller relies on OR returning 403/`null`. `MinutesAuthorizationService`
  is deleted.
- **Chair-only lifecycle transitions become an OR property-RBAC rule.** The `meeting` `lifecycle`
  property (and the transition endpoint) is write-gated to the body's chair scope; the
  `requiresChairAuthorization()` branch + NC-UID comparison in `MeetingService` is removed. The
  **domain policy** flags in `WorkflowService` (`allowPause`, `allowAdjourn`, `quorumEnforced`,
  `chairOnlyTransitions` *as a workflow-template value*) stay — those are process configuration, not
  actor authorization.
- **Consolidate the admin gate.** The four duplicated `requireAdmin()` methods are replaced by a
  single shared guard that consumes OpenRegister's admin determination
  (`PropertyRbacHandler::isAdmin()` / the OR authorization decision), removing copy-pasted code.
- **Gate passes clean.** After the change, `lint-or-abstraction-anti-patterns.sh` reports no
  `consume-or-rbac-fleet-wide` finding for decidesk before it flips to BLOCK.

### Out of Scope
- Any change to OpenRegister itself (this change consumes existing OR RBAC — `PropertyRbacHandler`,
  rbac-scopes, `isAdmin()` — it does not add OR capability).
- The data-access RBAC already correctly delegated to `ObjectService` (unchanged).
- The quorum guard (`DecisionTransitionGuard::isOpenAllowed`) — that is a *data-predicate* check
  (quorumMet), not an actor-authorization decision, and stays.
- Behavioural change to which roles are allowed — the allowed-signatory / allowed-chair set is
  preserved exactly; only the enforcement owner moves.

## Approach
Extend the existing declarative RBAC in `decidesk_register.json` with write-gating scopes on the
`meeting.lifecycle`, `minutes` signing, and `decision` resolution properties, keyed on a per-body
signatory/chair scope. Add a thin **role-projection** hook (on Membership/Participant write) that
keeps each body's OR scope in sync with its chair/secretary/vice-chair members. Delete
`MinutesAuthorizationService` and the `EIDASSignatureController` call into it; delete the
`requiresChairAuthorization` branch in `MeetingService`; replace the four `requireAdmin()` copies
with one shared `OrAdminGuard` that consumes OR's admin decision. See `design.md`.

## New Dependencies
None. Consumes OpenRegister's existing `PropertyRbacHandler` / rbac-scopes / `isAdmin()`.

## Impact
- **decidesk backend**: `MinutesAuthorizationService` deleted; `MeetingService` /
  `WorkflowService` chair-auth branch removed; four `requireAdmin()` methods consolidated into one
  shared guard; a role-projection hook added; `decidesk_register.json` gains write-gating RBAC
  scopes. Net **less** app-owned authorization code.
- **decidesk frontend**: none (the UI already handles 403s from the object API).
- **OpenRegister**: none.

## Cross-Project Dependencies
Depends only on OpenRegister's already-shipped RBAC surface (`PropertyRbacHandler`, schema
rbac-scopes, `isAdmin()`), which decidesk already consumes for object-access RBAC.

## Risks

### Risk 1: Role-projection drift
**Severity:** Medium — **Mitigation:** The projection hook runs on every Membership/Participant
write and is idempotent (reconciles the body's scope to its current chair/secretary/vice-chair set);
a repair/one-shot reconciler backfills existing bodies. Because the OR RBAC rule fails **closed**
(no scope membership → no write), drift can only ever *deny*, never over-grant.

### Risk 2: A transition path currently allowed only via the PHP branch is missed
**Severity:** Medium — **Mitigation:** Enumerate every `chairOnlyTransitions` entry and the signing
gate; each maps to exactly one write-gated property scope. Unit + e2e assert 403 for a non-chair and
success for the chair, mirroring the existing tests before deletion.

### Risk 3: The shared admin guard changes an admin's effective access
**Severity:** Low — **Mitigation:** The consolidated guard consumes the *same* admin determination
NC/OR already use; the four call sites keep identical semantics (deny non-admin), only de-duplicated.

## Rollback Strategy
Revert the register RBAC-scope additions, restore the deleted service + branches, and drop the
role-projection hook. Because the migration preserves the *who-may-act* outcome, rollback is
behaviour-neutral.

## Open Questions
- Should the per-body signatory scope and chair scope be one scope with role sub-tags or two
  distinct scopes? (Resolve in design — leaning two, since signatory ⊇ chair for minutes but the
  lifecycle gate wants chair only.)
- Backfill existing bodies via a repair step or an on-first-write reconcile? (Resolve in design.)
