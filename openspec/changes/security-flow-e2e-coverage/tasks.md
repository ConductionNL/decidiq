# Tasks: security-flow-e2e-coverage

## Implementation Tasks

### Task 1: Proxy-vote delegation e2e spec
- **spec_ref**: `openspec/changes/security-flow-e2e-coverage/specs/security-sensitive-e2e-coverage/spec.md#requirement-req-sfec-001-proxy-vote-delegation-must-have-a-real-http-e2e-test`
- **files**: `tests/e2e/proxy-vote-delegation.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN two seeded meeting participants A and B THEN a Playwright test, driving the real app UI
    or `page.request` against `/api/proxies`, registers a proxy with A as grantor and B as holder
    and asserts `201`/success
  - GIVEN the registered proxy THEN a request to revoke it as an unrelated third participant
    asserts `403 Forbidden`
  - GIVEN the registered proxy THEN a request to revoke it as the grantor (A) asserts success
  - Follow the existing `test.skip(condition, reason)` pattern used across
    `tests/e2e/spec-coverage/*.spec.ts` for environments where proxy-vote fixtures are not seeded
- [ ] Add `tests/e2e/proxy-vote-delegation.spec.ts` covering register / unauthorized-revoke-403 /
      authorized-revoke-success against the real `/api/proxies*` routes.
- [ ] Tag scenarios with `@e2e openspec/changes/security-flow-e2e-coverage/specs/security-
      sensitive-e2e-coverage/spec.md#...` per this app's gate-19 convention.

### Task 2: eIDAS signing-endpoint reachability + auth-posture e2e spec
- **spec_ref**: `openspec/changes/security-flow-e2e-coverage/specs/security-sensitive-e2e-coverage/spec.md#requirement-req-sfec-002-eidas-endpoints-must-have-a-real-http-e2e-reachability-and-auth-test`
- **files**: `tests/e2e/eidas-signature-endpoints.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN an authenticated session THEN `POST /api/eidas/validate-cert` is reachable (not a router
    404) and responds according to its documented contract (success or a domain error, not a
    framework-level auth rejection)
  - GIVEN no session / an unauthenticated request THEN the eIDAS endpoints reject the request
    (this proves `#[NoAdminRequired]` — or whichever attribute is present — is actually enforced by
    the real middleware chain, which a direct-controller-call unit test cannot prove)
  - Full external QES provider round-trip (`initiate` → real signing → `finalize`) is explicitly
    OUT OF SCOPE for this e2e spec (requires a live signing provider); scope is reachability + auth
    posture only
- [ ] Add `tests/e2e/eidas-signature-endpoints.spec.ts` covering the reachability + auth-posture
      scenarios above via `page.request` against the real routes.
- [ ] Tag scenarios with `@e2e ...` per gate-19 convention.

### Task 3: Governance-report and regulator-export e2e reachability spec
- **spec_ref**: `openspec/changes/security-flow-e2e-coverage/specs/security-sensitive-e2e-coverage/spec.md#requirement-req-sfec-003-governance-report-and-regulator-export-routes-must-have-a-real-http-e2e-test`
- **files**: `tests/e2e/governance-regulator-exports.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN an authenticated caller with appropriate role THEN `POST /api/governance-reports` and
    `POST /api/regulator-exports` are reachable and produce a downloadable artifact reference
  - GIVEN an unauthenticated request THEN both are rejected by the real middleware chain
- [ ] Add `tests/e2e/governance-regulator-exports.spec.ts` covering the scenarios above.
- [ ] Tag scenarios with `@e2e ...` per gate-19 convention.
