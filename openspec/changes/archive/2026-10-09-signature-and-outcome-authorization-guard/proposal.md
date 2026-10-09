---
kind: code
---

## Why

Gate-7 (`no-admin-idor`) stopped treating a delegated **authentication** helper as an
authorization guard (`ConductionNL/.github#365`). Four `#[NoAdminRequired]` endpoints that had
been clearing the gate on `requireUserOr401()` / a `getUser() === null` preamble alone are now
reported as unguarded, and three of the four really are:

- **`EIDASSignatureController::finalize()`** — the highest-stakes endpoint in the app. It affixes
  a QES signature set to a governance record: `EIDASSignatureService::finalizeMinutes()` writes
  `pdfArchiveReference`, `hashSha256`, `signingCompletionDate`, `eidasSignatureLevel = QES`,
  `version = signed` and `signedBy` onto the Minutes row, resolves the `method=signature`
  `DecisionStage` to `outcome=adopted`, and appends a `signature` audit entry. Its sibling
  `initiate()` — which merely *starts* the flow — has enforced the OR-projected signatory scope
  since `consume-or-rbac-authorization` (R-4). `finalize()` enforced nothing at all: **any
  authenticated Nextcloud user could mark any Minutes UUID as signed.**
- **`EIDASSignatureController::verify()`** — routed as
  `POST /api/minutes/{minutesId}/eidas/verify`, part of the same per-Minutes signing flow, with
  no scope check. It returns the signer's `certificateThumbprint` — an eIDAS qualified
  certificate identifies a natural person — to any authenticated caller who supplies a
  `requestId`.
- **`IntegrationController::getOutcome()`** — takes a caller-supplied Decision UUID straight into
  `DecisionIntegrationService::getOutcomeEnvelope()`. The controller docblock claims *"Per-object
  read access is enforced by OpenRegister ObjectService RBAC inside the service — callers without
  read access receive 404 (no UUID probing)"*. **That claim is false.** The `Decision` schema in
  `lib/Settings/decidesk_register.json` declares **no `authorization` block**, so the register
  baseline applies (`read`/`list`: `["authenticated", "public"]`) and OR authorises the read for
  everyone. Any authenticated user could read any Decision's outcome envelope — including the
  cross-app subject coordinates (`subjectRegister`/`subjectSchema`/`subjectId`, e.g. "this board
  decision concerns shillinq Contract X"), the consumer's `externalReference`, and the `signers`
  array — for decisions that are still internal/unpublished.

The fourth, **`EIDASSignatureController::certStatus()`**, is investigated and found **not** to be
an IDOR: see "Deliberately app-wide" below. It is made explicit rather than silently guarded.

## What Changes

- **`GovernanceScopeGuard`** gains a public `isSignatoryForMinutes()` — the Minutes -> Meeting ->
  GovernanceBody resolution plus the OR-projected `decidesk:body:{bodyId}:signatory` scope check
  that `canInitiateSigning()` already performed. `canInitiateSigning()` now delegates to it, so
  there is exactly one implementation of "is this actor a signatory on these minutes", and it
  keeps failing **closed** on any unresolved hop.
- **`EIDASSignatureController::verify()` and `::finalize()`** consult that guard before reaching
  the signature service and return `403 Forbidden` otherwise — the same shape `initiate()` already
  uses. The service is never invoked on a refusal, so a refused `finalize()` cannot write to the
  Minutes row or resolve a signature stage.
- **`EIDASSignatureController::certStatus()`** is documented as a deliberately app-wide
  authenticated read with a reason-bearing `@no-admin-idor-exempt` tag (see below).
- **`DecisionIntegrationService`** gains a public `isAuthorizedToReadOutcome()`, and
  **`IntegrationController::getOutcome()`** resolves `$callerUid` (null for a Nextcloud admin,
  the `ProxyVoteController`/`ConflictOfInterestController` convention) and refuses with
  `403 Forbidden` when the guard denies. The corrected docblock states the real rule.
- The false OR-RBAC-delegation claim is removed from `IntegrationController`'s class docblock and
  from `DecisionIntegrationService::getOutcomeEnvelope()`.

### The `getOutcome` rule, and why it is this rule

There is **no caller -> consumer identity** anywhere in the contract-decision hub:
`createDecision()`'s `$actorId` is written to the audit log only, never persisted on the object,
and `isRegistryConsumer()` validates a callback *URL*, not a caller. So the only ownership fact
that exists is OpenRegister's own `@self.owner` — which is exactly the Nextcloud identity that
raised the Decision through `POST /api/v1/decisions`, i.e. precisely the consumer REQ-DCDH-003
exists to serve ("so a consumer can poll the result of a delegated decision"). This is also the
established decidiq convention for a per-object guard on this same `decision` schema:
`MotionCoauthorService::checkMotionAccess()` authorises on `@self.owner`.

A caller may therefore read a Decision's outcome envelope when they are **the raiser
(`@self.owner`)**, **a Nextcloud admin**, or when the Decision is **published**
(`isPublished === 'public'` — the app's own citizen-visibility flag, set by
`DecisionController::publish()`; a published decision is a public governance record by
definition). No functionality is lost: `grep -rn "v1/decisions" src/` returns **zero** frontend
callers, so the only callers are integration consumers polling decisions they raised, and the
published branch keeps every already-public decision readable.

Resolution failures (OpenRegister unavailable, `find()` throwing) **deny** rather than skip
(fail-closed, gate-8 `unsafe-auth-resolver`). A Decision that genuinely does not exist still
returns `404` from the envelope assembler — the guard does not convert a miss into a `403`.

### Deliberately app-wide: `certStatus()`

`POST /api/eidas/validate-cert` takes **no caller-supplied object identifier**. Its single input
is a certificate SHA-256 thumbprint, and its single output is `valid` / `issuer` /
`trustListLevel` — facts from the **public** EU Trusted List. No decidiq object is reachable
from it, nothing it returns is derived from app data, and its own docblock states it is an
informational pre-flight whose verdict is *not* authoritative (the binding chain validation
happens server-side inside `finalizeMinutes()`, which is now scope-guarded). The action and the
openconnector source slug are both fixed constants, so it is not a request-forgery surface
either. Restricting it would invent an authorization rule no spec states and would risk breaking
the signing pre-flight; it is instead marked with the reason-bearing `@no-admin-idor-exempt` tag
that gate-7 provides for exactly this shape. Residual, reported not fixed: an authenticated user
can drive repeated outbound calls to the configured QSP (a rate/cost surface, not an
access-control one).

## Register change (applied separately)

`lib/Settings/decidesk_register.json` is single-writer this session and is **not** touched here.
The recommended `Decision` schema `authorization` block is recorded in `tasks.md` Task 5 for the
orchestrator to apply; the controller guard above does not depend on it.

## Impact

- **decidiq backend**: `GovernanceScopeGuard` (+1 public method, one shared implementation),
  `EIDASSignatureController` (2 guarded methods, 1 documented exemption), `IntegrationController`
  (+`IGroupManager`, 1 guarded method), `DecisionIntegrationService` (+1 public guard method).
- **decidiq frontend**: none — no frontend caller exists for any of the four endpoints.
- **OpenRegister**: none.

Not marked BREAKING: this closes an authorization gap that should never have been open. No
legitimate caller was relying on finalizing signatures on minutes of a body they hold no
signatory role on, or on polling the outcome of a decision they neither raised nor may see.
