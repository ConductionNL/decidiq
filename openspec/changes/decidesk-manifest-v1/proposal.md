# Decidesk — Manifest v0.3.0 → v1.0.0 stabilisation

## Why

Decidesk is the **fleet's reference Tier-4 app-manifest consumer** — the only
Conduction app that today loads `useAppManifest` + `CnAppRoot` + `CnAppNav` +
`CnPageRenderer` end-to-end. The manifest at `src/manifest.json` (39 pages,
8 menu entries, `dependencies: ["openregister"]`, version `0.3.0`) is the
canonical real-world example cited by ADR-024 §1 and by every other app's
adoption change (including OR's parallel `openregister-adopt-app-manifest`).

The 2026-05-03 OR-abstraction audit
(`.claude/audit-2026-05-03/research/R6-manifest-json.md` line 25-30) flags
decidesk as Tier-4 done but version-pinned at `0.3.0` despite the manifest
being effectively stable for several sprints. Three blockers stop a
straight `0.3.0 → 1.0.0` bump:

1. **`type:"custom"` everywhere.** Every one of decidesk's 39 pages is
   declared `type:"custom"` — even `Meetings` / `Decisions` / `Motions`
   list views that map cleanly to `type:"index"` and their `:id` detail
   variants that map to `type:"detail"`. The reference app should
   demonstrate the closed enum's primary types, not avoid them. (See
   `R6-manifest-json.md` lines 165-168 — the audit recommends this
   refactor explicitly.)
2. **Multi-tenancy primitives are landing in nextcloud-vue.** Audit
   research
   `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md` documents
   8 fixes for the missing tenant-context plumbing in `createObjectStore`,
   `useObjectStore`, `buildHeaders`, and the form dialogs. Once
   `nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113) ships,
   decidesk needs to consume the new primitives — `useTenantContext()`,
   `organisationUuidGetter`, `CnTenantBadge`, the auto-fill `organisation`
   default — for the manifest to be considered v1.0.0-stable. Without that
   wiring, decidesk's `manifest.dependencies: ["openregister"]` understates
   the actual surface area the app depends on.
3. **i18n primitives are landing in OR.** OR specs
   `register-resolver-service`, `i18n-source-of-truth`, and
   `i18n-api-language-negotiation` (just merged in OR #1420) are the
   canonical homes for the resolver / translation logic. Decidesk has
   policy-document content that needs Dutch + English; the `manifest.label`
   / `manifest.title` keys per ADR-024 §6 / ADR-007 must resolve through
   the new contract, and any inline `getValueString(...register/schema...)`
   calls in decidesk views must move to the resolver. Without that,
   bumping to `1.0.0` would lock in stale wiring.

A v1.0.0 bump is the audit follow-up. This change is the **stabilisation
+ verification** plan that lands the bump cleanly.

## What Changes

- **Bump `manifest.version` from `0.3.0` → `1.0.0`** once the four
  verification gates below pass. The bump is intentionally last so the
  version cannot diverge from the actual stability state.

- **Refactor `type:"custom"` pages to `type:"index"` / `type:"detail"`**
  where they map cleanly to schema-driven views:
  - `Meetings` (`/meetings`) → `index` (was `custom` + `MeetingsView`)
  - `MeetingDetail` (`/meetings/:id`) → `detail` (was `custom` + `MeetingDetailView`)
  - `Decisions` (`/decisions`) → `index`
  - `DecisionDetail` (`/decisions/:id`) → `detail`
  - `Motions` (`/motions`) → `index`
  - `MotionDetail` (`/motions/:id`) → `detail`
  - `ActionItems` (`/action-items`) → `index`
  - `ActionItemDetail` (`/action-items/:id`) → `detail`
  - `AgendaItems` (`/agenda-items`) → `index`
  - `AgendaItemDetail` (`/agenda-items/:id`) → `detail`
  - `Minutes` (`/minutes`) → `index`
  - `MinutesDetail` (`/minutes/:id`) → `detail`
  - `Participants` (`/participants`) → `index`
  - `ParticipantDetail` (`/participants/:id`) → `detail`
  - `GovernanceBodies` (`/governance-bodies`) → `index`
  - `GovernanceBodyDetail` (`/governance-bodies/:id`) → `detail`
  - `MotionDetail` / `AmendmentDetail` are kept as a `detail` pair on a
    single schema (Motion + Amendment) per the v0.3.0 schema model.

  Pages that **stay** `type:"custom"` (per audit guidance — these don't
  have a clean schema-driven shape):
  - `Dashboard` (`/`) — bespoke widget composition, will move to
    `type:"dashboard"` in a follow-up once the widget config lands
  - `LiveMeeting` (`/meetings/:id/live`) — realtime meeting shell, no
    `index`/`detail` analogue
  - `Settings` (`/settings`) — admin-shaped, will move to `type:"settings"`
    once the upstream library extends the enum
  - `AmendmentDetail` (`/amendments/:id`) — amendment is a sub-entity of
    Motion; can move to `detail` once the renderer supports cross-schema
    detail navigation

  Final tally after refactor: **16 `index`/`detail` + 4 `custom` + future
  Dashboard `dashboard`** instead of today's 39/0/0/0.

- **Verify R2 multi-tenancy primitives are wired.** Once
  `nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113) merges,
  decidesk consumes:
  - `useTenantContext()` composable in `App.vue::setup()` — exposes the
    active organisation UUID via inject/provide
  - `organisationUuidGetter` wired into every `createObjectStore` call
    in `src/store/store.js` (currently 17 entity stores per the existing
    `p1-dashboard-and-navigation/spec.md` REQ-NAV-004)
  - `CnTenantBadge` in the top bar of `App.vue` (or via `CnAppRoot`'s
    header slot if Tier-4 exposes it)
  - All forms that have an `organisation` field rely on the composable's
    auto-fill default instead of rendering it as a user-selectable
    dropdown (per `R2-nc-vue-multitenancy.md` line 113-115 finding 8)

- **Adopt `i18n-source-of-truth` + `i18n-api-language-negotiation` once
  they ship.** The consumer-side wiring:
  - Form-side `X-Translation-Target-Language` header on PATCH /
    PUT requests for translatable fields (per the OR spec)
  - Frontend language selector calls `?_lang=` query parameter on GET
    requests (per the negotiation spec)
  - `manifest.label` / `manifest.title` values resolve via the app's
    `t()` function as ADR-024 §6 already requires — verified, not changed
  - Translation status badges on policy-document content fields

- **Adopt `register-resolver-service`.** Replace any inline
  `getValueString(...register/schema...)` calls in decidesk's views and
  components with calls to the resolver service. The resolver lives in
  OR per the canonical spec; decidesk consumes it through the standard
  service-injection path.

## Capabilities

### Modified Capabilities

- `decidesk-app-manifest`: existing capability already satisfied by the
  v0.3.0 manifest. This change refactors `type:"custom"` to
  `type:"index"`/`type:"detail"` for 16 of the 39 pages, verifies the
  multi-tenancy + i18n + resolver consumption, and bumps `version` to
  `1.0.0`.

### New Capabilities

*(none — purely a stabilisation pass on an existing capability.)*

## Impact

- **Modified files**:
  - `decidesk/src/manifest.json` — refactor 16 page entries from
    `custom` → `index`/`detail`; bump `version`; possibly add
    `pages[].config.{register, schema, columns}` for the index entries
    (mapping to OR's underlying schemas)
  - `decidesk/src/customComponents.js` — drop the 16 components that no
    longer need to register through the custom map (the renderer's
    built-in `index`/`detail` types replace them)
  - `decidesk/src/App.vue` — add `useTenantContext()` setup; add
    `CnTenantBadge` to header
  - `decidesk/src/store/store.js` — wire `organisationUuidGetter` into
    every `createObjectStore` call
  - `decidesk/src/views/**` — replace inline `getValueString` calls with
    the resolver service; remove organisation `<select>` fields where
    auto-fill suffices
- **No backend changes** — the manifest stays FE-only per ADR-024.
- **Dependency floor bumps**:
  - `@conduction/nextcloud-vue` — pinned ≥ the version that ships the
    multi-tenancy primitives (`useTenantContext`, `organisationUuidGetter`,
    `CnTenantBadge`)
  - `openregister` (server-side) — pinned ≥ the version that ships
    `register-resolver-service`, `i18n-source-of-truth`, and
    `i18n-api-language-negotiation`
- **Validates against**:
  - Library schema at `nextcloud-vue/src/schemas/app-manifest.schema.json`
  - Library renderer spec at
    `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/
    json-manifest-renderer/spec.md` (17 REQ-JMR-* requirements)
  - Cross-app convention at
    `hydra/openspec/architecture/adr-024-app-manifest.md`
- **Audit references**:
  - `.claude/audit-2026-05-03/research/R6-manifest-json.md` — manifest
    pattern + decidesk Tier-4 status + audit follow-up
  - `.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md` —
    multi-tenancy verification list (this change implements the
    consumer side)
  - `.claude/audit-2026-05-03/research/R4-or-i18n-source-of-truth.md` and
    `R5-or-api-language-negotiation.md` — i18n consumer wiring
  - `.claude/audit-2026-05-03/00-executive-summary.md §1-2` — adoption
    rather than feature work as the priority

## Risks

- **Library primitives ship later than expected.** The multi-tenancy +
  i18n + resolver work is sequenced upstream of this change. If any of
  those slip, the version bump to `1.0.0` slips with them. Mitigated by
  splitting the verification gates so individual gates can ship
  incrementally (and the version bump waits for all four).
- **Refactoring `custom` → `index`/`detail` is not zero-touch.** The
  schema-driven `index`/`detail` renderer expects `pages[].config.
  {register, schema, columns}` and dispatches differently from a
  `custom` view. Some of decidesk's bespoke list views may have
  view-specific behaviour the renderer doesn't expose (custom column
  formatters, action overrides). Mitigated by per-page regression tests
  and by keeping a fallback `slots` override per page where needed.
- **`AmendmentDetail` and `LiveMeeting` stay custom.** Acknowledged as
  intentional residual; not a blocker for `1.0.0`.

## Out of scope

- **Adding new pages.** This change refactors existing pages and
  verifies new wiring. Net-new pages (e.g. additional governance bodies
  or a public-facing decision register) belong in their own change.
- **Backend `/api/manifest` endpoint.** Same deferral as the OR change
  — driven by an App Builder use case, not the v1.0.0 stabilisation.
- **Tier-5 / library-extension page types.** If decidesk wants
  `type:"live-meeting"` or `type:"dashboard"` for its bespoke views,
  that is a library-side change in nextcloud-vue (per ADR-024
  consequences). Decidesk's residual `custom` count after this change
  (4 pages) is the signal feeding that future library work.
