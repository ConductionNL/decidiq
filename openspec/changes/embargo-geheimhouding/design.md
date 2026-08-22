# Design: embargo-geheimhouding

## Architecture Overview

Thin-client extension (ADR-022/ADR-037): two new OpenRegister schemas — `Geheimhouding` and `GeheimhoudingGrond` — ship as ADR-037 register fragment `lib/Settings/register.d/65-embargo-geheimhouding.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; fragment numbers 40-64 belong to sibling changes, 65 is assigned here). Additive `embargoUntil` / `embargoActive` / `embargoAudience` properties on the existing `DigitalDocument` schema are the one edit made directly in `lib/Settings/decidesk_register.json` (fragments merge whole schemas; property additions to existing schemas belong in the canonical file — records-management precedent).

The change is a juridical layer OVER the four existing confidentiality mechanisms, never a fifth vocabulary:

- `AgendaItem.confidentiality` (meeting-pack-board-book REQ-005) — driven to `confidential` when a geheimhouding with scope `item` is imposed.
- `Decision.isPublished` (p3-citizen-participation) — driven to `"confidential"` for scope `decision`; the structured `GeheimhoudingGrond` supersedes the free-text `Decision.legalBasis` for new geheimhouding records (existing free-text values untouched — `legalBasis` remains the general legal-citation field).
- public-publication — the future-`publicatiedatum` predicate stays the ONLY public timed-release mechanism; this change adds a structural refusal for targets under active geheimhouding, consistent with the deny-list (never modifying the eligibility-gates requirement itself).
- commissievergaderingen REQ-CVG-010 — its viewer-audit precedent for besloten stukken is generalized to every document under active geheimhouding; records-management REQ-RMA-009 keeps owning archival classification (`beperkingGebruik`).

Workflow orchestration (impose → bekrachtig → opheffing) lives in one `GeheimhoudingService`; timed member-side release is one `EmbargoReleaseJob`; UI is a manifest-v2 fragment rendered by `CnPageRenderer` with the shared object stores hitting `/apps/openregister/api/objects` directly (no CRUD controllers, per the redundant-controller gate).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Rationale |
|---|---|---|
| Geheimhouding lifecycle `opgelegd → bekrachtigd → opgeheven` (+ direct `opgelegd → opgeheven` when no bekrachtiging required) | **Declarative** `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Register KPIs (active per body, awaiting/overdue bekrachtiging, opgeheven-awaiting-publication) | **Declarative** `x-openregister-aggregations` | Counter queries, no N+1 |
| Ground ↔ geheimhouding ↔ target ↔ besluit references | **Declarative** `x-openregister-relations` (UUID references) | Standard relation dialect |
| Bekrachtiging-due, embargo-released, opheffing-recorded notifications | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 hard-fails imperative dispatch), nl/en subjects | Scheduled + field-change triggers; the release notification rides the `embargoActive` flip, so no bespoke notification job |
| Register overview pages, KPI widgets, dialogs | **Declarative** manifest-v2 fragment `src/manifest.d/embargo-geheimhouding.json` (ADR-037; schema refs by **slug**: `geheimhouding`, `geheimhouding-grond`, never PascalCase) | Rendering is manifest-driven |
| Impose action (create Geheimhouding + set target classifier + create bekrachtiging agenda item, transactionally, with authority guard) | **Imperative** `GeheimhoudingService::impose()` | Multi-object transaction across schemas tied to organ authority; not expressible as a dialect |
| Bekrachtiging / opheffing recording (link besluit, transition, restore classifier) | **Imperative** `GeheimhoudingService` | Same: cross-object transaction + authority guard |
| Member-side embargo release at `embargoUntil` | **Imperative** `EmbargoReleaseJob` (OCP `TimedJob`, 15 min) flipping `embargoActive` via the OR object API | Group-scoped RBAC cannot time-switch per object for member groups (see Security Considerations); a scheduled field flip is honest and testable — no magic |
| Publication guard (refuse payload build for targets under active geheimhouding) | **Imperative** extension of the existing publication payload/eligibility service | Payload construction is by design imperative in decidiq (existing pattern) |
| View-audit of stukken under geheimhouding | **Imperative** logging on the app's document read/download path writing to the OR audit trail | OR does not declaratively audit reads; the CVG-010 precedent is imperative too |

### Other key decisions

- **`requiresBekrachtiging` lives on the ground, not in code.** The 2023 Wet bevorderen integriteit en functioneren decentraal bestuur (Gemeentewet art. 87-89, voorheen art. 25/55/86) changed the bekrachtigingsregime; provinces, waterschappen, and private governance domains differ again. Hardcoding any regime would be wrong somewhere — so both article labelings ship as seed data and the workflow keys off the configured ground.
- **Fail-visible, never auto-lift.** An overdue bekrachtiging changes NO legal state: it surfaces as KPI + notification. Same for opheffing → publication: eligibility is restored, publication requires a griffie action through the normal publish flow.
- **One-directional relations.** `Geheimhouding` references its target by UUID + scope; target schemas gain no `geheimhouding` back-reference (avoids editing four schemas; the register overview and detail views resolve the relation from the Geheimhouding side via the relation dialect).
- **Ground vocabulary = Schema.org `DefinedTerm`.** Grounds are a managed term set, matching how records-management treats classification labels; `Geheimhouding` itself is a Schema.org `Action` (agent/object/startTime/endTime).

## API Design

No new CRUD endpoints (objects go through `/apps/openregister/api/objects`). Workflow actions only:

### `POST /api/geheimhoudingen/{id}/impose-agenda` — (re)create the bekrachtiging agenda item on the confirming body's next meeting; body: `{ "meetingId": "<uuid>" }` optional override
### `POST /api/geheimhoudingen/{id}/bekrachtig` — body: `{ "besluitId": "<uuid>" }`; 409 when ground does not require bekrachtiging or state ≠ `opgelegd`
### `POST /api/geheimhoudingen/{id}/opheffen` — body: `{ "besluitId": "<uuid>", "conditions": "..." }` (conditions optional); restores target classifier, 403 without organ authority
### `GET  /api/geheimhoudingen/{id}/views` — audit list (actor, timestamp) for the geheimhouding's target document; griffie/staff only

All endpoints `#[NoAdminRequired]` + `#[NoCSRFRequired]` where API-consumed, with per-object organ-authority guards in the method body, registered in `appinfo/routes.php` (gates: route-auth, semantic-auth, route-reachability, no-admin-idor).

## Database Changes

None. Decidiq owns no tables (ADR-022); all data lives in OpenRegister objects.

## Nextcloud Integration

- Controllers: `GeheimhoudingController` (4 workflow/audit endpoints above).
- Services: `GeheimhoudingService` (impose/bekrachtig/opheffen, classifier drive, agenda placement); publication payload/eligibility service extended with the active-geheimhouding structural refusal.
- Background jobs: `EmbargoReleaseJob` extends `OCP\BackgroundJob\TimedJob`, interval 900 s, registered in `Application.php`; queries `embargoActive: true AND embargoUntil <= now` via the OR object API and flips the field per object (audit-trailed writes).
- Events/Hooks: none — notifications are declarative (ADR-031); document-view audit hooks into the app's existing document read/download path using `IUserSession` for the actor.

## Security Considerations

- **What RBAC can and cannot time-switch (honest boundary):** OR's published-predicate surface supports `publicatiedatum <= $now` for the anonymous `public` group on payload objects — that primitive is reused unchanged for the public side. Group-scoped read rules for member groups are membership-based and cannot evaluate a per-object time predicate for "wider members after moment X while entitled members before it". Therefore the member-side unlock is a scheduled job flipping `embargoActive`; access rules key off the field value. Release granularity = job interval (15 min), stated in spec and docs; no second-precision claims.
- **Authority guards:** impose/bekrachtig/opheffen require organ authority (griffie/chair per governance body) checked per object in the method body — `#[NoAdminRequired]` alone is not authorization (no-admin-idor gate).
- **Never fail open:** if the ground list cannot be resolved, imposing is refused (no geheimhouding without a structured ground); if the geheimhouding lookup fails during publication-guard evaluation, publication is refused, not allowed (unsafe-auth-resolver pattern).
- **Secrets:** none involved. Audit rows are append-only (never mutated after write).
- Input validation on besluit/meeting UUIDs (must resolve to objects of the right schema and governance body).

## NL Design System

Standard NC components via nc-vue (`CnPageRenderer`, `CnDetailPage`, KPI stat widgets); nldesign CSS variables only (no hardcoded colors); WCAG 2.1 AA; dialogs as separate files under `src/dialogs/` (modal-isolation gate); `NcSelect` with `inputLabel` for the ground picker.

## File Structure

```
lib/
  Settings/register.d/65-embargo-geheimhouding.json   (new — 2 schemas + dialects + seeds)
  Settings/decidesk_register.json                     (edited — additive DigitalDocument embargo props)
  Service/GeheimhoudingService.php                    (new)
  Controller/GeheimhoudingController.php              (new)
  BackgroundJob/EmbargoReleaseJob.php                 (new)
  AppInfo/Application.php                             (edited — job + service wiring)
appinfo/routes.php                                    (edited — 4 routes)
src/
  manifest.d/embargo-geheimhouding.json               (new — register pages + KPI widgets)
  dialogs/GeheimhoudingImposeDialog.vue               (new)
  dialogs/GeheimhoudingOpheffenDialog.vue             (new)
docs/features/embargo-geheimhouding.md                (new)
tests/  (Unit + integration collections)
```

## Seed Data

Seeds ship as `x-openregister-seeds` inside fragment 65 (convention: `43-process-config-v1.json`), realistic for a Dutch municipality yet neutral enough for the other governance domains (a vereniging or RvC uses "statuten/reglement" grounds via `overig`). Cross-references use seed slugs; example UUIDs use the nil placeholder.

### Schema: `geheimhouding-grond` (8 seeds, `builtIn: true`, admin-editable per ADR-016)

| name | citation | legacyCitation | category | requiresBekrachtiging |
|---|---|---|---|---|
| Geheimhouding raadsstukken | Gemeentewet art. 87-89 | voorheen art. 25 | gemeentewet | true |
| Geheimhouding collegestukken | Gemeentewet art. 87-89 | voorheen art. 55 | gemeentewet | false |
| Geheimhouding commissiestukken | Gemeentewet art. 87-89 | voorheen art. 86 | gemeentewet | true |
| Eenheid van de Kroon / veiligheid van de Staat | Woo art. 5.1 lid 1 | — | woo-absoluut | false |
| Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens | Woo art. 5.1 lid 1 | — | woo-absoluut | false |
| Eerbiediging van de persoonlijke levenssfeer | Woo art. 5.1 lid 2 | — | woo-relatief | false |
| Financiële en economische belangen van het bestuursorgaan | Woo art. 5.1 lid 2 | — | woo-relatief | false |
| Statutaire vertrouwelijkheid (niet-overheid) | statuten/reglement | — | overig | true |

### Schema: `geheimhouding` (3 seeds)

| state | scope | ground | notes |
|---|---|---|---|
| `opgelegd` | document | Geheimhouding raadsstukken | bekrachtigingDeadline in the future → feeds "awaiting bekrachtiging" KPI |
| `opgelegd` | item | Geheimhouding commissiestukken | bekrachtigingDeadline in the past, no besluit → feeds the OVERDUE KPI (fail-visible testable on install) |
| `opgeheven` | decision | Eerbiediging van de persoonlijke levenssfeer | opheffingsbesluit + conditions recorded → feeds "awaiting publication" KPI |

### DigitalDocument embargo seeds

Two seed documents: one with `embargoUntil` in the future (`embargoActive: true`, audience "fractievoorzitters") and one past-`embargoUntil` already released (`embargoActive: false`) — so both the locked and the released state are visible on a clean install, and the release-job test has a target.

## Risks / Trade-offs

- [Lifecycle dialect drift] → fragment uses the exact canonical keys (`field`/`initial`/`states`/`terminal`/`transitions`) verbatim from the existing Decision schema; register-import verified on a clean Postgres instance (non-canonical keys are silently ignored — known fleet defect class).
- [Job-based release means up-to-15-min delay] → accepted and documented; alternative (per-request time check in an RBAC rule per member group) is not supported by OR and faking it app-side would bypass RBAC.
- [Classifier drive races a concurrent edit] → impose/opheffen carry ALL target fields forward (OR saveObject is PUT-semantic — partial updates null omitted props); test that a non-changed field survives.
- [Manifest slug refs] → schema refs by slug (`geheimhouding`, `geheimhouding-grond`); gates 28/30/51/52 run on register+manifest changes.
- [Orphaned capability] → every service method is invoked from a manifest action or route; gates 56/57 verify no zero-caller writes.

## Migration Plan

Purely additive: new fragment + additive `DigitalDocument` properties import via the existing register bootstrap; no data migration, no NC migration. Existing free-text `Decision.legalBasis` values are not migrated (they stay valid). Rollback = revert the PR and re-import; existing objects untouched; already-created Geheimhouding records persist as legal records.

## Open Questions

Carried from the proposal: bekrachtiging deadline from the confirming body's next scheduled meeting vs a configurable window (provisional: next meeting + manual override); release-job interval 5 vs 15 minutes (provisional: 15, documented as the granularity).
