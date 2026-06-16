---
kind: code
---

# Proposal: Decidesk Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Decidesk hand-writes ~2,500 lines of fleet-standard plumbing that the OpenRegister AppHost now provides generically:

- `lib/Controller/HealthController.php` (162 lines) — the fleet's *most bespoke* health endpoint. It checks OR availability via a DI-container lookup of `OCA\OpenRegister\Service\ObjectService` (catch `Throwable` → `'unavailable'`), **always returns HTTP 200** even when degraded, emits CORS headers plus an OPTIONS preflight route, and returns extra response fields (`baseUrl` from `overwrite.cli.url`, `version`, `openregister: 'connected'|'unavailable'`). This contract is mandated by **REQ-API-004** (`openspec/changes/archive/2026-05-11-p4-integration/specs/p4-integration/spec.md`, "REQ-API-004 — API health check"): reverse-proxy probes must succeed even when the app is degraded, and the body must let admins verify reverse-proxy base-URL configuration.
- `lib/Controller/DashboardController.php` (82), `PreferencesController.php` (158), `SettingsController.php` (128), `lib/Service/SettingsService.php` (322) — namespace-token copies of the petstore skeleton.
- `lib/Settings/AdminSettings.php` (132), `lib/Sections/SettingsSection.php` (107), `lib/Repair/InitializeSettings.php` (127), `lib/Listener/DeepLinkRegistrationListener.php` (93) — drifted boilerplate.
- `lib/AppInfo/Application.php` (1,039 lines) and `appinfo/routes.php` (212 lines) carry the standard registration/route blocks inline with the domain wiring.
- **No `MetricsController` exists at all** — decidesk currently violates the ADR-006 observability contract (no metrics endpoint).

The AppHost observability engine (`openregister/openspec/changes/apphost-observability-engine`) designed its two policy knobs **specifically for decidesk**: `statusCodePolicy: "always200"` (the REQ-API-004 reverse-proxy contract, vs the ADR-006 default of 503-on-critical-failure) and `cors: true` (decidesk's probe CORS parity). Decidesk is the reference adopter for both knobs — if this adoption doesn't exercise them, nothing does.

## Proposed Change

Adopt both AppHost halves (`apphost-observability-engine` + `apphost-boilerplate-controllers`), deleting the local copies.

### 1. Declarative observability

Add to `src/manifest.json`:

```jsonc
"observability": {
  "health": {
    "statusCodePolicy": "always200",   // REQ-API-004: probes succeed even when degraded
    "cors": true,                      // REQ-API-004 / task-1.4 CORS + preflight parity
    "checks": [
      { "id": "openregister", "type": "orAvailable" }
    ]
  }
  // no metrics[] declared — implicit decidesk_info / decidesk_up only
}
```

- The engine's `orAvailable` primitive subsumes today's hand-rolled DI-container lookup (same semantics: resolve OR `ObjectService`, `Throwable` → failed).
- **Metrics**: decidesk has no metrics endpoint today; it gains the implicit `decidesk_info` (version/php/nextcloud labels) and `decidesk_up` for free via the engine's `GenericMetricsController` — admin-gated, Prometheus text 0.0.4, ADR-006-compliant. This is a pure compliance upgrade requiring zero descriptors.

### 2. Response-shape adjudication (decision required)

Decidesk's current health body `{ status, baseUrl, version, openregister }` differs from the engine's standard shape `{ status, app, version, checks: { "<id>": "ok|failed: ..." } }`. Two non-standard fields are at stake: `baseUrl` (reverse-proxy verification, the stated purpose of REQ-API-004) and the flattened `openregister: 'connected'|'unavailable'` (vs standard `checks.openregister`). The spec adjudicates between:

- **Option A (preferred)**: inventory every actual probe consumer (K8s/compose probe configs, reverse-proxy verification docs, Newman collections, e2e specs, external monitoring) and migrate them to the standard shape. `baseUrl` verification migrates to `checks` semantics or an explicit consumer-side check; REQ-API-004's *normative* core (public, always-200, OR status visible) is preserved by the engine knobs. Decidesk then carries **zero** local health code.
- **Option B (fallback)**: keep a thin local `HealthController` subclass of the AppHost generic, overriding only the documented response-shaping hook (the AppHost extension-first pattern — protected hook methods, no `final`) to append `baseUrl` and the flattened `openregister` field. Everything else (always-200 policy, CORS, check execution) still comes from the engine.

Task 0.2 performs the consumer inventory; the option is chosen on its evidence. If any consumer outside our control reads `baseUrl` or `openregister`, Option B wins; if all consumers are ours, Option A wins and consumers are migrated in the same change.

### 3. Legacy route preservation

The live health route is `GET /api/v1/health` (route name `health#status`) plus `OPTIONS /api/v1/health` (`health#statusOptions`) — **not** the AppHost-standard `/api/health`. Existing probes must not break: the adoption keeps `/api/v1/health` (GET + OPTIONS) as `$extra` alias routes pointing at the AppHost generic health controller for a deprecation window, while also exposing the standard `/api/health`. The deprecation window and removal are tracked as a follow-up task, gated on the task-0.2 consumer inventory.

### 4. Boilerplate adoption and deletions

Wire `AppHost\Bootstrap::register()` and `Routes::standard($extra)`, then delete:

| File | Lines | Fate |
|---|---|---|
| `lib/Controller/HealthController.php` | 162 | Delete (Option A) or shrink to response-shaping subclass (Option B) |
| `lib/Controller/DashboardController.php` | 82 | Delete — alias to `GenericDashboardController` |
| `lib/Controller/PreferencesController.php` | 158 | Delete — alias to `GenericPreferencesController` |
| `lib/Controller/SettingsController.php` | 128 | Delete — alias to `GenericSettingsController` |
| `lib/Service/SettingsService.php` | 322 | Delete — alias to `AppHostSettingsService` |
| `lib/Settings/AdminSettings.php` | 132 | Shrink to one-line subclass stub (NC `<settings>` needs an app-namespace class) |
| `lib/Sections/SettingsSection.php` | 107 | Shrink to one-line subclass stub |
| `lib/Repair/InitializeSettings.php` | 127 | Shrink to one-line subclass stub (NC `<repair-steps>` needs an app-namespace class; repair-step pattern preserved per the install-order constraint) |
| `lib/Listener/DeepLinkRegistrationListener.php` | 93 | Delete — generic listener reads manifest `deepLinks` block |
| `lib/AppInfo/Application.php` | 1,039 | Shrink: boilerplate registration replaced by one `Bootstrap::register()` call; domain service registrations (the bulk of decidesk's 30+ `registerService` calls) stay |
| `appinfo/routes.php` | 212 | `return \OCA\OpenRegister\AppHost\Routes::standard($extra)` — decidesk's ~90 domain routes (boards, motions, voting, minutes, ORI/v1 public API, …) move into `$extra`; boilerplate routes (dashboard page/catch-all, settings, preferences, health, metrics) come from `standard()` |

Net: roughly 1,300–1,500 lines of pure boilerplate deleted; decidesk gains a metrics endpoint it never had.

## Impact

- **Modified**: `src/manifest.json`, `appinfo/routes.php`, `lib/AppInfo/Application.php`, `appinfo/info.xml` (repair-step/settings stubs if class names change — expected unchanged).
- **Deleted/shrunk**: the table above.
- **Behavioural deltas**: new `/api/health` + `/api/metrics` endpoints (additive); health response shape per the Option A/B adjudication; everything else bit-compatible per the boilerplate parity rules.
- **Risk**: probe consumers reading `baseUrl`/`openregister` break under Option A — mitigated by the mandatory task-0.2 inventory before the option is chosen, the legacy-URL alias, and baseline-vs-after diffs. Engine regressions are guarded by the OR AppHost Newman contract collection.

## Dependencies

Chained on OpenRegister changes `apphost-observability-engine` (engine, `always200` + `cors` knobs, `orAvailable` check, implicit metrics) and `apphost-boilerplate-controllers` (`Bootstrap`, `Routes::standard`, generic controllers/services/stub pattern). ADR-040 (hydra) defines the manifest block; ADR-006 defines the endpoint contract; ADR-022 is the architectural basis.
