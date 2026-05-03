# Decidesk app-manifest capability spec — v1.0.0 stabilisation

## MODIFIED Requirements

### Requirement: REQ-DD-MAN-001 Manifest stabilises at v1.0.0

`src/manifest.json` SHALL set `version` to `"1.0.0"` once the four
verification gates (refactor / multi-tenancy / i18n / resolver) all pass.
The bump is intentionally atomic so version cannot diverge from the
actual stability state. Sub-1.0.0 releases (`0.4.0`, `0.5.0`, etc.) MAY
be used for staged landings if any gate slips significantly; the default
is one atomic bump.

The version bump SHALL be the last commit on the change branch — never
the first.

#### Scenario: All gates pass before bump

- **WHEN** a reviewer signs off on §8.1-8.10 of `tasks.md`
- **THEN** `manifest.version` is bumped to `"1.0.0"` and the manifest
  re-validates against the canonical schema

#### Scenario: A gate slips — staged release allowed

- **WHEN** the i18n gate or resolver gate is blocked on an upstream OR
  release that is delayed
- **THEN** the team MAY land an interim `0.4.0` (refactor + multi-tenancy
  only) and bump to `1.0.0` later when the upstream lands

#### Scenario: Version stays in sync with `info.xml`

- **WHEN** `manifest.version` is `"1.0.0"`
- **THEN** decidesk's `appinfo/info.xml` `<version>` is also `"1.0.0"` per
  decidesk's semver-alignment convention

---

### Requirement: REQ-DD-MAN-002 Schema-driven pages use `type:"index"` / `type:"detail"`

The 8 list-view pages and their 8 detail-view counterparts SHALL use
`type:"index"` and `type:"detail"` respectively, not `type:"custom"`.
The renderer's built-in dispatch handles them; no custom-component
registration is required.

Index pages: `GovernanceBodies`, `Meetings`, `Participants`,
`AgendaItems`, `Motions`, `Minutes`, `Decisions`, `ActionItems`.

Detail pages: `GovernanceBodyDetail`, `MeetingDetail`,
`ParticipantDetail`, `AgendaItemDetail`, `MotionDetail`, `MinutesDetail`,
`DecisionDetail`, `ActionItemDetail`.

Each entry SHALL set `pages[].config.{register, schema}` referencing the
underlying OR slugs from `lib/Settings/decidesk_register.json`. Index
entries MAY also set `columns`.

#### Scenario: Index entries declare register and schema

- **WHEN** the manifest is loaded
- **THEN** every `type:"index"` page has `config.register` and
  `config.schema` set to OR slugs that resolve to a real OR resource

#### Scenario: Detail entries dispatch to the renderer's built-in detail view

- **WHEN** the user navigates to `/decisions/abc-123`
- **THEN** the route resolves to `CnPageRenderer` keyed by `id:
  "DecisionDetail"` with `type:"detail"`, which fetches the Decision
  with `objectId: "abc-123"` and renders the schema-driven detail view

#### Scenario: No `component` field on retyped entries

- **WHEN** a reviewer inspects a retyped page entry
- **THEN** the entry has no `component` field — that field is
  reserved for `type:"custom"` per the canonical schema

---

### Requirement: REQ-DD-MAN-003 Residual `type:"custom"` pages are explicitly enumerated

Exactly 4 of the 39 pages MAY remain `type:"custom"` after this change:
`Dashboard`, `LiveMeeting`, `AmendmentDetail`, `Settings`. Each residual
SHALL be justified in `design.md` and SHALL have a follow-up tracked in
`tasks.md` §9.

A reviewer SHALL reject any additional `type:"custom"` entry not on this
list of 4.

#### Scenario: Residual count is exactly 4

- **WHEN** the manifest is loaded post-refactor
- **THEN** `pages[].filter(p => p.type === "custom").length === 4` and
  the four ids match the enumerated set

#### Scenario: New `custom` requires upstream enum work

- **WHEN** a contributor proposes a new `type:"custom"` page
- **THEN** the reviewer requires either a follow-up nextcloud-vue change
  to extend the `type` enum or a refactor to fit one of the existing
  types

---

### Requirement: REQ-DD-MAN-004 Multi-tenancy primitives are consumed

`App.vue` SHALL call `useTenantContext()` (from `@conduction/nextcloud-
vue`) inside `setup()`. Every `createObjectStore({ ... })` invocation in
`src/store/store.js` SHALL set `organisationUuidGetter` to a function
returning the active organisation UUID from the composable.

`App.vue` SHALL mount `CnTenantBadge` (or its `CnAppRoot` header-slot
analogue) so the active tenant is visible at all times.

Forms rendered via `CnFormDialog` / `CnAdvancedFormDialog` whose schema
declares an `organisation` field SHALL rely on the composable's auto-fill
default. The `organisation` field SHALL NOT be rendered as a
user-selectable dropdown unless the form schema explicitly allows
multi-tenant cross-posting (none currently do).

#### Scenario: Tenant context is available app-wide

- **WHEN** any component calls `inject('tenantContext')` (or the
  composable directly)
- **THEN** the active organisation UUID is available reactively

#### Scenario: Tenant switch invalidates store cache

- **WHEN** the user switches tenant
- **THEN** every entity store re-fetches with the new organisation UUID
  and the previous tenant's cache is cleared per `R2-nc-vue-multitenancy.md`
  finding 4

#### Scenario: Form auto-fills organisation

- **WHEN** the user opens a form for a schema with an `organisation`
  field
- **THEN** the field is pre-populated to `useTenantContext().organisationUuid`
  and is not rendered as a dropdown

#### Scenario: Tenant badge is mounted

- **WHEN** the app shell is rendered
- **THEN** `CnTenantBadge` is visible in the header / sidebar and
  reflects the active tenant's display name

---

### Requirement: REQ-DD-MAN-005 i18n contracts are consumed

Decidesk SHALL consume OR's i18n contracts shipped in `i18n-source-of-
truth` and `i18n-api-language-negotiation`:

- A language selector in `App.vue` (or the `CnAppRoot` locale slot)
  SHALL list languages declared in OR's per-schema translation config.
- All GET requests to translatable resources SHALL pass `?_lang=<code>`
  per the OR negotiation spec when a non-default language is selected.
- All PATCH / PUT requests writing to translatable fields SHALL set the
  `X-Translation-Target-Language` header per the OR API spec.
- Detail views SHALL render translation-status badges for fields with
  `status: "draft" | "machine_translated"`.
- `manifest.label` / `manifest.title` values SHALL remain i18n keys per
  ADR-024 §6 / ADR-007. (No change from v0.3.0.)

#### Scenario: Language selector calls the negotiation parameter

- **WHEN** the user picks `nl` from the language selector
- **THEN** subsequent GETs include `?_lang=nl` and the response
  `Content-Language` header matches

#### Scenario: PATCH carries translation header

- **WHEN** the user edits a translatable field (e.g. Decision content)
  with the active language `en`
- **THEN** the PATCH request includes `X-Translation-Target-Language: en`

#### Scenario: Translation status badges render

- **WHEN** the detail view shows a field whose `status` is
  `machine_translated`
- **THEN** a visible badge (e.g. "auto-translated") is rendered next to
  the field

#### Scenario: Manifest labels resolve via t()

- **WHEN** the menu renders
- **THEN** every `menu[].label` resolves through `t(appName, key)` to
  the active locale's translation

---

### Requirement: REQ-DD-MAN-006 Resolver service replaces inline `getValueString` calls

Decidesk SHALL consume OR's `register-resolver-service` for any value
resolution that was previously implemented inline. After this change,
zero call sites SHALL remain that inline-resolve register/schema slugs
through ad-hoc string manipulation. The single source of truth for
resolution SHALL be the resolver service per ADR-022 (apps consume OR
abstractions).

#### Scenario: No inline resolver remains

- **WHEN** a reviewer searches the codebase for
  `getValueString.*register|register.*schema` style calls
- **THEN** zero matches are found in `src/` or `lib/`

#### Scenario: Server-side caller uses DI resolver

- **WHEN** server-side code needs to resolve a slug
- **THEN** it injects `RegisterResolverService` via
  `OCP\Server::get(...)` (or constructor DI) and calls the canonical
  method

#### Scenario: Frontend caller uses the composable / store action

- **WHEN** frontend code needs to resolve a slug
- **THEN** it calls the resolver's Vue composable or store action — not
  a local utility

---

### Requirement: REQ-DD-MAN-007 Manifest dependencies stay accurate

`manifest.dependencies` SHALL remain `["openregister"]`. Decidesk's
runtime requirements include OR (for the data layer) but no other
Conduction app — neither opencatalogi nor docudesk nor pipelinq nor
mydash are runtime requirements.

If the multi-tenancy gate (REQ-DD-MAN-004) introduces a runtime
requirement on a specific OR feature flag, that requirement SHALL be
expressed as a minimum version pin in `package.json` and `info.xml`,
NOT as an additional manifest dependency.

#### Scenario: Single dependency stays correct

- **WHEN** the manifest is loaded
- **THEN** `manifest.dependencies` is exactly `["openregister"]`

#### Scenario: Version pin captures runtime requirement

- **WHEN** the multi-tenancy gate requires OR ≥ N
- **THEN** the requirement is in `info.xml` `<dependencies>` and the
  manifest dependency stays the slug, not the version

---

### Requirement: REQ-DD-MAN-008 Tier 4 status is preserved

Decidesk SHALL remain Tier 4 (full `CnAppRoot` shell) per ADR-024 §8.
This change does not regress to a lower tier; it stabilises Tier 4 by
verifying every consumer-side wiring listed in REQ-DD-MAN-004,
REQ-DD-MAN-005, and REQ-DD-MAN-006.

#### Scenario: App.vue stays a CnAppRoot wrapper

- **WHEN** the app boots
- **THEN** `App.vue` mounts `CnAppRoot` with the manifest passed in (or
  loaded via the composable + passed to the shell) — not `NcContent` +
  bespoke `NcAppNavigation`

#### Scenario: Tier downgrade is a separate change

- **WHEN** a contributor proposes regressing decidesk to a lower tier
- **THEN** that requires its own openspec change with explicit
  justification — not allowed within this stabilisation pass

---

### Requirement: REQ-DD-MAN-009 Backend `/api/manifest` endpoint stays deferred

Decidesk SHALL NOT implement `GET /index.php/apps/decidesk/api/manifest`
as part of this change. The composable's silent fallback on 404 makes
absence non-regressive. A follow-up change driven by an admin
customisation use case SHALL add the endpoint when needed.

#### Scenario: Endpoint returns 404 today

- **WHEN** a request hits `/index.php/apps/decidesk/api/manifest`
- **THEN** the response is HTTP 404 and the loader silently keeps the
  bundled manifest

#### Scenario: Follow-up change is tracked

- **WHEN** a reader looks up "decidesk backend manifest endpoint"
- **THEN** the answer points at `tasks.md` §9.5 and the App Builder driver

---

### Requirement: REQ-DD-MAN-010 Regression suite covers all 39 routes after refactor

A browser regression suite SHALL navigate to each of the 39 routes in
sequence post-refactor. Each route SHALL render without error. The
16 retyped `index`/`detail` routes SHALL produce visually equivalent
output to their pre-refactor `custom`-component versions (modulo
expected upgrades from the renderer's built-in shell).

#### Scenario: All 39 routes resolve

- **WHEN** the regression suite runs
- **THEN** zero routes 404, zero routes throw uncaught errors, and zero
  routes render an empty body

#### Scenario: Retyped routes match pre-refactor

- **WHEN** a retyped route is compared against its pre-refactor
  screenshot
- **THEN** column layouts and detail panes are functionally equivalent
  (header / actions slot overrides preserve any non-trivial behaviour
  per `tasks.md` §1.7)

#### Scenario: Residual custom routes are unchanged

- **WHEN** the 4 residual `type:"custom"` routes (`Dashboard`,
  `LiveMeeting`, `AmendmentDetail`, `Settings`) are tested
- **THEN** they render their pre-refactor bespoke components without
  modification
