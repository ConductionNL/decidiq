# Tasks: Decidesk Adopts OpenRegister AppHost

## 0. Baseline + probe-consumer inventory

- [ ] 0.1 Capture baseline on a deployed instance: `curl -i /apps/decidesk/api/v1/health` (anonymous; record status code 200, full JSON body incl. `baseUrl`/`version`/`openregister`, and all `Access-Control-*` headers) + `curl -i -X OPTIONS /apps/decidesk/api/v1/health` (preflight headers). Store as fixtures for the parity diff. Also confirm `/api/metrics` 404s today (no MetricsController — baseline for the additive compliance upgrade).
- [ ] 0.2 **Probe-consumer inventory** (decides Option A vs B): enumerate every consumer of the health endpoint and record which response fields each one reads — especially `baseUrl` and the flattened `openregister` field. Sweep: K8s/compose/reverse-proxy probe configs, deployment + admin docs mentioning reverse-proxy verification, Newman collections, Playwright e2e specs, decidesk frontend code, external monitoring configs, and the archived REQ-API-004 contract (`openspec/changes/archive/2026-05-11-p4-integration/specs/p4-integration/spec.md`). Output: a table consumer → fields-read → ours/external.
- [ ] 0.3 **Decide shape adjudication** on the 0.2 evidence: Option A (standard engine shape, migrate all consumers) if every `baseUrl`/`openregister` reader is ours; Option B (thin `HealthController` subclass overriding the AppHost response-shaping hook to append `baseUrl` + flattened `openregister`) if any external consumer depends on those fields. Record the decision + rationale in this file and update the spec delta checkboxes accordingly.

## 1. Manifest observability block

- [ ] 1.1 Add `observability` block to `src/manifest.json`: `health = { statusCodePolicy: "always200", cors: true, checks: [ { id: "openregister", type: "orAvailable" } ] }`; no `metrics[]` (implicit `decidesk_info`/`decidesk_up` only).
- [ ] 1.2 Validate via ManifestService diagnostics (no errors); confirm gate-22 manifest-validation passes.

## 2. Wiring, deletions, legacy-route alias

- [ ] 2.1 Wire `AppHost\Bootstrap::register($context, Application::APP_ID, ...)` in `lib/AppInfo/Application.php`; remove the boilerplate registrations it replaces (dashboard/preferences/settings controller + SettingsService + AdminSettings/SettingsSection/InitializeSettings/DeepLinkRegistrationListener wiring). Domain `registerService` calls stay.
- [ ] 2.2 Convert `appinfo/routes.php` to `return \OCA\OpenRegister\AppHost\Routes::standard($extra)`, moving all decidesk domain routes into `$extra`. Preserve route ordering invariants (specific routes before the v1/ORI wildcards and the SPA catch-all).
- [ ] 2.3 **Legacy-route alias**: keep `GET /api/v1/health` (`health#status`) and `OPTIONS /api/v1/health` (`health#statusOptions`) in `$extra`, routed to the AppHost generic health controller (or the Option-B subclass), so existing reverse-proxy probes keep working for a deprecation window. File the follow-up issue for alias removal after the window, gated on the 0.2 inventory showing all consumers migrated.
- [ ] 2.4 Delete `lib/Controller/DashboardController.php`, `PreferencesController.php`, `SettingsController.php`, `lib/Service/SettingsService.php`, `lib/Listener/DeepLinkRegistrationListener.php`; shrink `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php`, `lib/Repair/InitializeSettings.php` to one-line subclass stubs (NC needs app-namespace classes for `<settings>`/`<repair-steps>`).
- [ ] 2.5 Apply the 0.3 decision to `lib/Controller/HealthController.php`: delete (Option A) or shrink to the response-shaping-hook subclass (Option B). If Option A: migrate every consumer found in 0.2 to the standard shape in the same change.
- [ ] 2.6 Sweep references: unit tests, `@spec` tags, info.xml, docs mentioning the deleted classes/old shape.

## 3. Verification

- [ ] 3.1 **Always-200 preserved (REQ-API-004 parity)**: with OR disabled (`occ app:disable openregister`), `GET /api/v1/health` AND `/api/health` return HTTP 200 with `status: degraded` and the OR check reported failed/unavailable; re-enable OR → HTTP 200 `status: ok`.
- [ ] 3.2 **CORS preserved**: `Access-Control-Allow-Origin/-Methods/-Headers` present on the health GET response; `OPTIONS /api/v1/health` preflight returns 200 with the same headers (diff vs 0.1 baseline).
- [ ] 3.3 **Shape parity per 0.3**: Option A — all migrated consumers green against the standard shape; Option B — body byte-compatible with the 0.1 baseline fixture (`status`, `baseUrl`, `version`, `openregister`).
- [ ] 3.4 **Metrics compliance upgrade**: `GET /api/metrics` as admin returns Prometheus text 0.0.4 with `decidesk_info` + `decidesk_up`; unauthenticated/non-admin requests are rejected (ADR-006 posture).
- [ ] 3.5 OR AppHost Newman contract collection green against decidesk; decidesk's own Newman collections green; existing Playwright e2e suite green (dashboard, settings, preferences surfaces unaffected by the generic controllers).

## 4. Docs

- [ ] 4.1 Update decidesk docs: health endpoint contract page (always-200 + CORS now declared via manifest knobs; legacy `/api/v1/health` alias + deprecation window; chosen response shape), new metrics endpoint, AppHost adoption note linking the manifest block as the living example.

## 5. Quality gates

- [ ] 5.1 `composer check:strict` green; all hydra gates green (incl. gate-14 route-reachability after the routes.php rewrite, gate-16 `@spec` coverage on changed methods, gate-22 manifest validation); no orphaned `@spec` tags pointing at deleted code.
