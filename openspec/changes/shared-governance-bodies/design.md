# Design: shared-governance-bodies

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Three new OpenRegister schemas — `BodyParticipation` (slug `body-participation`), `Zienswijzeronde` (slug `zienswijzeronde`), `Zienswijze` (slug `zienswijze`) — ship as `lib/Settings/register.d/56-shared-governance-bodies.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load). The base file gains exactly two additive edits that fragments structurally cannot make (see D2): the `shared-body` value on GovernanceBody's `bodyType` enum and the optional `namens` property on Membership. All workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/shared-governance-bodies.json` fragment rendered by `CnPageRenderer` (the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidiq CRUD controllers, per the redundant-controller gate).

Imperative code is limited to one small service: `ZienswijzerondeService::openRonde()` — fan-out generation of one Zienswijze per active BodyParticipation when a ronde opens (object generation is not expressible as a dialect; the pc-cyclus step-generation precedent).

Cross-references, not duplication:
- `Zienswijzeronde.cyclusStap` → the pc-cyclus sibling's `CyclusStap` (the GR ontwerpbegroting is a P&C artifact); nullable soft reference, either change lands first.
- `Zienswijze.decision` → the participant council's raadsbesluit; `Zienswijzeronde.decision` → the shared body's vaststellingsbesluit — both in the universal Decision model; this capability creates no meetings, agenda items, or decisions (pc-cyclus REQ-PCC-007 linkage discipline).
- Attendee voting weight in the shared body's meetings keeps coming from Membership per meeting-attendees REQ-MAT-006; no vote path reads BodyParticipation.

## Decisions

### D1: BodyParticipation is a first-class relationship schema, not properties on GovernanceBody

The multi-org axis needs per-edge data (seats, weight, toetreding/uittreding per participant) and history (withdrawn participants stay visible). An array property on the shared GovernanceBody could not carry per-edge lifecycle dates queryably, would bloat the universal body schema for the 99% of bodies that are not shared, and would make the reverse question ("in which regelingen does this municipality participate?") unanswerable via standard relation queries. A separate schema with two many-to-one GovernanceBody references gives both directions for free, mirrors how Membership models the person↔body edge, and is annotated `org:Membership` — the W3C org ontology/Popolo membership explicitly allows an organisation as member, so no new vocabulary is invented.

**Alternative considered:** `subOrganizationOf`-style parent links on GovernanceBody — rejected: a GR is not a hierarchy (the members are not sub-organisations of the shared body, nor vice versa), and a single parent pointer cannot carry per-participant terms.

### D2: Two additive base-register edits (bodyType enum value + Membership `namens`)

ADR-037 fragments add schemas; they cannot extend an existing schema's enum or property set (a fragment carrying `GovernanceBody` or `Membership` would replace/merge the whole schema definition and race with siblings). So, mirroring the works-council-consultation D2 precedent exactly: `shared-body` is added as a one-line direct edit to the GovernanceBody `bodyType` enum, and `namens` (optional GovernanceBody reference) as one property block on Membership. **Union-merge coordination:** the works-council sibling adds `works-council` to the same enum in the same wave — on conflict, union merge; never drop a sibling's value (the jq-union-merge lesson: diff against the merge base, keep every addition).

**Alternative considered:** reusing an existing bodyType for shared bodies — rejected: filters, seeds, the roster section, and future Wgr features must address shared bodies precisely; a GR-bestuur is legally distinct from `legislative`/`corporate-board`.

### D3: Membership provenance = one optional `namens` reference, not a provenance schema and not a BodyParticipation link

The question "namens which organisation does this person sit?" is a single organisational reference per membership — exactly Popolo's `on_behalf_of` semantics, which the schema already uses for `party` (string). A GovernanceBody reference is the least invasive model: absent for every non-shared body, no new schema, no migration, filterable/groupable in rosters via standard relation queries.

**Alternatives considered:** (a) a separate `MembershipProvenance` schema — rejected: a whole schema for one edge attribute, doubling every roster query; (b) referencing the `BodyParticipation` object instead of the organisation — rejected: participations are renegotiated/replaced over time (uittreding + re-toetreding), which would orphan or ambiguate the provenance, and every display would need a second hop to reach the organisation name. The participation is derivable from (`membership.governanceBody`, `membership.namens`) when needed.

### D4: The zienswijzeprocedure is ronde + per-participant zienswijze, both first-class

One `Zienswijzeronde` (what the shared body asks, on what subject, by when) fans out to N `Zienswijze` objects (one per participating organisation — its deadline tracking, its standpunt, its response document, its verwerking). Two schemas because the two objects have different owners (shared body vs participant), different lifecycles, and different audiences; the aggregated overview is then a plain reverse lookup on `ronde`. Neither is a Decision subtype: a zienswijze is a consultative response exchange, not a governance outcome — the same reasoning that kept ConsultationRequest (works-council D1) and Toezegging (toezeggingen D2) out of the 42-field Decision supertype. Both link *to* Decisions where real besluiten exist (raadsbesluit on the zienswijze, vaststellingsbesluit on the ronde).

**Alternative considered:** zienswijzen as an array on the ronde — rejected: per-participant lifecycle, rappels, RBAC filtering ("show my organisation's obligations"), and Files attachments all need real objects.

### D5: Ronde deadline is denormalised onto each Zienswijze at generation

The notification dialect's scheduled triggers filter on the object's own fields (toezeggingen pattern: non-terminal lifecycle + deadline window). The deadline lives on the ronde, but the rappel must fire per zienswijze to reach the right organisation. So `openRonde()` copies the ronde's `deadline` onto each generated Zienswijze — one documented denormalisation with a single write moment. If the shared body moves the deadline while zienswijzen are outstanding, the same service action propagates the new date to non-terminal zienswijzen (PUT-semantic saves carrying all fields forward). If the dialect turns out to support relation-path filters, the copy can be dropped (open question).

### D6: Fan-out generation is imperative, idempotent, and minimal

No dialect creates objects, so `ZienswijzerondeService::openRonde()` is app code (pc-cyclus REQ-PCC-004 precedent): query active participations of the shared body (`uittredingsDatum` null or future), create one Zienswijze in `uitstaand` per participant with the deadline copied, transition the ronde `concept → geopend` through the declared lifecycle map. Idempotency: before creating, the service checks for an existing zienswijze for (ronde, participant) — a retry after partial failure completes the remainder without duplicates (the toezeggingen bulk-confirm idempotency pattern). The endpoint follows the governance-scoped controller pattern: `#[NoAdminRequired]` + per-object guard on the shared body's scope (no-admin-idor/semantic-auth gates), one route in `appinfo/routes.php` (route-reachability gate).

### D7: Declarative-vs-imperative decision (ADR-031)

Default declarative via `x-openregister-{lifecycle,notifications,relations}` + manifest aggregations; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Ronde workflow (`concept → geopend → verwerking → afgerond`, `ingetrokken` terminal) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Zienswijze workflow (`uitstaand → in-voorbereiding → ingediend → verwerkt`, `niet-ingediend` terminal) | `x-openregister-lifecycle` | Same |
| Deadline rappels (approaching + overdue) and "zienswijze uitstaand" notice | `x-openregister-notifications` scheduled + `created` triggers on Zienswijze (filter: non-terminal status + deadline window), nl/en subjects | ADR-031 default for reminders; toezeggingen-register pattern; gate-18 hard-fails imperative dispatch; no bespoke ReminderJob |
| Roster on body detail, zienswijzen overview on ronde detail | `x-openregister-relations` reverse lookups rendered as object-list sections | Typed relations; standard OR relation queries |
| Dashboard KPI "Openstaande zienswijzen" | Manifest stat-widget `source` aggregation (`metric: count`, filter `status: [uitstaand, in-voorbereiding]`) | Declarative count like every existing KPI widget |
| Weighted voting in the shared body | **None new** — Membership `votingWeight` per REQ-MAT-006 | Reuse; BodyParticipation.votingWeight is prefill master data only, never read by tabulation |
| Fan-out generation of zienswijzen on ronde opening (+ deadline propagation) | **Imperative** — `ZienswijzerondeService` | No dialect creates objects; pc-cyclus generation precedent; idempotent per D6 |
| Membership-form weight suggestion from the participation | **Imperative (frontend)** — form prefill in the membership dialog | Pure UI convenience; user-overridable, no server logic |

## Nextcloud Integration

- Controllers: one thin endpoint for `openRonde` (existing governance-scoped controller pattern, `#[NoAdminRequired]` + per-object guard on the shared body; route registered in `appinfo/routes.php`).
- Services: `ZienswijzerondeService` (new — fan-out generation + deadline propagation; saves via `ObjectService::saveObject()` carrying **all** fields forward, PUT-semantic).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications, lifecycles, relations are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; zienswijze recording and verwerking via explicit dialogs in `src/dialogs`/`src/modals` (modal-isolation gate); Files leaf on ronde and zienswijze detail pages for the subject and response documents.

## Security Considerations

- **Scope (medium):** zienswijzerondes and zienswijzen stay behind OR RBAC on the shared instance; no public publication surface is introduced by this change (a GR's begrotingsstukken publication is a future concern for the woo-diwoo sibling, not this change).
- **openRonde authority:** the endpoint checks governance-body scope on the shared body per object, not merely the route annotation (semantic-auth gate); generated zienswijzen inherit standard OR object ACLs.
- **CSRF/auth posture:** standard NC attributes on the one new controller method; no public app routes.
- **Input validation:** schema-level (required fields, enums, date formats) via OpenRegister validation; both lifecycle maps reject out-of-order transitions server-side.
- **No writeOnly fields** on any of the three schemas (no render-boundary exposure).

## File Structure

```
lib/Settings/register.d/56-shared-governance-bodies.json   (new — 3 schemas + dialects + seed)
lib/Settings/decidesk_register.json                        (edit — +1 bodyType enum value, +1 optional Membership property)
src/manifest.d/shared-governance-bodies.json               (new — zienswijzeronde index/detail, zienswijzen index, menu)
src/manifest.json                                          (edit — GovernanceBodyDetail participation section + 1 Dashboard stat widget)
lib/Service/ZienswijzerondeService.php                     (new — fan-out generation, idempotent)
lib/Controller/…                                           (edit — openRonde endpoint on existing governance controller)
appinfo/routes.php                                         (edit — 1 route)
tests/Unit/Service/ZienswijzerondeServiceTest.php          (new)
tests/e2e/shared-governance-bodies.spec.ts                 (new — gate-19 coverage)
docs/features/gemeenschappelijke-regelingen.md             (new)
```

## Seed Data

A realistic gemeenschappelijke regeling modelled on the SED organisatie pattern: three fictional municipalities sharing one uitvoeringsorganisatie ("NOZ organisatie" for Noorderbrug, Oostwoud, Zuidermeer). References use existing decidiq seed objects where available or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder where a cross-seed reference is resolved at import. All objects carry the `@self` envelope `register: decidesk` with their schema slug.

### Schema: `governance-body` (seed additions, existing schema)

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | bestuur-noz-organisatie | gemeenteraad-noorderbrug | gemeenteraad-oostwoud | gemeenteraad-zuidermeer |
| name | "Bestuur NOZ organisatie" | "Gemeenteraad Noorderbrug" | "Gemeenteraad Oostwoud" | "Gemeenteraad Zuidermeer" |
| bodyType | shared-body | legislative | legislative | legislative |
| domain | municipal | municipal | municipal | municipal |
| workflowTemplate | legislative | legislative | legislative | legislative |

### Schema: `body-participation`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | deelname-noorderbrug-noz | deelname-oostwoud-noz | deelname-zuidermeer-noz |
| sharedBody | (bestuur-noz-organisatie ref) | (bestuur-noz-organisatie ref) | (bestuur-noz-organisatie ref) |
| participant | (gemeenteraad-noorderbrug ref) | (gemeenteraad-oostwoud ref) | (gemeenteraad-zuidermeer ref) |
| seats | 2 | 2 | 1 |
| votingWeight | 2 | 2 | 1 |
| toetredingsDatum | 2015-01-01 | 2015-01-01 | 2015-01-01 |
| uittredingsDatum | — | — | — |
| label | "Deelnemer per oprichting" | "Deelnemer per oprichting" | "Deelnemer per oprichting" |

### Schema: `membership` (seed additions, existing schema — provenance demo)

Two board members of the NOZ bestuur seeded as Person + Membership (REQ-GBD-011): "W. van Dam" (role `chair`, `namens` = gemeenteraad-noorderbrug, votingWeight 2) and "A. Terpstra" (role `member`, `namens` = gemeenteraad-zuidermeer, votingWeight 1). Person seeds reuse existing demo persons where sensible or nil-UUID placeholders resolved at import.

### Schema: `zienswijzeronde`

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | zienswijzeronde-begroting-2027 | zienswijzeronde-wijziging-gr-2026 |
| title | "Zienswijzeronde ontwerpbegroting 2027 NOZ organisatie" | "Zienswijzeronde wijziging gemeenschappelijke regeling" |
| sharedBody | (bestuur-noz-organisatie ref) | (bestuur-noz-organisatie ref) |
| subjectType | ontwerpbegroting | wijziging-regeling |
| subjectDescription | "Ontwerpbegroting 2027, aangeboden aan de raden conform art. 35 Wgr." | "Voorstel tot wijziging van de regeling i.v.m. taakuitbreiding vergunningverlening." |
| deadline | 2026-06-15 *(past at seed time)* | 2026-09-30 |
| cyclusStap | (pc-cyclus begroting step, nil-UUID placeholder) | — |
| decision | — | — |
| status | verwerking | geopend |

### Schema: `zienswijze`

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | zienswijze-noorderbrug-begroting-2027 | zienswijze-oostwoud-begroting-2027 | zienswijze-zuidermeer-begroting-2027 | zienswijze-noorderbrug-wijziging-gr |
| ronde | (ronde 1 ref) | (ronde 1 ref) | (ronde 1 ref) | (ronde 2 ref) |
| participant | (gemeenteraad-noorderbrug ref) | (gemeenteraad-oostwoud ref) | (gemeenteraad-zuidermeer ref) | (gemeenteraad-noorderbrug ref) |
| deadline | 2026-06-15 | 2026-06-15 | 2026-06-15 | 2026-09-30 |
| standpunt | positief-met-kanttekeningen | positief | — | — |
| text | "De raad stemt in met de ontwerpbegroting, met de kanttekening dat de indexatie van de gemeentelijke bijdrage maximaal 3% mag bedragen." | "De raad stemt zonder opmerkingen in met de ontwerpbegroting 2027." | — | — |
| ingediendDatum | 2026-06-10 | 2026-06-02 | — | — |
| decision | (raadsbesluit, nil-UUID placeholder) | — | — | — |
| verwerking | gedeeltelijk-overgenomen | overgenomen | — | — |
| verwerkingsToelichting | "Indexatie verlaagd naar 3,2%; volledige inwilliging niet mogelijk binnen de CAO-ontwikkeling." | "Voor kennisgeving aangenomen." | — | — |
| status | verwerkt | verwerkt | niet-ingediend | uitstaand |

**Related items per object:**
- Files: ontwerpbegroting 2027 PDF on ronde 1 and wijzigingsvoorstel PDF on ronde 2 (Files leaf); zienswijzebrief PDF on zienswijze objects 1 and 2.
- Notes/Tasks/Contacts: none (internal follow-up is a VTODO via the existing action-item flow, deliberately not seeded here).

Ronde 2's zienswijze (object 4) is `uitstaand`, so the "Openstaande zienswijzen" KPI is non-zero on a fresh install (ADR-016 testability); ronde 1 exercises the full flow including verwerking, a niet-ingediend participant, and the pc-cyclus link; the participation weights (2/2/1) make the weighted-roster scenario demonstrable.

## Migration Plan

1. Land the register.d fragment, the two additive base-register edits, the manifest.d fragment, the GovernanceBodyDetail section + dashboard widget, `ZienswijzerondeService`, seed data, tests, and docs in one decidiq PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schemas on upgrade).
2. `pc-cyclus` (sibling) is a soft reference only — `cyclusStap` is nullable, so the changes land in any order. `works-council-consultation` (sibling) edits the same `bodyType` enum — union merge, both values survive.
3. Rollback: revert the PR — the fragments disappear, pages unregister, the enum value / `namens` property / detail section / widget revert (all additive). Existing objects remain soft-retained in OR; a `shared-body` typed body or a `namens`-carrying membership would fail re-validation only on edit and can be re-typed/cleared manually.

No data migration — the three registers start empty apart from seed data.

## Risks / Trade-offs

- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from the existing Decision schema; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`body-participation`, `zienswijzeronde`, `zienswijze`), never PascalCase.
- [Base-file edits race with wave siblings] → strictly additive; union merge against the merge base on conflict, never dropping a sibling's addition (works-council adds `works-council` to the same enum); fragment number 56 is assigned to this change.
- [Deadline denormalisation drifts from the ronde] → single propagation path in `ZienswijzerondeService` (PUT-semantic, all fields carried forward); PHPUnit asserts a moved ronde deadline reaches non-terminal zienswijzen and skips terminal ones.
- [Fan-out partially fails mid-batch] → idempotent per-participant creation with collected failures reported; retry completes the remainder without duplicates (D6).
- [Prefill reads the wrong participation] → the membership-form suggestion resolves the participation by (`governanceBody`, `namens`) pair and is user-overridable; never written server-side.

## Open Questions

- Can `x-openregister-notifications` scheduled triggers filter on a relation-path field (zienswijze → ronde.deadline)? If yes, drop the D5 denormalisation; verify against OpenRegister's trigger resolver during apply.
- Can the `namens` picker be scoped via `x-relation-filter` to organisations with an active participation in the membership's `governanceBody` (a cross-object filter)? Fallback: plain unscoped GovernanceBody picker.
- Rappel window before the zienswijze deadline (provisional: 14 days before, weekly after — the toezeggingen values); tuning deferred to a future admin-settings change.
