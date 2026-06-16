# Tasks: Decidesk Adopts OpenRegister AppHost

> **Decision record (tasks 0.2 / 0.3) — Option B chosen.**
> Baseline (task 0.1) captured on the live :8080 instance:
> `GET /api/v1/health` → HTTP 200 `{"status":"ok","baseUrl":"http://localhost","version":"0.3.0","openregister":"connected"}`
> with `Access-Control-Allow-Origin: http://localhost`, `Allow-Methods: GET, OPTIONS`,
> `Allow-Headers: Authorization, Content-Type, X-Requested-With`. `/api/metrics` and the
> canonical `/api/health` both returned 401 (no real endpoint — SPA catch-all), confirming no
> metrics endpoint existed pre-adoption. Fixtures stored under
> `tests/fixtures/apphost-parity/`.
>
> **Probe-consumer inventory (task 0.2):** no external consumer reads `baseUrl` or the flattened
> `openregister` field. The `baseUrl` grep hits are all Postman collection variables (test
> harness, unrelated to the health body). No K8s/compose/reverse-proxy probe config, no frontend
> code, and no external monitoring reads the health response. The only references to the shape
> are the published **REQ-API-004** contract (`openspec/.../archive/.../p4-integration/...`) and
> the design doc — a documented external promise for reverse-proxy verification by operators.
>
> **Adjudication (task 0.3):** the engine's standard body `{status, app, version, checks}` does
> NOT carry `baseUrl` or a flattened `openregister`, and its CORS emits only `*` + 2 headers (vs
> decidesk's configured-origin + 3 headers). Because REQ-API-004 is a published operator-facing
> contract and the byte-for-byte difference is real, **Option B** is chosen: a thin
> `HealthController` subclass of `GenericHealthController` runs the engine checks then reshapes the
> result into the REQ-API-004 body and re-applies the historical 3-header CORS. The always-200
> policy, `orAvailable` check, and public auth posture all come from the engine. No external
> consumer migration needed.

## 0. Baseline + probe-consumer inventory

- [x] 0.1 Baseline captured (see decision record above); fixtures in `tests/fixtures/apphost-parity/`. `/api/metrics` confirmed absent (401 SPA catch-all) pre-adoption.
- [x] 0.2 Probe-consumer inventory complete: no external reader of `baseUrl`/`openregister` (see decision record).
- [x] 0.3 Option B chosen + recorded (decision record above).

## 1. Manifest observability block

- [x] 1.1 Added `observability` block to `src/manifest.json`: `health = { statusCodePolicy: "always200", cors: true, checks: [ { id: "openregister", type: "orAvailable" } ] }`; no `metrics[]` (implicit `decidesk_info`/`decidesk_up` only). Also added the `deepLinks` block (migrated from the deleted hand-written listener).
- [x] 1.2 Manifest JSON valid. **Gate-22 note:** the installed `@conduction/nextcloud-vue` (beta.108) ships a v2 UI schema with `additionalProperties:false` that predates the AppHost `observability`/`deepLinks` blocks (ADR-040), and its `validateManifest` is not node-requireable, so gate-22 fails open (skips) per its documented schema-lag behaviour. The `observability`/`deepLinks` blocks are engine-read (OpenRegister `ManifestLoader` / `GenericDeepLinkRegistrationListener`), not UI-renderer blocks — same as OpenRegister's own dogfood manifest.

## 2. Wiring, deletions, legacy-route alias

- [x] 2.1 Added `Application::registerAppHostBoilerplate()`. Removed the hand-written `DeepLinkRegistrationListener` event wiring; wired the generic manifest-driven `GenericDeepLinkRegistrationListener` instead. Dashboard/Metrics/Health route targets are thin concrete subclasses of the AppHost generics (DI auto-resolves their engine deps). Domain `registerService` calls untouched. NOTE: full `Bootstrap::register()` was deliberately NOT used — it references a `GenericPreferencesController` that does not exist in OpenRegister development, and it would alias away decidesk's domain `SettingsService`/`AdminSettings`/`InitializeSettings`. The direct service-alias path (the same one OpenRegister itself dogfoods) was used instead.
- [x] 2.2 `appinfo/routes.php` now `return \OCA\OpenRegister\AppHost\Routes::standard($extra)`; all decidesk domain routes moved into `$extra`, ordered before the SPA catch-all. The canonical `dashboard#page`/`dashboard#catchAll`, `settings#index|create|load`, `preferences#getPreference|setPreference`, `health#index`, `metrics#index` come from `standard()`.
- [x] 2.3 **Legacy-route alias**: kept `GET /api/v1/health` (`health#status`) + `OPTIONS /api/v1/health` (`health#statusOptions`) in `$extra`, routed to the decidesk `HealthController` (Option-B subclass) which delegates to `index()`. Deprecation-window follow-up: remove the `/api/v1/health` alias once the inventory confirms all consumers use `/api/health` (tracked in proposal "Dependencies"/follow-up).
- [x] 2.4 KEPT bespoke (domain-entangled — generics do not fit, per "don't force"): `Controller\SettingsController` + `Service\SettingsService` (decidesk-register import + publication-config CRUD), `Settings\AdminSettings` (domain initial state), `Sections\SettingsSection`, `Settings\PersonalSettings` + `Sections\PersonalSection`, `Repair\InitializeSettings` (voter_token_secret + OR config import), `Controller\PreferencesController` (no GenericPreferencesController in OR). info.xml `<settings>`/`<repair-steps>` unchanged.
- [x] 2.5 Applied Option B: `HealthController` shrunk to a thin `GenericHealthController` subclass that reshapes to the REQ-API-004 body. Deleted `lib/Listener/DeepLinkRegistrationListener.php` (patterns → manifest `deepLinks`). `DashboardController` → thin `GenericDashboardController` subclass. Added `MetricsController` → thin `GenericMetricsController` subclass (new endpoint).
- [x] 2.6 Swept references: deleted `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php` (covered the deleted bespoke listener; generic listener is OR-owned + OR-tested). No other refs to the deleted class.

## 3. Verification

- [ ] 3.1 **Always-200 preserved (REQ-API-004 parity)**: verified against the live instance (OR enabled → 200 `status: ok`); OR-disabled degraded path reasoned from the engine `always200` policy + `orAvailable` failed→`status: degraded` + flattened `openregister: unavailable`.
- [ ] 3.2 **CORS preserved**: the Option-B subclass re-applies the historical 3-header CORS with the configured origin; OPTIONS preflight kept via `health#statusOptions`.
- [ ] 3.3 **Shape parity (Option B)**: body byte-compatible with the 0.1 baseline fixture (`status`, `baseUrl`, `version`, `openregister`).
- [ ] 3.4 **Metrics compliance upgrade**: `GET /api/metrics` as admin returns Prometheus 0.0.4 with `decidesk_info` + `decidesk_up`; unauthenticated/non-admin rejected (engine-owned posture, no `#[NoAdminRequired]`).
- [ ] 3.5 PHPUnit touched tests green; no NEW failures vs the ~98-error baseline.

## 4. Docs

- [ ] 4.1 Update decidesk docs: health endpoint contract page (always-200 + CORS via manifest knobs; legacy `/api/v1/health` alias + deprecation window; Option-B body), new metrics endpoint, AppHost adoption note.

## 5. Quality gates

- [ ] 5.1 Hydra gates diff-clean (gate-14 route-reachability after the routes.php rewrite, gate-16 `@spec` coverage on changed methods, gate-22 fail-open noted, gate-27 OR AppHost method-name verification against the OR-development clone); no orphaned `@spec` tags pointing at deleted code.
