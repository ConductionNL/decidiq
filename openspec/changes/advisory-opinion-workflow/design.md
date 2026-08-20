# Design: advisory-opinion-workflow

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Two new OpenRegister schemas — `Adviesaanvraag` (slug `adviesaanvraag`) and `Advies` (slug `advies`) — ship as `lib/Settings/register.d/60-advisory-opinion-workflow.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file gains only additive edits, see D2). Workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/advisory-opinion-workflow.json` fragment rendered by `CnPageRenderer` (frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidesk CRUD controllers, per the redundant-controller gate).

Imperative code is limited to one guard: `AdviceAccountabilityGuard`, wired into the existing decision status-transition path, blocking decision completion when a linked advies deviates and no verantwoording is recorded (fail-closed).

Cross-references, not duplication:
- `Adviesaanvraag.advisoryBody` / `requestingBody` → the universal `GovernanceBody` (advisory bodies get `bodyType=advisory-body`; their internal meetings use the existing meetings machinery unchanged).
- `Adviesaanvraag.relatedDecision` / `advisoryStage` → a routed `Decision` / its advisory `DecisionStage`; in-route stage mechanics stay with decision-methods `method=advice` (actor sets `advised`/`deferred` directly) — this change is the request/response/accountability wrapper around them.
- `Adviesaanvraag.agendaItem` → the universal `AgendaItem`; raadscommissie advies stays with `commissievergaderingen`.

## Decisions

### D1: Adviesaanvraag + Advies are first-class schemas, not Decision subtypes and not WOR ConsultationRequests

An advisory-opinion traject is a body-to-body request/response exchange with an accountability tail — it has a requester (the deciding body), an external respondent (the advisory body), a question, a deadline, a formal response artifact, and a deviation-motivering obligation. The `Decision` supertype models governance *outcomes* with a guarded lifecycle that does not fit `verzonden → … → afgerond`. The sibling `works-council-consultation` `ConsultationRequest` is superficially similar (its request/response vocabulary is deliberately mirrored: subject, sent/requested dates, response outcome + text + document, besluit recording) but is a *statutory employee-participation* instrument (bestuurder → OR, WOR art. 25/27, opschortingstermijn) with different actors, legal frame, and lifecycle; folding both into one schema would force every filter, KPI, guard, and publication rule to branch on a type flag. The `Advies` is a separate artifact (not response fields on the aanvraag) because it has its own recording authority (the advisory body's secretary, not the requesting griffie), its own publication moment, and its own strekking that the guard evaluates — and it mirrors the `commissievergaderingen` precedent of advies-as-first-class-object (CommissieAdvies).

**Alternative considered:** response fields folded onto Adviesaanvraag (the WCC pattern) — rejected: WCC's respondent (the OR) *owns* the whole ConsultationRequest object; here the aanvraag is owned by the requesting griffie while the advies is authored by the advisory body's secretary — separate objects give OR per-object RBAC a clean authority split and let the advies publish independently.

### D2: Additive base-register edits for `bodyType=advisory-body` and Decision verantwoording fields

ADR-037 fragments add schemas; they cannot extend an existing schema's enum or add properties to an existing schema (a fragment carrying `GovernanceBody` or `Decision` would replace/merge the whole definition and race with siblings). Two minimal direct edits to `decidesk_register.json`: (1) `advisory-body` on the GovernanceBody `bodyType` enum — the works-council (WCC D2) and shared-body (shared-governance-bodies) precedent; (2) optional `verantwoording` fields on Decision (`adviesVerantwoording` string, `adviesVerantwoordingDate` date) so the motivering lives on the decision record itself (REQ-AOW-005), not only on the aanvraag.

**Alternative considered:** reusing `bodyType=citizen-panel` for advisory bodies — rejected: a CitizenPanel is a citizen-participation construct with its own public join/feedback API surface (p3-citizen-participation); an adviesraad sociaal domein or cliëntenraad is a formally installed advisory organ whose trajecten need precise filtering and seeding. Storing the verantwoording only on the Adviesaanvraag — rejected: the decision record must be self-contained for archiving and publication (the motivering is part of the besluit, Archiefwet-relevant).

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative via `x-openregister-{lifecycle,notifications,relations}` + manifest aggregations; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Traject flow (`verzonden → in-behandeling → advies-uitgebracht → verantwoord → afgerond`, `advies-uitgebracht → afgerond` conform shortcut, `niet-uitgebracht` terminal) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Deadline rappels before/after `requestedByDate` | `x-openregister-notifications` scheduled triggers (filter on non-terminal lifecycle + date window), nl/en subjects | ADR-031 default for reminders; toezeggingen-register pattern; gate-18 hard-fails imperative dispatch; no bespoke ReminderJob |
| "Deviating besluit verantwoord" notification to advisory-body members | `x-openregister-notifications` `updated` trigger on the verantwoording write | Event-shaped, declarative |
| Public advies + verantwoording | `authorization.read` predicate `publicatiedatum <= $now` on the live `Advies` and `Adviesaanvraag` objects | Predicate-on-live-object (toezeggingen REQ-005 pattern); the verantwoording must be live-visible without republication; public-publication's derived-payload eligibility gates are NOT touched |
| Dashboard KPIs "Open adviesaanvragen" / "Adviezen wachtend op afdoening" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI widget |
| Body/decision/stage/agenda-item/document linkage | `x-openregister-relations` | Typed relations; reverse lookup via standard OR relation queries |
| Verantwoordingsplicht: block decision completion on unverantwoorde afwijking | **Imperative** — `AdviceAccountabilityGuard` in the decision status-transition path | Cross-object conditional requirement (Decision outcome × Advies.strekking × presence of motivering) is not expressible in the lifecycle dialect; mirrors the existing decision lifecycle-guard precedents; fails closed on evaluation errors (unsafe-auth-resolver lesson: never `catch → allow`) |
| Advisory-stage outcome on `relatedDecision` | **None new** — existing decision-methods `method=advice` semantics (actor sets `advised`/`deferred` directly) | Reuse; a derivation here would duplicate decision-methods ownership |

### D4: Deviation is sign-only and the guard's blocking set is narrow

Deviation = (`strekking` ∈ {`positief`, `positief-met-kanttekeningen`} ∧ outcome rejected) ∨ (`strekking` = `negatief` ∧ outcome adopted). `geen-advies`, conform outcomes, `niet-uitgebracht` trajecten, and decisions without linked aanvragen never block. Whether kanttekeningen were honoured is political judgment the guard cannot evaluate — staff can always record a verantwoording voluntarily, and the guard's error names exactly which aanvraag is missing its motivering. Recording the verantwoording is one dialog action that writes both objects (Decision fields + aanvraag `verantwoordingText` and the `verantwoord` transition) — the save carries **all** fields forward (OR saveObject is PUT-semantic; a partial update would silently null schema props).

**Alternative considered:** a required-field validation note on Decision — rejected: whether the field is required depends on another object's state (the advies), which schema validation cannot see; a lifecycle guard at the transition point is the established decidesk precedent for exactly this shape of rule.

### D5: The requested-by date warns, never blocks

Like WCC D5: `requestedByDate` is what the requesting body asked, not a statutory term. Rappels warn (pre-deadline + overdue, declarative); no lifecycle guard references the date. A lapsed date is a reason for the griffie to set `niet-uitgebracht` — a human decision, not automation. Rappel windows (provisional: 14 days before, weekly after) live in the notification trigger config; tuning deferred to a future admin-settings change.

## Nextcloud Integration

- Controllers: none new for CRUD (redundant-controller gate); the guard hooks the existing decision status-transition endpoint/service path — if a thin verantwoording-recording endpoint is needed it lands on the existing governance-scoped controller with `#[NoAdminRequired]` + per-object guard (no-admin-idor/semantic-auth gates; route in `appinfo/routes.php`, route-reachability gate).
- Services: `AdviceAccountabilityGuard` (new — evaluates linked aanvragen/adviezen on the completing decision transition; fail-closed; PUT-semantic saves carrying all fields forward).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications, lifecycle, and publication predicates are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; advies recording and verantwoording recording via explicit dialogs in `src/dialogs`/`src/modals` (modal-isolation gate); Files leaf on the detail page for submitted documents; `NcSelect` usages carry `inputLabel`.

## Security Considerations

- **Authority split:** the aanvraag is writable by the requesting body's staff; the Advies is recorded by the advisory body's secretary — enforced via OR per-object RBAC scoped to the respective governance bodies, not by UI state.
- **Fail-closed accountability:** the guard refuses on evaluation failure — never `catch (\Throwable) { return null; }` treated as "check skipped" (CWE-863); PHPUnit covers the failure branch with a failing relation resolver.
- **Publication:** predicate-on-live-object is staff-explicit only; both schemas carry no internal-only fields by construction (no confidential remarks property), so no writeOnly/render-boundary exposure exists; no app-local anonymous pages.
- **Input validation:** schema-level (required fields, enums, date formats) via OpenRegister validation; the lifecycle map rejects out-of-order transitions server-side.

## File Structure

```
lib/Settings/register.d/60-advisory-opinion-workflow.json   (new — 2 schemas + dialects + seed)
lib/Settings/decidesk_register.json                         (edit — +1 bodyType enum value, +2 optional Decision fields)
src/manifest.d/advisory-opinion-workflow.json               (new — index + detail pages + menu)
src/manifest.json                                           (edit — 2 Dashboard stat widgets)
lib/Service/AdviceAccountabilityGuard.php                   (new — fail-closed verantwoording guard)
appinfo/routes.php                                          (edit — only if a thin verantwoording endpoint is needed)
tests/Unit/Service/AdviceAccountabilityGuardTest.php        (new — full deviation matrix + fail-closed branch)
tests/e2e/advisory-opinion-workflow.spec.ts                 (new — gate-19 coverage)
docs/features/adviesaanvragen.md                            (new)
```

## Seed Data

Realistic Dutch examples for the existing seed municipality context; references use existing decidesk seed objects where available or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder where a cross-seed reference is resolved at import. All seed objects carry the `@self` envelope (`register: decidesk`, schema slug, slug below).

### Schema: `governance-body` (seed additions, existing schema)

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | jongerenraad | adviesraad-sociaal-domein |
| name | "Jongerenraad" | "Adviesraad Sociaal Domein" |
| bodyType | advisory-body | advisory-body |
| workflowTemplate | citizen | legislative |

Members seeded as Person + Membership (REQ-GBD-011): jongerenraad voorzitter "Y. Osei", secretaris "D. Jansen"; adviesraad voorzitter "H. van Dijk", secretaris "P. de Wit" (nil-UUID placeholders where Person seeds resolve at import).

### Schema: `adviesaanvraag`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | adviesaanvraag-jongerenhuisvesting | adviesaanvraag-inkoopbeleid-wmo | adviesaanvraag-skatepark-locatie |
| subject | "Beleidsnota jongerenhuisvesting 2027–2031" | "Herziening inkoopbeleid Wmo-ondersteuning" | "Locatiekeuze skatepark centrum" |
| question | "Sluit de nota aan bij de woonbehoeften van jongeren van 18–27 jaar?" | "Welke gevolgen heeft het voorgenomen inkoopbeleid voor cliënten en mantelzorgers?" | "Welke van de drie kandidaat-locaties heeft de voorkeur van jongeren?" |
| requestingBody | (seed Gemeenteraad body ref) | (seed College body ref, nil-UUID placeholder) | (seed Gemeenteraad body ref) |
| advisoryBody | (jongerenraad seed ref) | (adviesraad-sociaal-domein seed ref) | (jongerenraad seed ref) |
| sentDate | 2026-05-04 | 2026-03-02 | 2026-01-12 |
| requestedByDate | 2026-06-15 *(past at seed time)* | 2026-04-13 | 2026-02-16 |
| lifecycle | in-behandeling | advies-uitgebracht | afgerond |
| relatedDecision | — | (Decision, nil-UUID placeholder) | (Decision, nil-UUID placeholder) |
| advisoryStage | — | (advisory DecisionStage, nil-UUID placeholder) | — |
| verantwoordingText | — | — | "De raad kiest locatie B in afwijking van het advies (locatie A) vanwege geluidsnormen; met de jongerenraad is een evaluatie na één jaar afgesproken." |
| verantwoordingDate | — | — | 2026-03-24 |
| publicatiedatum | — | — | 2026-03-25 |

### Schema: `advies`

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | advies-inkoopbeleid-wmo | advies-skatepark-locatie |
| adviesaanvraag | (adviesaanvraag-inkoopbeleid-wmo ref) | (adviesaanvraag-skatepark-locatie ref) |
| strekking | positief-met-kanttekeningen | negatief |
| samenvatting | "De adviesraad adviseert positief, mits de continuïteit van lopende ondersteuning wordt geborgd en cliënten actief worden geïnformeerd." | "De jongerenraad adviseert negatief over locatie B en positief over locatie A vanwege bereikbaarheid." |
| adviesDate | 2026-04-10 | 2026-02-14 |
| recordedBy | (Person P. de Wit, nil-UUID placeholder) | (Person D. Jansen, nil-UUID placeholder) |
| adviesDocument | (advies PDF, Files link) | (advies PDF, Files link) |
| publicatiedatum | 2026-04-12 | 2026-03-25 |

**Related items per object:** Files: adviesaanvraag-brief PDF on all three aanvragen; advies PDF on both adviezen via the Files leaf. Notes/Tasks/Contacts: none (internal follow-up is a VTODO via the existing action-item flow, deliberately not seeded here).

Object 1's `requestedByDate` lies in the past at seed time while non-terminal (overdue rappel + KPI non-zero); object 2 sits in `advies-uitgebracht` (KPI 2 non-zero) and wraps an in-route advisory stage; object 3 exercises the full flow including a deviating besluit, the recorded verantwoording on both objects, and live publication (ADR-016 testability).

## Migration Plan

1. Land the register.d fragment, the additive base edits (bodyType enum value, Decision verantwoording fields), the manifest.d fragment, the two dashboard widgets, `AdviceAccountabilityGuard` + wiring, seed data, tests, and docs in one decidesk PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schemas on upgrade).
2. No dependency ordering with siblings: fragment number 60 is assigned to this change (40–59 and 61–65 belong to siblings); the enum edit unions with `works-council` / `shared-body` additions.
3. Rollback: revert the PR — the fragment disappears, pages unregister, the enum value/fields/widgets revert (all additive), the guard unhooks restoring previous completion behaviour. Existing Adviesaanvraag/Advies objects remain soft-retained in OR; a `bodyType=advisory-body` body would fail re-validation only on edit and can be re-typed manually.

No data migration — the register starts empty apart from seed data.

## Risks / Trade-offs

- [Guard blocks legitimate completions] → blocking set is narrow (D4); error names the aanvraag and missing motivering; full deviation matrix PHPUnit-covered, mutation-guarded (a test that passes against unfixed code proves nothing).
- [Guard silently fails open] → fail-closed by construction; the failure branch is explicitly tested with a failing relation resolver; no `catch → null → skip` shape (unsafe-auth-resolver gate).
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from the existing Decision schema; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs `adviesaanvraag`/`advies`, never PascalCase.
- [Base-file edits race with wave siblings] → strictly additive (one enum value, two optional fields, two widgets); union merge on conflict, never dropping a sibling's addition.
- [Publication exposes something confidential] → both schemas carry no internal-only fields by construction; publication is staff-explicit predicate-only; public-publication's derived-payload eligibility gates are untouched, so no eligibility regression is possible for other types.
- [Boundary erosion vs WOR/commissie siblings] → boundaries stated in spec Notes and enforced by distinct schemas + nav; mirrored vocabulary is naming-only, no shared objects.

## Open Questions

- Rappel windows (14 days before / weekly after `requestedByDate`) — provisional values in the notification triggers; tuning deferred to a future admin-settings change.
- Whether `positief-met-kanttekeningen` + adopted-with-material-changes should ever trigger the guard (currently: no — sign-only, D4); revisit with adviesraad pilot feedback.
