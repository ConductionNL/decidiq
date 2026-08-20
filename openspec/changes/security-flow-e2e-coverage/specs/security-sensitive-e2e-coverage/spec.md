## ADDED Requirements

### Requirement: REQ-SFEC-001 Proxy-vote delegation MUST have a real HTTP e2e test
The proxy-vote delegation flow (`proxyVote#register`, `#suspend`, `#revoke` — `appinfo/routes.php:181-184`) MUST be exercised by at least one Playwright e2e test that drives the request through the real Nextcloud HTTP + auth stack, in addition to any PHPUnit unit test that calls the controller directly.

#### Scenario: Grantor registers and later revokes their own proxy over real HTTP

@e2e exclude API/authorization scenario, not a UI flow (feedback_playwright-ui-only-newman-api); covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 1 ("Register proxy as grantor", "Revoke proxy as grantor succeeds") — real HTTP through the actual route, not a direct controller call.

- **GIVEN** two seeded meeting participants A (grantor) and B (holder)
- **WHEN** a Newman test issues `POST /api/proxies` as A, then `DELETE /api/proxies/{id}`
  as A (the actual revoke route per `appinfo/routes.php`)
- **THEN** both requests succeed against the real route (not a direct controller call)

#### Scenario: Unrelated participant cannot revoke another member's proxy over real HTTP

@e2e exclude API/authorization scenario, not a UI flow; covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 1 ("Revoke proxy as unrelated user is rejected (authz: 403, unchanged)").

- **GIVEN** a proxy registered between A and B
- **WHEN** a Newman test issues `DELETE /api/proxies/{id}` authenticated as an unrelated
  participant C
- **THEN** the real HTTP response is `403 Forbidden` and the proxy's status is unchanged

### Requirement: REQ-SFEC-002 eIDAS endpoints MUST have a real HTTP e2e reachability and auth test
The eIDAS QES signing endpoints (`eIDASSignature#initiate|verify|finalize|certStatus` — `appinfo/routes.php:175-178`) MUST be exercised by at least one Playwright e2e test asserting route reachability and authentication enforcement through the real middleware chain. A full external-provider signing round-trip is out of scope.

#### Scenario: Authenticated caller can reach the certificate-status endpoint

@e2e exclude API reachability/auth-posture scenario, not a UI flow; covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 2 ("certStatus reachable for an authenticated caller").

- **GIVEN** an authenticated Nextcloud session
- **WHEN** a Newman test issues `POST /api/eidas/validate-cert`
- **THEN** the route responds according to its documented contract (not a router 404, not a
  framework auth rejection)

#### Scenario: Unauthenticated request is rejected by the real middleware

@e2e exclude API auth-posture scenario, not a UI flow; covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 2 ("certStatus rejects an unauthenticated request").

- **GIVEN** no authenticated session
- **WHEN** a Newman test issues a request to any eIDAS endpoint
- **THEN** the real Nextcloud auth middleware rejects the request before the controller logic runs

### Requirement: REQ-SFEC-003 Governance-report and regulator-export routes MUST have a real HTTP e2e test
The `governanceReport#*` (`appinfo/routes.php:187-190`) and `regulatorExport#*` (`appinfo/routes.php:193-195`) routes MUST be exercised by at least one Playwright e2e test through the real HTTP + auth stack.

#### Scenario: Authorized caller generates a governance report and a regulator export

@e2e exclude API reachability scenario, not a UI flow; covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 3 ("Generate governance report as admin", "Generate regulator export as admin").

- **GIVEN** an authenticated caller with an appropriate role
- **WHEN** a Newman test issues `POST /api/governance-reports` and `POST
  /api/regulator-exports`
- **THEN** both requests succeed and return a reference to the generated artifact

#### Scenario: Unauthenticated request is rejected

@e2e exclude API auth-posture scenario, not a UI flow; covered by tests/integration/decidesk-security-flow-e2e.postman_collection.json folder 3 (the two "unauthenticated (401)" requests).

- **GIVEN** no authenticated session
- **WHEN** a Newman test issues a request to either route group
- **THEN** the real middleware chain rejects the request
