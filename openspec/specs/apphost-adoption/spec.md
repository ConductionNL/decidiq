# Decidiq AppHost Adoption

## Purpose

Decidiq serves its health, metrics, and dashboard plumbing through the OpenRegister AppHost (declarative observability engine + generic boilerplate controllers), preserving the REQ-API-004 reverse-proxy health contract (always HTTP 200, public, CORS-enabled) and the legacy `/api/v1/health` URL, while gaining a previously missing ADR-006-compliant metrics endpoint. Domain-entangled settings/preferences/repair plumbing is deliberately kept bespoke where the AppHost generics cannot express decidiq's richer behaviour.

**Cross-references**:
- `openregister/lib/AppHost/Observability/` (engine, `always200`/`cors` knobs, `orAvailable` check, `ManifestLoader`/`HealthCheckExecutor`/`MetricsEngine`)
- `openregister/lib/AppHost/` (`Routes::standard`, `GenericDashboardController`/`GenericHealthController`/`GenericMetricsController`/`GenericDeepLinkRegistrationListener`, override pattern)
- Legacy contract: `openspec/changes/archive/2026-05-11-p4-integration/specs/p4-integration/spec.md` — "Requirement: REQ-API-004 — API health check"

---

## Requirements

### Requirement: Declarative Health with always200 + CORS Policy Knobs

Decidiq SHALL declare its health endpoint in `src/manifest.json` as `observability.health = { statusCodePolicy: "always200", cors: true, checks: [{ id: "openregister", type: "orAvailable" }] }`, executed by the AppHost engine. The endpoint SHALL remain public (no authentication) and SHALL preserve the REQ-API-004 contract: reverse-proxy probes MUST succeed (HTTP 200) even when the app is degraded.

#### Scenario: Health returns HTTP 200 while OpenRegister is down (REQ-API-004 parity)

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** the OpenRegister app is disabled or its ObjectService cannot be resolved
- **WHEN** `GET /apps/decidiq/api/v1/health` is requested anonymously
- **THEN** the response MUST be HTTP 200 (never 503, per `statusCodePolicy: "always200"`) with `status: "degraded"` and `openregister: "unavailable"`

#### Scenario: Healthy instance reports ok

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **GIVEN** OpenRegister is enabled and resolvable
- **WHEN** `GET /apps/decidiq/api/v1/health` is requested anonymously
- **THEN** the response MUST be HTTP 200 with `status: "ok"` and `openregister: "connected"`

#### Scenario: CORS headers present on health responses

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidiq/api/v1/health` is requested
- **THEN** the response MUST carry `Access-Control-Allow-Origin` (configured origin), `Access-Control-Allow-Methods` (GET, OPTIONS), and `Access-Control-Allow-Headers` headers

#### Scenario: OPTIONS preflight succeeds

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `OPTIONS /apps/decidiq/api/v1/health` is requested
- **THEN** the response MUST be HTTP 200 with the same `Access-Control-*` headers and no authentication required

---

### Requirement: Health Response-Shape Adjudication

Decidiq SHALL preserve the published REQ-API-004 health body (`{status, baseUrl, version, openregister}`) byte-for-byte via **Option B**: a thin `HealthController` subclass of `GenericHealthController` that runs the engine checks then reshapes the result into the legacy body and re-applies the historical 3-header CORS. The body carries non-standard fields `baseUrl` (from `overwrite.cli.url`, for reverse-proxy base-URL verification) and a flattened `openregister: "connected"|"unavailable"` that differ from the engine's standard shape; the probe-consumer inventory found no external reader of those fields, but REQ-API-004 is a published operator-facing contract, so the legacy shape MUST be retained. Check execution, the always-200 policy, and the public auth posture come from the engine.

#### Scenario: Response-shaping subclass preserves legacy fields

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidiq/api/v1/health` is requested after adoption
- **THEN** the body MUST be byte-compatible with the pre-adoption baseline (`status`, `baseUrl`, `version`, `openregister`), produced by a `GenericHealthController` subclass that reshapes the engine result

---

### Requirement: Legacy Health URL Alias

The pre-adoption health route is `GET|OPTIONS /apps/decidiq/api/v1/health` (route names `health#status`/`health#statusOptions`), not the AppHost-standard `/api/health`. Decidiq SHALL keep `/api/v1/health` (both verbs) as `$extra` alias routes targeting the decidiq `HealthController` for a documented deprecation window, in addition to exposing the standard `/api/health` (route `health#index`). Alias removal SHALL be a tracked follow-up gated on consumer migration.

#### Scenario: Legacy and standard URLs serve identical health responses

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidiq/api/v1/health` and `GET /apps/decidiq/api/health` are both requested
- **THEN** both MUST return HTTP 200 with byte-compatible bodies and CORS headers

---

### Requirement: Implicit Metrics Endpoint (Compliance Upgrade)

Decidiq has no metrics endpoint today. Through AppHost adoption it SHALL gain `GET /apps/decidiq/api/metrics` (route `metrics#index`, served by a thin `GenericMetricsController` subclass) serving the implicit `decidesk_info` (version, php_version, nextcloud_version labels) and `decidesk_up` metrics in Prometheus text format 0.0.4, with no `metrics[]` descriptors declared. The endpoint SHALL be admin-only per ADR-006.

#### Scenario: Implicit metrics served to admin

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidiq/api/metrics` is requested by an admin
- **THEN** the response MUST be Prometheus text 0.0.4 containing `decidesk_info` and `decidesk_up` with `# HELP`/`# TYPE` lines

#### Scenario: Metrics rejected for non-admin

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** `GET /apps/decidiq/api/metrics` is requested unauthenticated or as a non-admin user
- **THEN** the request MUST be rejected (no metric data in the response body)

---

### Requirement: Boilerplate Delegation

Decidiq SHALL serve its dashboard (SPA page + catch-all) endpoint through the AppHost generic via a thin `DashboardController` subclass, and SHALL use `Routes::standard($extra)` for the canonical route table — the canonical `dashboard#page`/`dashboard#catchAll`, `settings#index|create|load`, `preferences#getPreference|setPreference`, `health#index`, and `metrics#index` come from `standard()`, with decidiq's domain routes (boards, motions, voting, minutes, ORI/v1 public API, …) appended via `$extra` with their ordering invariants preserved (specific routes before wildcards and the SPA catch-all). Deep-link patterns SHALL be declared in the manifest `deepLinks` block and registered by the generic `GenericDeepLinkRegistrationListener`, replacing the hand-written listener.

Domain-entangled plumbing SHALL remain bespoke where the generics cannot express decidiq's behaviour: `SettingsController` + `SettingsService` (decidesk-register import + publication-config CRUD), `AdminSettings` (domain initial state), `SettingsSection`, Personal settings, `InitializeSettings` (voter_token_secret seeding + OR config import), and `PreferencesController` (no `GenericPreferencesController` exists in OpenRegister). Route names, URLs, verbs, and response shapes SHALL remain bit-compatible.

#### Scenario: SPA and settings surfaces unchanged after adoption

- **GIVEN** a logged-in user on a deployed instance after adoption
- **WHEN** they open the Decidiq app, navigate to a deep link, and an admin opens the Decidiq admin settings section
- **THEN** the SPA MUST load via the generic dashboard controller (page + catch-all), deep links MUST resolve via the manifest `deepLinks` block, and the bespoke admin settings form MUST load and save exactly as before

#### Scenario: Preferences round-trip via the retained controller

- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
- **WHEN** a user sets a preference via `PUT /api/preferences/{key}` and reads it back via `GET /api/preferences/{key}`
- **THEN** the value MUST round-trip identically to pre-adoption behaviour, with previously written preference keys still resolving
