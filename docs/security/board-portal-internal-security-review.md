# Decidesk Board Portal — Internal Security Review

**Date:** 2026-06-12 (W33)
**Scope:** `board-meeting-resolutions` change — audit-trail
immutability, access-control enforcement, eIDAS QES integration.
**Reviewer:** Internal (Conduction engineering).
**Status:** Internal partial review. Does NOT substitute for an
independent third-party security audit (Computest / Northwave /
Madison Gurkha). §10.10 of `board-meeting-resolutions/tasks.md`
remains `[~]` (tracking) until an external engagement is commissioned.
**Method:** STRIDE-style threat-model walk + targeted code review of
the three security-load-bearing services + RBAC matrix sanity-check +
controller authorization surface scan + dependency posture check.

> **Why this document exists.** The W23 / W32 documented-handoff flips
> on §10.10 left the task-checkbox in a `[x]` state with a body that
> said the task "intentionally stays `[~]` until an audit is
> commissioned." The W33 audit caught the inconsistency, reverted the
> checkbox to `[~]`, and produced this internal review as honest,
> reviewable partial work — so that the §10.10 deliverable is not
> empty while the external auditor is still pending engagement.

---

## 1. Scope and limitations

This review covers the security-load-bearing surfaces named in
§10.10:

1. **Audit-trail immutability** — `lib/Service/AuditLogService.php`
   (497 LOC). Append-only SHA-256 hash chain over board actions.
2. **Access-control enforcement** — `lib/Service/BoardMaterialAuthorizationService.php`
   (265 LOC) + the controller authorization surface
   (`AuditLogController`, `RegulatorExportController`,
   `EIDASSignatureController`).
3. **eIDAS QES integration** — `lib/Service/EIDASSignatureService.php`
   (456 LOC) — QSP delegation via openconnector's `e-sign` Source.

Out of scope: penetration testing (no live target), social-engineering
review, physical-security review, third-party crypto certification
of the QSP itself, deep static analysis of all 30+ services. Those
require an external engagement.

## 2. Threat model (STRIDE)

| Threat (STRIDE) | Asset | Internal-review finding |
|---|---|---|
| **S**poofing — actor identity | Audit-log `actor` field | Captured server-side from `IUserSession` in the audit-log call sites; not user-supplied. **OK.** |
| **T**ampering — audit-log row | `oc_openregister_*_decidesk_audit-log-entry` table | Hash chain `currentHash = sha256(timestamp + actor + action + objectUids + previousHash)` recomputed on `verify()`. Any UPDATE breaks the chain. **OK** at app level. Residual: a DB admin with row-DELETE privilege can drop entries — caught by row-count discontinuity but not by hash mismatch. **Risk R-1 (low).** |
| **R**epudiation — signed minutes | `Minutes` + `EIDASSignature` rows | QES delegated to QSP; verification chain stored in `LogEIDASSignatureService` + audit-log entry. Repudiation requires breaking both QSP signature AND the local audit-log chain. **OK.** |
| **I**nformation disclosure — board materials | `BoardMaterial` rows | RBAC matrix in `BoardMaterialAuthorizationService::ACCESS_MATRIX` enforces role-based access. **Risk R-2 (low):** matrix is per-material, not per-meeting; a material missing an `accessLevel` field defaults to `board-only` (`?? 'board-only'`). Default-deny would be safer but the current default is the *most-restrictive non-empty* level, so the fail mode is "default to closed", which is correct. |
| **D**enial of service — audit-log verify | `verify()` chain walk | Full-chain walk is O(n); a 10-year-old board may have ~50k rows. **Risk R-3 (info):** at ~100µs/row this is ~5s wall-time which is acceptable, but a regulator-export over a 30-year tenancy could approach minute scale. Pagination is supported via `entryUuid`. |
| **E**levation of privilege — audit-log read | `AuditLogController::*` | All three methods (`list`, `verify`, `entry`) carry `#[NoAdminRequired]` BUT immediately call `requireAdmin()` which returns 403 unless `IGroupManager::isAdmin()`. The `@NoAdminRequired` is intentional to dodge SecurityMiddleware's silent-bypass in test setups (documented at controller-doc L41-L44). **OK** — defence-in-depth, semantic check matches code. |
| **E**levation of privilege — regulator export | `RegulatorExportController::*` | Same pattern: `#[NoAdminRequired]` + `requireAdmin()` guard. **OK.** |
| **E**levation of privilege — signature initiation | `EIDASSignatureController::initialize` | `#[NoAdminRequired]` only; no per-board membership check at the controller. The downstream `EIDASSignatureService::initializeSigningRequest` does NOT verify the caller is a member of the signatories list. **Risk R-4 (medium):** any authenticated user can initiate a signing request for any minutes ID, provided they know the UUID. The QSP will then notify the signatories — so the impact is a notification-spam vector, not an unauthorized signature, but it bypasses the workflow service. Recommend a `requireBoardMember($minutesId)` guard. |

## 3. Audit-trail immutability deep-dive

### 3.1 Hash-chain construction

`AuditLogService.php` L83-L154. Canonical payload is built as:

```
timestamp + actor + action + objectUids + previousHash
```

then SHA-256 over `json_encode($canonical)`. Genesis previousHash is
the literal string `"GENESIS"`.

**Findings:**

- **F-A1 (info).** Canonical-payload ordering is hard-coded via PHP
  associative array key order (insertion order), which is stable in
  PHP 8.3. **OK** — platform pins to PHP 8.3+ via
  `appinfo/info.xml` L56.
- **F-A2 (low).** Canonical payload omits the `payload` JSON field
  itself. An attacker with row-UPDATE privilege could mutate
  `payload` without breaking the chain. The hash is over
  `(timestamp, actor, action, objectUids, previousHash)` only; the
  business `payload` is unprotected. Recommend extending the
  canonical payload to include `hash('sha256', json_encode($payload))`
  so payload tampering is detected.
- **F-A3 (info).** Verification (`verify()` L206-L266) recomputes
  `currentHash` per row and compares to stored. If the row stores no
  `currentHash` it falls back to the recomputed one (L252), which
  silently passes for a NULL-currentHash row. Recommend hard-failing
  on missing currentHash so a partial-write race is detectable.
- **F-A4 (info).** `verify()` walks the full chain on every call.
  For long-running tenancies, consider a checkpoint-anchored
  verification (anchor the hash of every N-th entry to an external
  store such as an internal-CA-signed timestamp).

### 3.2 Append-only enforcement

The model relies on OpenRegister's object service for persistence.
There is no DB-level UPDATE/DELETE trigger preventing row mutation;
the immutability guarantee is by-convention + by-verify-detection.
**Risk R-1 (low).** An external auditor will want to see either:

- a DB-level CHECK / TRIGGER preventing UPDATE on this magic table, or
- a documented monitoring runbook that runs `verify()` on a schedule
  and alerts on mismatch.

The internal-prep checklist in
`docs/compliance/board-portal-compliance.md` §6 includes
"Audit-log verification of the last 200 entries returns `checked`"
but the cadence is "before every board cycle" (per-cycle, not
continuous). Recommend documenting the cron / monitoring frequency.

## 4. Access-control enforcement

### 4.1 RBAC matrix sanity

`BoardMaterialAuthorizationService::ACCESS_MATRIX` (L50-L74) maps
five access-level enum values to allow-listed roles:

| accessLevel | Allowed roles | Sanity |
|---|---|---|
| `board-only` | chairman, vice-chairman, member, executive-member, non-executive-member, independent-member, employee-representative | **OK** — full board scope. |
| `executive-only` | executive-member, chairman | **F-B1 (info):** vice-chairman intentionally excluded; chairman included as fallback chair-of-executives. Worth confirming with governance. |
| `audit-committee` | audit-committee-member, chairman | **OK** — chairman has ex-officio access to audit committee per Dutch CG-code §4.3.1. |
| `external-auditor` | external-auditor | **OK** — single role. |
| `regulator` | regulator | **OK** — single role. |

**F-B2 (low).** The matrix is a private const, not configuration. A
tenant whose bylaws require a different chair / vice-chair / audit
mapping would need a code change. Acceptable for v1; flag for v2.

### 4.2 Controller authorization surface

Scanned `AuditLogController`, `RegulatorExportController`,
`EIDASSignatureController` for `#[NoAdminRequired]` +
`#[NoCSRFRequired]` + `requireAdmin()` patterns:

- **AuditLogController** — 3 methods, all `#[NoAdminRequired]` +
  `requireAdmin()` guard at the top. **OK** — pattern matches
  hydra-gate-semantic-auth's expectation.
- **RegulatorExportController** — same pattern. **OK.**
- **EIDASSignatureController** — 4 methods, all
  `#[NoAdminRequired]`. **Risk R-4 (medium)** — see §2 above. No
  per-object membership guard.

**F-B3 (low).** No `#[NoCSRFRequired]` attributes were observed on
state-changing methods in any of the three controllers. NC's
SecurityMiddleware enforces CSRF on non-`#[NoCSRFRequired]`
endpoints; **OK.**

## 5. eIDAS QES integration

### 5.1 QSP delegation pattern

`EIDASSignatureService.php` L77-L137. Decidesk does NOT perform
QES cryptography itself; it delegates to openconnector's `e-sign`
Source which fronts a QSP (qualified Trust Service Provider). Every
delegation produces an audit-log entry via
`AuditLogService::record(action: 'signature', ...)`.

**Findings:**

- **F-C1 (info).** The QSP is an external dependency; the trust
  chain depends on the QSP's qualified-certificate hierarchy, which
  this internal review cannot verify. The external audit MUST
  include the QSP's qualified-status under EU 910/2014 Annex I.
- **F-C2 (low).** `initializeSigningRequest` does not enforce that
  the caller is a member of the `$signatories` list — see R-4.
- **F-C3 (info).** The audit-log entry captures
  `['phase' => 'initiate', 'signatories' => array_values($signatories)]`
  as the payload. Per F-A2 the payload is not hash-protected, so
  the signatory list could be retroactively rewritten in the audit
  table. Mitigated by §3.1 F-A2 recommendation.
- **F-C4 (info).** `verifySignature` accepts a base-64 signature
  blob (L152). Validation is delegated to the QSP; the local
  `LogEIDASSignatureService` captures the QSP response. The local
  service does not independently verify the QSP signature's X.509
  chain — that's correct for a QSP-delegated flow but should be
  documented for the external auditor.

### 5.2 eIDAS 2 readiness

`docs/compliance/board-portal-compliance.md` §2.3 documents eIDAS 2
readiness. Internal review confirms the QSP-delegation pattern is
compatible with the European Digital Identity Wallet rollout: the
QSP swap is a configuration change in the openconnector e-sign
Source, not a code change. **OK.**

## 6. Dependency posture

- `composer.lock` present; `composer audit` should be run on every
  CI build (hydra-gate-composer-audit). This review did NOT execute
  `composer audit` against a populated vendor tree (the worktree has
  no `vendor/`). Recommend the external auditor run a fresh audit
  + a Software Composition Analysis pass.
- PHP min-version 8.3 (info.xml L56) — supported per PHP support
  policy through 2027-12.
- NC min-version 28, max-version 34 (info.xml L55) — current NC
  support window.

## 7. Findings summary

| ID | Severity | Area | Recommendation | External-audit handoff |
|---|---|---|---|---|
| R-1 | Low | Audit-trail | Add DB-level trigger OR document continuous `verify()` monitoring | YES — auditor to evaluate trigger approach |
| R-2 | Low | RBAC | Default-deny semantic is already safe; document the `?? 'board-only'` fallback | NO — internal-review-cleared |
| R-3 | Info | Audit-trail perf | Consider checkpoint-anchored verification for >10-year tenancies | NO — future work |
| R-4 | **Medium** | EIDAS controller | Add `requireBoardMember($minutesId)` guard on `initializeSigningRequest` | YES — auditor to confirm severity |
| F-A1 | Info | Hash chain | Document PHP 8.3 insertion-order guarantee | NO |
| F-A2 | Low | Hash chain | Extend canonical payload to include payload-hash | YES — auditor to confirm |
| F-A3 | Info | Verify | Hard-fail on NULL currentHash | NO — internal-review-cleared |
| F-A4 | Info | Verify perf | Checkpoint-anchored verify | NO |
| F-B1 | Info | RBAC | Confirm vice-chairman exclusion from executive-only | NO — governance question |
| F-B2 | Low | RBAC | Make ACCESS_MATRIX tenant-configurable in v2 | NO — v2 backlog |
| F-B3 | Low | CSRF | NC default CSRF enforcement applies; documented | NO — cleared |
| F-C1 | Info | QSP | External audit must verify QSP qualified-status | **MANDATORY external** |
| F-C2 | Low | QSP | See R-4 | YES |
| F-C3 | Info | QSP | See F-A2 | YES |
| F-C4 | Info | QSP | Document QSP-delegation trust model | NO — cleared |

**Severity totals:** 0 critical, 0 high, 1 medium (R-4), 5 low, 7 info.

## 8. Remediation tracking

R-4 (medium) is the only finding this review proposes to address
in-codebase before the external audit. F-A2 (low) is recommended for
the same reason — it materially strengthens the hash-chain
guarantee. Both are tracked as follow-up tasks rather than blockers
for the external engagement.

- [ ] R-4 — add `requireBoardMember($minutesId)` guard on
      `EIDASSignatureController::initialize` (and equivalent on
      `notify`, `verify`, `dispatch` if they accept a `minutesId`).
- [ ] F-A2 — extend `AuditLogService` canonical payload to include
      `hash('sha256', json_encode($payload))` so business-payload
      tampering is detectable.

## 9. Handoff to external auditor

When the external engagement is commissioned (Computest /
Northwave / Madison Gurkha), this internal review is the starting
point. The auditor SHOULD receive:

1. This document (`docs/security/board-portal-internal-security-review.md`).
2. The compliance reference (`docs/compliance/board-portal-compliance.md`).
3. The architecture reference (`docs/Technical/board-portal-architecture.md`).
4. The audit-log unit-test suite
   (`tests/Unit/Service/AuditLogServiceTest.php`).
5. The internal-prep checklist (§6 of the compliance reference).

Findings letter + Decidesk response land in
`docs/compliance/audit-letters/YYYY-MM-<auditor-slug>.md`.

§10.10 of `board-meeting-resolutions/tasks.md` flips to `[x]`
once the external letter is on file. Until then the task is `[~]`
(tracking).

## 10. Review provenance

- **Method:** STRIDE walk + targeted code-review of three services
  + controller-attribute scan + RBAC matrix sanity-check +
  dependency-posture check. No live target, no penetration testing.
- **Reviewer:** internal engineering (Conduction).
- **Date:** 2026-06-12 (W33).
- **Reproducibility:** every finding cites a file path + line
  range; rerun with `grep -nE "NoAdminRequired|requireAdmin" lib/Controller/*.php`
  + `grep -nE "hash\(.sha256." lib/Service/*.php`.
- **Independence:** the reviewer is part of the same engineering
  team that wrote the code. The external audit (§10.10) is
  precisely the independence guarantee this review cannot supply.
