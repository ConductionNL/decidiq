---
kind: code
---

## Why

Four security-relevant HTTP endpoint groups are covered **only** by PHPUnit unit tests that
instantiate the controller class directly and call its methods in-process — never by a Playwright
e2e test that actually drives the request through Nextcloud's real HTTP stack (routing,
`SecurityMiddleware`, CSRF, `IUserSession` cookie auth). A unit test that does
`new EIDASSignatureController($request, $signatureService, $userSession, $authService)` and calls
`$controller->initiate(...)` directly proves the PHP logic is correct in isolation, but proves
nothing about whether the route is actually reachable, correctly annotated, or enforces auth the
way the real request pipeline enforces it (the exact gap `hydra-gate-semantic-auth` and
`hydra-gate-route-auth` exist to catch mechanically, but neither substitutes for an e2e request).

Confirmed routes with no e2e coverage:

- `appinfo/routes.php:175-178` — `eIDASSignature#initiate|verify|finalize|certStatus`
  (`/api/minutes/{minutesId}/eidas/*`, `/api/eidas/validate-cert`). Only
  `tests/Unit/Controller/EIDASSignatureControllerTest.php` (312 lines, direct controller
  instantiation) exists; `grep -rli eidas tests/e2e` returns nothing.
- `appinfo/routes.php:181-184` — `proxyVote#register|index|suspend|revoke`
  (`/api/proxies*`). Only `tests/Unit/Controller/ProxyVoteControllerTest.php` (210 lines, direct
  controller instantiation) exists; `grep -rli "proxy.vote\|proxyvote" tests/e2e` returns nothing.
  This is the exact endpoint group `board-proxy-vote-authorization-guard` (this app's own active
  change) is currently hardening for per-object IDOR — the authorization guard being added there
  will *also* only be unit-tested unless this gap is closed.
- `GovernanceReportController` / `RegulatorExportController` — both have
  `tests/Unit/Controller/*Test.php` only; no e2e spec references "governance report" or
  "regulator export" flows.

Per the fleet feedback rule "distinguish unit tests that call controllers directly (bypass
router/middleware) from e2e/Playwright that drive the real path" — this app has 28 e2e spec files
(`tests/e2e/**/*.spec.ts`) covering meetings, motions, voting, minutes, and settings UI flows, but
none reach the eIDAS signing flow or the proxy-vote delegation flow, both of which are
security/legal-consequence-bearing (a QES signature and a proxy-vote grant are legally
significant actions under Dutch governance rules).

This is not covered by any existing spec/change — `openspec/changes/board-proxy-vote-
authorization-guard/` hardens the *authorization logic* for proxy votes but does not add e2e
coverage; `openspec/changes/archive/2026-06-15-meeting-transcription-ai-minutes` is the only prior
change that added a security-adjacent e2e workflow spec (`meeting-transcription-workflow.spec.ts`)
demonstrating the pattern already exists in this codebase and can be followed here.

## What Changes

- Add a Playwright e2e spec exercising the proxy-vote delegation flow end-to-end (register a
  proxy as the grantor, attempt to revoke as an unrelated user and observe `403`, revoke as the
  grantor and observe success) through the real HTTP route, not a direct controller call.
- Add a Playwright e2e spec exercising at minimum the reachability + auth posture of the eIDAS
  signing endpoints (`initiate`/`certStatus`) — full QES flow requires an external signing
  provider so the e2e scope is: route is reachable, requires authentication, and rejects an
  unauthorized caller — not a full external-signature round-trip.
- Not BREAKING: additive test coverage only, no production code changes.
