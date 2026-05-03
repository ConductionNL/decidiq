# Design — Decidesk manifest v0.3.0 → v1.0.0

## Approach

Stabilisation in **four parallel verification gates**, then one atomic
version bump:

1. **Refactor gate** — `type:"custom"` → `type:"index"`/`type:"detail"`
   for 16 of 39 pages. Pure manifest refactor + customComponents shrink.
2. **Multi-tenancy gate** — consume `nextcloud-vue` multi-tenancy
   primitives once they ship in `multi-tenancy-context` (#113).
3. **i18n gate** — consume OR's `i18n-source-of-truth` +
   `i18n-api-language-negotiation` (just merged in OR #1420).
4. **Resolver gate** — consume OR's `register-resolver-service` (just
   merged in OR #1420).

Only after all four gates land does `manifest.version` bump to `1.0.0`.
Each gate ships as a separate commit on the change branch so partial
landings are reviewable.

The change is **manifest stabilisation + consumer wiring only**. No
new pages, no new schemas, no backend endpoints. Decidesk has been the
fleet's reference Tier-4 example for multiple sprints; this change
hardens that status.

## Refactor gate — page-type mapping

The 39-page manifest at `src/manifest.json` (v0.3.0) declares every page
as `type:"custom"`. The audit
(`.claude/audit-2026-05-03/research/R6-manifest-json.md` lines 165-168)
calls this out as the primary refactor for v1.0.0.

Mapping after refactor:

| Page id | Route | Before | After | OR schema |
|---|---|---|---|---|
| `Dashboard` | `/` | `custom` | `custom` (→ `dashboard` later) | n/a |
| `GovernanceBodies` | `/governance-bodies` | `custom` | `index` | GovernanceBody |
| `GovernanceBodyDetail` | `/governance-bodies/:id` | `custom` | `detail` | GovernanceBody |
| `Meetings` | `/meetings` | `custom` | `index` | Meeting |
| `MeetingDetail` | `/meetings/:id` | `custom` | `detail` | Meeting |
| `LiveMeeting` | `/meetings/:id/live` | `custom` | `custom` | Meeting (realtime) |
| `Participants` | `/participants` | `custom` | `index` | Participant |
| `ParticipantDetail` | `/participants/:id` | `custom` | `detail` | Participant |
| `AgendaItems` | `/agenda-items` | `custom` | `index` | AgendaItem |
| `AgendaItemDetail` | `/agenda-items/:id` | `custom` | `detail` | AgendaItem |
| `Motions` | `/motions` | `custom` | `index` | Motion |
| `MotionDetail` | `/motions/:id` | `custom` | `detail` | Motion |
| `AmendmentDetail` | `/amendments/:id` | `custom` | `custom` | Amendment (sub-entity) |
| `Minutes` | `/minutes` | `custom` | `index` | Minutes |
| `MinutesDetail` | `/minutes/:id` | `custom` | `detail` | Minutes |
| `Decisions` | `/decisions` | `custom` | `index` | Decision |
| `DecisionDetail` | `/decisions/:id` | `custom` | `detail` | Decision |
| `ActionItems` | `/action-items` | `custom` | `index` | ActionItem |
| `ActionItemDetail` | `/action-items/:id` | `custom` | `detail` | ActionItem |
| `Settings` | `/settings` | `custom` | `custom` | n/a (admin) |

Tally after refactor: **8 `index` + 8 `detail` + 4 `custom` = 20** (the
v0.3.0 manifest line count is 39 entries; the refactor merges no entries
— it just retypes 16 of them and removes their dedicated `component`
references in favour of the renderer's defaults).

The 4 residuals are intentional:

- `Dashboard` — refactor target is `type:"dashboard"`, blocked on
  shipping the dashboard widget config.
- `LiveMeeting` — realtime meeting shell with no `index`/`detail`
  analogue; would benefit from a future `type:"realtime"` library
  built-in.
- `AmendmentDetail` — Amendment is modelled as a sub-entity of Motion in
  decidesk's schema; the renderer's `detail` type doesn't yet support
  cross-schema detail lookups. Stays custom until renderer extends.
- `Settings` — admin-shaped page; would move to a future `type:"settings"`
  library built-in.

## Multi-tenancy gate — consumer wiring

Audit research
`.claude/audit-2026-05-03/research/R2-nc-vue-multitenancy.md` documents
8 fixes for the missing tenant-context plumbing in nextcloud-vue. Once
`nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113) ships the
critical-priority fixes (1, 2, 3, 4 — composable, header stamping,
`saveObject` org param, cache invalidation on tenant switch), decidesk
consumes:

- **`useTenantContext()` in `App.vue::setup()`** — exposes active
  organisation UUID via inject/provide; `App.vue` reads it to drive
  per-tenant cache invalidation
- **`organisationUuidGetter` on every `createObjectStore({ ... })`**
  call in `src/store/store.js` (currently 17 entity stores per the
  existing `p1-dashboard-and-navigation/spec.md` REQ-NAV-004) — wires
  the active org into the store's HTTP layer
- **`CnTenantBadge` in `App.vue` header / sidebar** — visual indicator
  of active tenant; mounted next to the user-menu button
- **Auto-fill `organisation` field** — for any form rendered via
  `CnFormDialog` / `CnAdvancedFormDialog` whose schema declares an
  `organisation` field, the field SHOULD default to
  `useTenantContext()`'s active org and SHOULD NOT render as a
  user-selectable dropdown (per `R2` finding 8)

The verification criterion is "all 5 wirings present and exercised in a
browser regression test". The wiring lives in decidesk; the primitives
live in the library.

## i18n gate — consumer wiring

Once `openregister/openspec/specs/i18n-source-of-truth/spec.md` and
`openregister/openspec/specs/i18n-api-language-negotiation/spec.md`
(merged in OR #1420) are released:

- **PATCH / PUT to translatable fields** — the form-side service layer
  in decidesk SHALL set the `X-Translation-Target-Language` header per
  the OR API spec. Translatable fields include policy-document content
  (Decisions, Motions, Minutes textual fields).
- **GET with explicit language** — the language selector in decidesk's
  header (or in `CnAppRoot`'s locale slot if Tier-4 exposes it) SHALL
  call `?_lang=` per the OR negotiation spec, with a fallback to the
  user's browser-negotiated `Accept-Language`.
- **Manifest `label` / `title` are i18n keys** — already true at v0.3.0
  per ADR-024 §6 / ADR-007. Verified, not changed.
- **Translation status badges** — fields with translations in
  `status: "draft" | "machine_translated"` SHOULD render a badge in the
  detail view. The badge component lives in nextcloud-vue (or in OR's
  `register-i18n` consumer kit if that ships first).

## Resolver gate — consumer wiring

Once `openregister/openspec/specs/register-resolver-service/spec.md`
(merged in OR #1420) is released:

- Search decidesk source for inline `getValueString(...register/schema...)`
  calls. Replace each with a call to the resolver service.
- The resolver lives in OR per ADR-022 (apps consume OR abstractions).
  Decidesk consumes it through the standard service-injection path
  (`OCP\Server::get(RegisterResolverService::class)` server-side; the
  frontend uses the corresponding Vue composable or store action).

## Files affected

- `decidesk/src/manifest.json` — 16 page-type retypes; version bump
- `decidesk/src/customComponents.js` — shrink the registry by 16
  entries (renderer's defaults take over)
- `decidesk/src/App.vue` — add `useTenantContext()` setup;
  `CnTenantBadge` mount; language selector wiring
- `decidesk/src/store/store.js` — `organisationUuidGetter` on each of
  the 17 `createObjectStore` calls
- `decidesk/src/services/**` and `decidesk/src/views/**` — resolver
  replacement; auto-fill organisation removal from forms; translation
  header wiring
- `decidesk/src/composables/useDecideskTenant.js` (new, optional) —
  thin wrapper over `useTenantContext()` if decidesk needs domain-
  specific selectors

Untouched: backend (`lib/`, `appinfo/`), schemas
(`lib/Settings/decidesk_register.json`), seed data, controllers.

## Citations

- **Library schema**:
  `nextcloud-vue/src/schemas/app-manifest.schema.json` — pinned via
  `$schema` in `manifest.json` (already correct at v0.3.0)
- **Library renderer spec**:
  `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
  — 17 REQ-JMR-* requirements
- **Library multi-tenancy change** (in flight):
  `nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113)
- **Cross-app convention**:
  `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  adoption ADR
- **Cross-app i18n**:
  `hydra/openspec/architecture/adr-025-i18n-source-of-truth.md`
- **Cross-app abstractions**:
  `hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md`
- **OR specs (just merged in #1420)**:
  - `openregister/openspec/specs/register-resolver-service/spec.md`
  - `openregister/openspec/specs/pluggable-integration-registry/spec.md`
  - `openregister/openspec/specs/i18n-source-of-truth/spec.md`
  - `openregister/openspec/specs/i18n-api-language-negotiation/spec.md`
- **Hydra fleet change**:
  `hydra/openspec/changes/adopt-app-manifest/` (#218) — this change
  is the per-app counterpart for decidesk per ADR-024 §9

## Out of scope

- **Page-type enum extensions.** `Dashboard`, `LiveMeeting`,
  `AmendmentDetail`, `Settings` stay `type:"custom"`. Adding
  `type:"dashboard"` to `Dashboard` is a follow-up once the dashboard
  widget config lands; the other three need library-side enum
  extensions.
- **Backend `/api/manifest` endpoint.** Same deferral as everywhere —
  driven by App Builder use case.
- **Adding new pages or schemas.** Stabilisation only.
- **Tier-5 (post-CnAppRoot) experiments.** Decidesk is already Tier-4;
  no upgrade path beyond Tier-4 is defined.

## Open questions

1. **`manifest.version` bump timing** — bump atomically when all four
   gates land, or stage `0.4.0` (refactor only) → `0.5.0` (multi-
   tenancy) → `1.0.0` (i18n + resolver)? Atomic is the simpler story
   ("v1.0.0 means the four gates are verified"); staged makes partial
   landings releasable. Default: atomic. Revisit if any gate slips
   significantly.
2. **`AmendmentDetail` future** — Amendment is sub-entity to Motion.
   Should the renderer grow cross-schema `detail` lookup, or should
   decidesk model Amendment as a top-level schema with a
   `parentMotion` reference (current model)? Cross-schema lookup is
   the more general fix and lifts a constraint for the whole fleet.
3. **`LiveMeeting` and `type:"realtime"`** — the realtime meeting
   shell has analogues in opentalk (video) and chat. Should the
   library grow a `type:"realtime"` built-in shaped around WebSocket
   subscriptions and per-frame UI updates? Worth surfacing as a
   nextcloud-vue change once the page-type-enum question lands
   (Open Question 2 in the OR change).
