# Tasks — Decidesk manifest v0.3.0 → v1.0.0

## 1. Refactor gate — `type:"custom"` → `type:"index"` / `type:"detail"`

- [ ] 1.1 Edit `src/manifest.json` and retype the 8 list-view pages from `type:"custom"` to `type:"index"`: `GovernanceBodies`, `Meetings`, `Participants`, `AgendaItems`, `Motions`, `Minutes`, `Decisions`, `ActionItems`.
- [ ] 1.2 For each retyped index, add `pages[].config.{register, schema, columns}` referencing the underlying OR register and schema slugs from `lib/Settings/decidesk_register.json`. The renderer reads these to drive the table.
- [ ] 1.3 Edit `src/manifest.json` and retype the 8 detail-view pages from `type:"custom"` to `type:"detail"`: `GovernanceBodyDetail`, `MeetingDetail`, `ParticipantDetail`, `AgendaItemDetail`, `MotionDetail`, `MinutesDetail`, `DecisionDetail`, `ActionItemDetail`.
- [ ] 1.4 For each retyped detail, set `pages[].config.{register, schema}` referencing the same slug pair as its index counterpart. Add a `route` `:id` parameter check; the renderer pulls the object via the slug + `:id`.
- [ ] 1.5 Remove the `component` field from the 16 retyped entries — the renderer dispatches via the closed `type` enum, not via custom-component lookup.
- [ ] 1.6 Update `src/customComponents.js` to drop the 16 components that no longer route through the custom map: `GovernanceBodiesView`, `GovernanceBodyDetailView`, `MeetingsView`, `MeetingDetailView`, `ParticipantsView`, `ParticipantDetailView`, `AgendaItemsView`, `AgendaItemDetailView`, `MotionsView`, `MotionDetailView`, `MinutesView`, `MinutesDetailView`, `DecisionsView`, `DecisionDetailView`, `ActionItemsView`, `ActionItemDetailView`.
- [ ] 1.7 Move any non-trivial behaviour from those 16 components (custom column formatters, action handlers, header overrides) into `headerComponent` / `actionsComponent` / `slots` overrides on the manifest entry. Verify each override resolves through the registry to a registered component.
- [ ] 1.8 Confirm the residual 4 `type:"custom"` pages stay: `Dashboard`, `LiveMeeting`, `AmendmentDetail`, `Settings`. Document the rationale per page in a comment in `manifest.json` (or in `docs/manifest.md` if that file exists).
- [ ] 1.9 Run `npm run check:manifest` and confirm the manifest still validates against the canonical schema.

## 2. Multi-tenancy gate — consume nextcloud-vue primitives (#113)

- [ ] 2.1 **Block** until `nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113) ships and `@conduction/nextcloud-vue` releases a version exposing `useTenantContext`, `organisationUuidGetter`, and `CnTenantBadge`. Bump `package.json` floor to that version.
- [ ] 2.2 Import `useTenantContext` in `src/App.vue` and call it inside `setup()`. Confirm the composable returns a reactive `organisationUuid` ref.
- [ ] 2.3 Mount `CnTenantBadge` in `App.vue`'s header (or the `header` slot of `CnAppRoot` if Tier-4 surfaces it). The badge SHOULD show the active tenant's display name and SHOULD update reactively when the user switches tenant.
- [ ] 2.4 Edit `src/store/store.js` and add `organisationUuidGetter: () => useTenantContext().organisationUuid.value` to every `createObjectStore({ ... })` call (17 entity stores per the existing `p1-dashboard-and-navigation/spec.md` REQ-NAV-004).
- [ ] 2.5 Verify the new wiring: open the app, switch tenant, confirm every entity store re-fetches with the new tenant's organisation UUID and the cache is cleared per `R2-nc-vue-multitenancy.md` finding 4.
- [ ] 2.6 Audit every `CnFormDialog` / `CnAdvancedFormDialog` invocation in the app. For schemas that have an `organisation` field, remove the user-selectable dropdown and rely on the composable's auto-fill default per `R2-nc-vue-multitenancy.md` finding 8 / line 113-115.
- [ ] 2.7 Add or update at least one browser regression test confirming a multi-tab tenant switch correctly invalidates the cache and the new tab does not show stale data from the previous tenant.

## 3. i18n gate — consume OR i18n contracts (merged in OR #1420)

- [ ] 3.1 **Block** until `openregister` releases a version including `i18n-source-of-truth` and `i18n-api-language-negotiation`. Bump the OR floor in decidesk's `info.xml` / dependency declaration to that version.
- [ ] 3.2 Wire a language selector in `App.vue` (or in `CnAppRoot`'s locale slot if Tier-4 exposes it). The selector SHOULD list the languages declared in OR's per-schema translation config and SHOULD call `?_lang=<code>` on subsequent GETs per the OR negotiation spec.
- [ ] 3.3 Update the form-side service layer in `src/services/` (or wherever PATCH/PUT is dispatched). For each translatable field, set the `X-Translation-Target-Language` header to the user's selected language.
- [ ] 3.4 Translatable fields in decidesk are at minimum: Decisions content, Motions content, Minutes content, AgendaItem description. Verify each writes through the new header.
- [ ] 3.5 Add translation-status badges to detail views for fields with `status: "draft" | "machine_translated"`. The badge component SHOULD come from nextcloud-vue (or from OR's `register-i18n` consumer kit). Falling back to a thin local component is acceptable if neither is available yet.
- [ ] 3.6 Verify the existing `manifest.label` / `manifest.title` i18n keys still resolve through `t(appName, key)` per ADR-024 §6 / ADR-007. No change expected — this is a verification step.
- [ ] 3.7 Add a browser regression test that switches language, confirms the UI re-renders with translated content, and confirms a PATCH after the switch carries the correct `X-Translation-Target-Language` header.

## 4. Resolver gate — consume `register-resolver-service` (merged in OR #1420)

- [ ] 4.1 **Block** until `openregister` releases a version including `register-resolver-service`. Confirm in OR's release notes.
- [ ] 4.2 Search decidesk source for inline `getValueString(...register/schema...)` calls. Use `grep -rn "getValueString\|register.*schema" src/ lib/`. Enumerate every hit.
- [ ] 4.3 For each hit, replace the inline call with the resolver service. Server-side: `OCP\Server::get(RegisterResolverService::class)->resolve(...)`. Frontend: the matching composable or store action.
- [ ] 4.4 Verify the resolver replacement preserves caller behaviour (same return type, same null-handling). Add unit tests where the original code lacked them.
- [ ] 4.5 Per ADR-022 (apps consume OR abstractions): confirm decidesk does not declare its own copy of the resolver logic. The whole point of the resolver is single-sourcing in OR.

## 5. Version bump (atomic, after all four gates pass)

- [ ] 5.1 Confirm gates 1, 2, 3, and 4 are all green: refactor done, multi-tenancy wiring exercised, i18n contracts consumed, resolver in use.
- [ ] 5.2 Edit `src/manifest.json` and bump `version` from `"0.3.0"` to `"1.0.0"`.
- [ ] 5.3 Re-run `npm run check:manifest` and confirm the manifest validates.
- [ ] 5.4 Update `info.xml` `<version>` to match (semver alignment of app version with manifest version is a decidesk convention).
- [ ] 5.5 Run the full browser regression suite per task §6 and confirm no behavioural drift.
- [ ] 5.6 Tag the release: `git tag v1.0.0` on the merged commit. (Tag is created post-merge by the human; not part of the change branch.)

## 6. Regression tests

- [ ] 6.1 Browser test — navigate to each of the 39 routes in sequence after the refactor. Each must render without error and match the pre-change screenshot. Use `browser-1` (per project rules) for sequential navigation; capture screenshots into `.playwright-mcp/decidesk-v1-route-<id>.png`.
- [ ] 6.2 Verify the 16 refactored `index`/`detail` pages render through the renderer with identical column layouts and detail panes vs. their pre-refactor `custom` components.
- [ ] 6.3 Verify the residual 4 `custom` pages (`Dashboard`, `LiveMeeting`, `AmendmentDetail`, `Settings`) still render their bespoke components.
- [ ] 6.4 Verify dependency check: with `openregister` disabled, the app SHOULD render `CnDependencyMissing` per the existing `manifest.dependencies: ["openregister"]` declaration (no change to this behaviour).
- [ ] 6.5 Verify multi-tenancy: with two tenants and a user assigned to both, switch tenants, confirm the data list reloads, the badge updates, and a form auto-fills `organisation` to the new tenant's UUID.
- [ ] 6.6 Verify i18n: switch language, confirm UI re-renders, confirm a translatable PATCH carries the right header, confirm translation-status badges render where expected.
- [ ] 6.7 Verify resolver: pick one schema (e.g. Decision), confirm a value-resolution scenario (e.g. lookup of register/schema by slug) goes through the resolver service rather than inline.

## 7. Documentation

- [ ] 7.1 Update `decidesk/openspec/README.md` (if present) to reflect v1.0.0 status.
- [ ] 7.2 Add a `CHANGELOG.md` entry for v1.0.0 covering the four gates and the page-type refactor.
- [ ] 7.3 Update any architecture-docs or onboarding docs that referenced "v0.3.0 in flight" / "manifest still iterating" to "v1.0.0 stable, reference Tier-4 implementation".

## 8. Sign-off checklist (per ADR-024 §9)

- [ ] 8.1 `src/manifest.json` validates against the canonical schema.
- [ ] 8.2 Tier choice is explicit (Tier 4, unchanged from v0.3.0).
- [ ] 8.3 Regression test suite confirms all 39 routes resolve and render.
- [ ] 8.4 Reviewer confirms the manifest does not duplicate or contradict the canonical schema.
- [ ] 8.5 `manifest.version` is `"1.0.0"`.
- [ ] 8.6 `manifest.dependencies` is `["openregister"]` (unchanged from v0.3.0).
- [ ] 8.7 Multi-tenancy primitives consumed: `useTenantContext`, `organisationUuidGetter`, `CnTenantBadge`, auto-fill `organisation`.
- [ ] 8.8 i18n primitives consumed: language selector wired, `X-Translation-Target-Language` header set on PATCH, translation badges rendered.
- [ ] 8.9 Resolver consumed: zero inline `getValueString(...register/schema...)` calls remain.
- [ ] 8.10 Audit references in proposal.md / design.md cite the right files (`R6-manifest-json.md`, `R2-nc-vue-multitenancy.md`, `R4-or-i18n-source-of-truth.md`, `R5-or-api-language-negotiation.md`, ADR-024).

## 9. Follow-ups (out of this change)

- [ ] 9.1 **`Dashboard` → `type:"dashboard"`** — once the widget config schema lands, retype Dashboard. Tracked as `decidesk-dashboard-widget-config` (not yet created).
- [ ] 9.2 **`type:"realtime"` library built-in** — for `LiveMeeting`. Tracked as `add-app-manifest-realtime-type` in nextcloud-vue (not yet created).
- [ ] 9.3 **Cross-schema `detail` for `AmendmentDetail`** — Amendment as sub-entity of Motion. Tracked as `cn-page-renderer-cross-schema-detail` in nextcloud-vue (not yet created).
- [ ] 9.4 **`type:"settings"` library built-in** — for `Settings`. Tracked alongside §9.2 in the same nextcloud-vue change.
- [ ] 9.5 **Backend `/api/manifest` endpoint** — driven by App Builder use case. Tracked as `decidesk-manifest-backend` (not yet created).
- [ ] 9.6 **Reviewer-side drift gate** — same as the OR change; pairs with ADR-029 route-reachability gate. Tracked as `hydra-gate-manifest-route-drift`.
