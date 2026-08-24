---
status: proposed
---

# Decidesk AppHost Adoption

## Purpose

Decidesk serves its health, metrics, dashboard, preferences, and settings plumbing through the OpenRegister AppHost (declarative observability engine + generic boilerplate controllers), preserving the REQ-API-004 reverse-proxy health contract (always HTTP 200, public, CORS-enabled) and the legacy `/api/v1/health` URL, while gaining a previously missing ADR-006-compliant metrics endpoint.

**Cross-references**:
- `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md` (engine, `always200`/`cors` knobs, `orAvailable` check)
- `openregister/openspec/changes/apphost-boilerplate-controllers/` (Bootstrap, Routes::standard, generic controllers, override pattern)
- Legacy contract: `openspec/changes/archive/2026-05-11-p4-integration/specs/p4-integration/spec.md` — "Requirement: REQ-API-004 — API health check"

---

## ADDED Requirements

### Requirement: Declarative Health with always200 + CORS Policy Knobs

Decidesk SHALL declare its health endpoint in `src/manifest.json` as `observability.health = { statusCodePolicy: "always200", cors: true, checks: [{ id: "openregister", type: "orAvailable" }] }`, executed by the AppHost engine. The endpoint SHALL remain public (no authentication) and SHALL preserve the REQ-API-004 contract: reverse-proxy probes MUST succeed (HTTP 200) even when the app is degraded.

#### Scenario: Health returns HTTP 200 while OpenRegister is down (REQ-API-004 parity)

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** the OpenRegister app is disabled or its ObjectService cannot be resolved
- **WHEN** `GET /apps/decidesk/api/v1/health` is requested anonymously
- **THEN** the response MUST be HTTP 200 (never 503, per `statusCodePolicy: "always200"`) with `status: "degraded"` and the OpenRegister check reported as failed/unavailable

#### Scenario: Healthy instance reports ok

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** OpenRegister is enabled and resolvable
- **WHEN** `GET /apps/decidesk/api/v1/health` is requested anonymously
- **THEN** the response MUST be HTTP 200 with `status: "ok"` and the OpenRegister check reported healthy

#### Scenario: CORS headers present on health responses

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidesk/api/v1/health` is requested
- **THEN** the response MUST carry `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods` (GET, OPTIONS), and `Access-Control-Allow-Headers` headers, supplied by the engine's `cors: true` knob

#### Scenario: OPTIONS preflight succeeds

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `OPTIONS /apps/decidesk/api/v1/health` is requested
- **THEN** the response MUST be HTTP 200 with the same `Access-Control-*` headers and no authentication required

---

### Requirement: Health Response-Shape Adjudication

The health response body today carries non-standard fields `baseUrl` (from `overwrite.cli.url`, for reverse-proxy base-URL verification per REQ-API-004) and a flattened `openregister: "connected"|"unavailable"`, which differ from the engine's standard shape (`{ status, app, version, checks: {...} }`). The adoption SHALL resolve this by exactly one of:

- **Option A (preferred)**: all probe consumers are inventoried and migrated to the engine's standard shape; the local `HealthController` is deleted entirely.
- **Option B (fallback)**: decidesk keeps a thin `HealthController` subclass of the AppHost generic, overriding ONLY the documented response-shaping hook to append `baseUrl` and the flattened `openregister` field; check execution, status-code policy, and CORS still come from the engine.

The choice SHALL be made on the evidence of an actual probe-consumer inventory (who reads `baseUrl`/`openregister`); Option B is mandatory if any consumer outside decidesk's control depends on those fields.

#### Scenario: Option A — consumers migrated to standard shape

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** the consumer inventory shows every reader of `baseUrl`/`openregister` is under our control
- **WHEN** the adoption lands
- **THEN** the health body MUST be the engine standard shape, every inventoried consumer MUST be migrated in the same change, and `lib/Controller/HealthController.php` MUST be deleted

#### Scenario: Option B — response-shaping override preserves legacy fields

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** the consumer inventory shows an external consumer reads `baseUrl` or `openregister`
- **WHEN** `GET /apps/decidesk/api/v1/health` is requested after adoption
- **THEN** the body MUST be byte-compatible with the pre-adoption baseline (`status`, `baseUrl`, `version`, `openregister`), produced by a subclass overriding only the AppHost response-shaping hook

---

### Requirement: Legacy Health URL Alias

The pre-adoption health route is `GET|OPTIONS /apps/decidesk/api/v1/health` (route names `health#status`/`health#statusOptions`), not the AppHost-standard `/api/health`. Decidesk SHALL keep `/api/v1/health` (both verbs) as `$extra` alias routes targeting the AppHost health handling for a documented deprecation window, in addition to exposing the standard `/api/health`. Alias removal SHALL be a tracked follow-up gated on consumer migration.

#### Scenario: Legacy and standard URLs serve identical health responses

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidesk/api/v1/health` and `GET /apps/decidesk/api/health` are both requested
- **THEN** both MUST return HTTP 200 with identical bodies and CORS headers

---

### Requirement: Implicit Metrics Endpoint (Compliance Upgrade)

Decidesk has no metrics endpoint today. Through AppHost adoption it SHALL gain `GET /apps/decidesk/api/metrics` serving the implicit `decidesk_info` (version, php_version, nextcloud_version labels) and `decidesk_up` metrics in Prometheus text format 0.0.4, with no `metrics[]` descriptors declared. The endpoint SHALL be admin-only per ADR-006.

#### Scenario: Implicit metrics served to admin

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidesk/api/metrics` is requested by an admin
- **THEN** the response MUST be Prometheus text 0.0.4 containing `decidesk_info` and `decidesk_up` with `# HELP`/`# TYPE` lines

#### Scenario: Metrics rejected for non-admin

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidesk/api/metrics` is requested unauthenticated or as a non-admin user
- **THEN** the request MUST be rejected (no metric data in the response body)

---

### Requirement: Boilerplate Delegation

Decidesk SHALL serve its dashboard (SPA page + catch-all), preferences, and settings endpoints through the AppHost generic controllers via `Bootstrap::register()` aliases and `Routes::standard($extra)`, deleting `DashboardController`, `PreferencesController`, `SettingsController`, `SettingsService`, and `DeepLinkRegistrationListener`, and shrinking `AdminSettings`, `SettingsSection`, and `InitializeSettings` to one-line app-namespace subclass stubs. Route names, URLs, verbs, and response shapes SHALL remain bit-compatible; decidesk's domain routes (boards, motions, voting, minutes, ORI/v1 public API, …) move into `$extra` with their ordering invariants preserved (specific routes before wildcards and the SPA catch-all).

#### Scenario: SPA and settings surfaces unchanged after adoption

- **GIVEN** a logged-in user on a deployed instance after adoption
- **WHEN** they open the Decidesk app, navigate to a deep link, and an admin opens the Decidesk admin settings section
- **THEN** the SPA MUST load via the generic dashboard controller (page + catch-all), deep links MUST resolve, and the admin settings form MUST load and save exactly as before

#### Scenario: Preferences round-trip via generic controller

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** a user sets a preference via `PUT /api/preferences/{key}` and reads it back via `GET /api/preferences/{key}`
- **THEN** the value MUST round-trip identically to pre-adoption behaviour, with previously written preference keys still resolving
