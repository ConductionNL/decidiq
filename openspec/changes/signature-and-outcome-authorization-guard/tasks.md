# Tasks: signature-and-outcome-authorization-guard

## Implementation Tasks

### Task 1: One shared "is this actor a signatory on these minutes" determination
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes`
- **files**: `lib/Service/GovernanceScopeGuard.php`
- **acceptance_criteria**:
  - GIVEN a Nextcloud UID and a Minutes UUID THEN the guard resolves Minutes -> Meeting ->
    GovernanceBody and answers membership of `decidesk:body:{bodyId}:signatory`
  - GIVEN any hop is unresolvable, the scope group is empty, or OpenRegister throws THEN the guard
    denies (fail-closed) and logs
  - GIVEN `canInitiateSigning()` THEN it produces the identical verdict, so `initiate()`,
    `verify()` and `finalize()` cannot drift apart
- [x] Extract the body of `canInitiateSigning()` into a public `isSignatoryForMinutes()`.
- [x] Make `canInitiateSigning()` delegate to it (no second implementation).

### Task 2: Guard `finalize()` — the endpoint that affixes the signature
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes`
- **files**: `lib/Controller/EIDASSignatureController.php`
- **acceptance_criteria**:
  - GIVEN a caller in the Minutes' body signatory scope THEN `finalizeMinutes()` runs (`200 OK`)
  - GIVEN a caller outside that scope THEN the response is `403 Forbidden` and
    `finalizeMinutes()` is NEVER invoked — no Minutes row write, no stage resolution, no audit entry
- [x] Call `isSignatoryForMinutes()` before reading `signatures` and return `403` on denial.

### Task 3: Guard `verify()`
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-102-only-a-body-signatory-may-verify-a-signature-on-a-minutes-record`
- **files**: `lib/Controller/EIDASSignatureController.php`
- **acceptance_criteria**:
  - GIVEN a caller in the Minutes' body signatory scope THEN the verdict is returned (`200 OK`)
  - GIVEN a caller outside that scope THEN `403 Forbidden` and `verifySignature()` is never invoked
  - GIVEN the guard passes but `requestId`/`signature` are missing THEN the existing `422` still applies
- [x] Call `isSignatoryForMinutes()` before the parameter validation and return `403` on denial.

### Task 4: Record `certStatus()` as deliberately app-wide
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-103-certificate-trust-status-lookup-is-a-deliberately-app-wide-authenticated-read`
- **files**: `lib/Controller/EIDASSignatureController.php`
- **acceptance_criteria**:
  - GIVEN the method docblock THEN it carries a reason-bearing `@no-admin-idor-exempt` tag naming
    the two facts that make it safe: no caller-supplied object identifier, and a response sourced
    entirely from the public EU Trusted List
  - GIVEN an authenticated caller THEN the endpoint still returns `200` (the Newman contract in
    `tests/integration/decidesk-security-flow-e2e.postman_collection.json` folder 2 is unchanged)
- [x] Add the reason-bearing exemption tag and the supporting docblock note.

### Task 5: Guard `getOutcome()` on the Decision's raiser / publication state
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope`
- **files**: `lib/Service/DecisionIntegrationService.php`, `lib/Controller/IntegrationController.php`
- **acceptance_criteria**:
  - GIVEN the caller is the Decision's `@self.owner` THEN the envelope is returned (`200 OK`)
  - GIVEN `isPublished === 'public'` THEN any authenticated caller gets the envelope (`200 OK`)
  - GIVEN a Nextcloud admin THEN the envelope is returned (`$callerUid = null` bypass)
  - GIVEN none of the above THEN `403 Forbidden` and no envelope is assembled
  - GIVEN the Decision does not exist THEN the guard allows and the assembler still answers `404`
  - GIVEN OpenRegister is unavailable or `find()` throws THEN the guard DENIES (fail-closed)
- [x] Add public `isAuthorizedToReadOutcome()` to `DecisionIntegrationService`.
- [x] Add `resolveCallerUid()` (null on admin) to `IntegrationController` via `IUserSession` +
      `IGroupManager` and check the guard before calling `getOutcomeEnvelope()`.
- [x] Remove the false "per-object read access is enforced by OpenRegister RBAC" claim from the
      controller class docblock, the method docblock and `getOutcomeEnvelope()`.

### Task 6: Register `authorization` block for the `Decision` schema (ORCHESTRATOR APPLIES)
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `Decision` schema THEN anonymous (`public`) read is narrowed to decisions the app
    has actually published, instead of inheriting the register baseline
    (`read`/`list`: `["authenticated", "public"]`) which grants anonymous read of every draft
  - GIVEN an authenticated caller THEN read/list are unchanged, so the controller guard above and
    every existing in-app Decision reader keep working
- [ ] **NOT APPLIED HERE** — `lib/Settings/decidesk_register.json` is single-writer this session.
      The orchestrator applies this block to `components.schemas.Decision`, alongside the
      `_authorizationNote` convention already used by `ConflictOfInterest`:

      ```json
      "_authorizationNote": "Decision previously declared no schema-level authorization block, so it fell back to the decidesk register baseline (read/list open to 'public' + 'authenticated'). That made every DRAFT decision — including the cross-app subject coordinates and externalReference the contract-decision hub writes onto it (REQ-DCDH-001) — anonymously readable through OpenRegister's generic object API. Decisions ARE meant to be publicly readable, but only once the app has published them, which the app records in isPublished. This block therefore keeps anonymous read for isPublished=public only and leaves authenticated read unchanged, mirroring ParticipatoryBudget / PublicConsultation / BoardEvaluation in this same file. No write action is named, so the baseline still governs create/update/delete and nothing this app writes needs _rbac:false. The per-object rule for the outcome endpoint lives in DecisionIntegrationService::isAuthorizedToReadOutcome() (REQ-DCDH-101); this block is defence in depth on the anonymous surface, not that guard.",
      "authorization": {
        "read": [
          { "group": "public", "match": { "isPublished": "public" } },
          "authenticated"
        ],
        "list": [
          { "group": "public", "match": { "isPublished": "public" } },
          "authenticated"
        ]
      }
      ```

      **Two follow-ups the orchestrator must apply with it:**
      1. `tests/Unit/RegisterAuthorizationTest::testSchemasWithTheirOwnBlockStillDeclareOnlyReads()`
         pins the number of schema-level blocks at **25**. Adding this one makes it **26** — bump
         the constant and its message, or the suite goes red. (That count is the test's positive
         control; do not delete it.)
      2. Bump the `Decision` schema `version` (and the register version / app version if the
         repair step needs to re-run), exactly as the `ConflictOfInterest` block did — an
         annotation-only schema change that does not move the version never deploys.

      The controller guard in Task 5 does **not** depend on this block; it is a separate,
      anonymous-surface tightening and can ship independently.

### Task 7: Regression coverage, both directions
- **spec_ref**: `openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes`
- **files**: `tests/Unit/Controller/EIDASSignatureControllerTest.php`,
  `tests/Unit/Controller/IntegrationControllerTest.php`
- **acceptance_criteria**:
  - GIVEN each newly guarded endpoint THEN there is a test proving an unauthorized caller gets
    `403` AND the service is never invoked, and a test proving an authorized caller still succeeds
  - GIVEN `getOutcome()` THEN owner-allow, published-allow, admin-allow, unrelated-deny and
    still-404-on-missing are all covered
  - GIVEN `certStatus()` THEN a non-admin authenticated caller still gets `200`, proving the
    documented app-wide posture is real and not accidental
- [x] Extend both controller test suites with the allow/deny matrix.

## Out of Scope (reported, not changed)

- `IntegrationController::subscribe()` has the same caller-supplied-Decision-UUID shape as
  `getOutcome()` and writes `outcomeCallbackUrl` onto the target Decision. Gate-7 clears it only
  because an unrelated `Http::STATUS_FORBIDDEN` (the anti-SSRF `ssrf_rejected` mapping) appears in
  its body. It is outside this change's four assigned findings and should get REQ-DCDH-101's rule
  in a follow-up.
- `certStatus()` lets any authenticated user drive repeated outbound calls to the configured QSP.
  That is a rate/cost surface, not an access-control one; no throttle exists today.
