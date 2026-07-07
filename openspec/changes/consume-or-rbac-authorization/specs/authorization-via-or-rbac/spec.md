# authorization-via-or-rbac — delta: consume OpenRegister RBAC for actor authorization

## ADDED Requirements

### Requirement: REQ-RBAC-001 Governance-body roles project into OpenRegister RBAC scopes
decidesk SHALL maintain, per GovernanceBody, two OpenRegister RBAC scopes derived from the body's
member roles: a **chair scope** (`decidesk:body:{bodyId}:chair`) containing members whose role is
`chair` or `chairman`, and a **signatory scope** (`decidesk:body:{bodyId}:signatory`) containing
members whose role is `chair`, `chairman`, `vice-chairman`, or `secretary`. A role-projection hook
SHALL reconcile both scopes to the body's current roster whenever a member (Participant/Membership)
of that body is created, updated, or removed. Reconciliation SHALL be idempotent and SHALL fail
closed (an unresolved or empty scope denies rather than over-grants).

#### Scenario: Adding a chair populates both scopes
- **GIVEN** a GovernanceBody with no chair
- **WHEN** a member of that body is given the role `chair`
- **THEN** that member is present in both `decidesk:body:{bodyId}:chair` and
  `decidesk:body:{bodyId}:signatory`.

#### Scenario: Secretary is a signatory but not a chair
- **GIVEN** a GovernanceBody
- **WHEN** a member is given the role `secretary`
- **THEN** that member is present in `decidesk:body:{bodyId}:signatory`
- **AND** that member is NOT present in `decidesk:body:{bodyId}:chair`.

#### Scenario: Removing a role reconciles the scopes idempotently
- **GIVEN** a member currently in a body's chair scope
- **WHEN** that member's chair role is removed and the projection runs (or re-runs)
- **THEN** the member is absent from both scopes
- **AND** re-running the projection produces no further change.

### Requirement: REQ-RBAC-002 Signatory authorization is an OpenRegister RBAC rule, not an app-local service
Initiating a qualified e-signature (QES) on a Minutes record (and on a resolution `decision`) SHALL
be authorized by an OpenRegister property-RBAC rule that write-gates the signing-request property to
the owning body's `signatory` scope. The app-local `MinutesAuthorizationService` SHALL be removed and
SHALL NOT be reintroduced; the signing controller SHALL rely on OpenRegister returning a
403/`null` for a non-signatory. No app-local `*AuthorizationService` or `*PermissionService` for
decidesk OpenRegister objects SHALL remain.

#### Scenario: A signatory may initiate signing
- **GIVEN** a Minutes record on a body and a user in that body's `signatory` scope
- **WHEN** the user initiates QES signing
- **THEN** OpenRegister authorizes the signing-request write and the signing flow starts.

#### Scenario: A non-signatory is denied by OpenRegister
- **GIVEN** a Minutes record on a body and a user NOT in that body's `signatory` scope
- **WHEN** the user attempts to initiate QES signing
- **THEN** OpenRegister denies the signing-request write (403)
- **AND** no app-local authorization service is consulted.

#### Scenario: The anti-pattern gate is clean
- **GIVEN** the decidesk worktree after this change
- **WHEN** `lint-or-abstraction-anti-patterns.sh` runs
- **THEN** it reports no `consume-or-rbac-fleet-wide` finding for decidesk.

### Requirement: REQ-RBAC-003 Chair-only lifecycle transitions are enforced by OpenRegister property RBAC
Chair-only meeting-lifecycle transitions SHALL be enforced by an OpenRegister property-RBAC rule that
write-gates the `meeting.lifecycle` property to the owning body's `chair` scope for the transitions
listed in the workflow template's `chairOnlyTransitions`. The imperative
`WorkflowService::requiresChairAuthorization()` actor-branch and the NC-UID-vs-chair comparison in
`MeetingService` SHALL be removed. The workflow **policy** predicates (domain `allowPause` /
`allowAdjourn` flags, `isQuorumRequired`) and the quorum data-predicate
(`DecisionTransitionGuard::isOpenAllowed`) SHALL remain, as they express process configuration and
data state, not actor authorization.

#### Scenario: Only the chair may run a chair-only transition
- **GIVEN** a meeting whose `from:to` transition is in `chairOnlyTransitions`
- **WHEN** a user NOT in the body's `chair` scope attempts the transition
- **THEN** OpenRegister denies the `meeting.lifecycle` write (403)
- **AND WHEN** the body's chair attempts the same transition
- **THEN** OpenRegister authorizes it and the lifecycle advances.

#### Scenario: Domain policy still forbids a disallowed transition regardless of actor
- **GIVEN** a domain whose workflow sets `allowPause: false`
- **WHEN** the body's chair attempts `opened → paused`
- **THEN** the transition is refused by the workflow policy (not permitted in this domain)
- **AND** the refusal is independent of the actor's scope membership.

### Requirement: REQ-RBAC-004 The duplicated admin guards consume OpenRegister's admin determination
decidesk SHALL replace the four per-controller `requireAdmin()` copies
(`GovernanceReportController`, `MultilingualReconciliationController`, `AuditLogController`,
`RegulatorExportController`) with a single shared admin guard that consumes OpenRegister's admin
determination (`PropertyRbacHandler::isAdmin()` / the OpenRegister authorization decision). Each
controller SHALL call the shared guard; a non-admin SHALL receive 403 on every previously
admin-gated method.

#### Scenario: A non-admin is denied on every previously admin-gated surface
- **GIVEN** a non-admin user
- **WHEN** the user calls any method previously guarded by a private `requireAdmin()`
- **THEN** the shared guard returns 403
- **AND** there is exactly one admin-guard implementation shared across the four controllers.

#### Scenario: An admin is allowed
- **GIVEN** an admin user
- **WHEN** the user calls a previously admin-gated method
- **THEN** the shared guard permits the call.

### Requirement: REQ-RBAC-005 Fail-closed authorization is preserved end to end
Every migrated authorization path SHALL deny on ambiguity — an unresolved scope, a missing body, or
an OpenRegister error SHALL result in no write (403/`null`), never a silent skip. The change SHALL
NOT introduce a `catch (\Throwable) { return null; }` resolver whose null return is treated by a
caller as "check skipped".

#### Scenario: An unresolved scope denies
- **GIVEN** a signing or chair-only transition attempt whose body scope cannot be resolved
- **WHEN** OpenRegister evaluates the RBAC rule
- **THEN** the write is denied (403)
- **AND** the attempt is never treated as authorized.
