# Design: works-council-consultation

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). One new OpenRegister schema — `ConsultationRequest` (slug `consultation-request`) — ships as `lib/Settings/register.d/47-works-council-consultation.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file gains only one additive enum value, see D2). All workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/works-council-consultation.json` fragment rendered by `CnPageRenderer` (the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidiq CRUD controllers, per the redundant-controller gate).

Imperative code is limited to one service: `ConsultationResponseDocumentService`, generating the formal advies/instemming document by mirroring `MinutesDocumentService` (markdown canonical, Docudesk PDF opportunistic, honest fallback).

Cross-references, not duplication:
- `ConsultationRequest.achterbanraadpleging` → the sibling change `constituency-consultation`'s raadpleging object; poll mechanics (questions, responses, tallies) live entirely there.
- `ConsultationRequest.overlegvergadering` / `agendaItem` → the universal `Meeting` / `AgendaItem`; agenda building, live meeting, and minutes need zero changes.
- `ConsultationRequest.relatedDecision` → a routed `Decision`; the advisory `DecisionStage` outcome is set per existing decision-methods `method=advice` semantics (actor sets `advised` directly) — no new derivation mechanism.

## Decisions

### D1: ConsultationRequest is a first-class schema, not a Decision subtype and not a PublicConsultation variant

A WOR traject is a statutory request/response *exchange* between the bestuurder and the OR — it has a requester who is not a body member, a legal response obligation with a deadline, a formal response document, and a post-response bestuurder decision with an opschortingstermijn. The `Decision` supertype (ADR-005) models governance *outcomes* with a `draft → … → enacted` guarded lifecycle that does not fit `ontvangen → … → afgerond`, and squeezing the traject in would inherit dozens of never-applicable folded fields. `PublicConsultation` (citizen participation) gathers public reactions with moderation — different actors, different lifecycle, different legal frame. The traject *links* to a Decision (via `relatedDecision` + the advisory stage) when the underlying ondernemersbesluit is modelled as one.

**Alternative considered:** `decisionType: adviesaanvraag` on Decision — rejected per above; also the decision lifecycle transition map is already guarded and shared, and widening it for a request/response exchange would leak WOR states into every decision surface.

### D2: One additive enum value on the base register for `bodyType=works-council`

ADR-037 fragments add schemas; they cannot extend an existing schema's enum (a fragment carrying `GovernanceBody` would replace/merge the whole schema definition and race with siblings). The ondernemingsraad must be the universal GovernanceBody (ADR-006 — never a parallel schema), so `works-council` is added as a one-line direct edit to `decidesk_register.json`'s `bodyType` enum: `['legislative', 'association', 'corporate-board', 'operational', 'citizen-panel', 'supervisory-board', 'executive-board']` + `works-council`. This mirrors the toezeggingen precedent of a minimal direct base edit where fragments structurally cannot reach (its D6 dashboard widget).

**Alternative considered:** reusing `bodyType=operational` for ORs — rejected: the OR is a legally distinct medezeggenschapsorgaan; filters, seeds, and future WOR features need to address it precisely.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative via `x-openregister-{lifecycle,notifications,aggregations,relations}` (+ calculations); imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Statutory flow (`ontvangen → in-behandeling → (achterbanraadpleging) → overlegvergadering → vastgesteld → verzonden → besluit-ontvangen → afgerond`, `ingetrokken` terminal, repeat-overleg loop) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Deadline rappels before/after `requestedResponseDate` + opschorting-expiry notice | `x-openregister-notifications` scheduled triggers (filter on non-terminal lifecycle + date window), nl/en subjects | ADR-031 default for reminders; toezeggingen-register pattern; gate-18 hard-fails imperative dispatch; no bespoke ReminderJob |
| "Afwijkend besluit recorded" notification to OR members | `x-openregister-notifications` `updated` trigger on `besluitOutcome=afwijkend-van-advies` | Event-shaped, declarative |
| `opschortingTot` = `besluitDate` + 1 month (adviesaanvraag + afwijkend only) | `x-openregister-calculations` | Field derivation from sibling fields; if the dialect cannot do date arithmetic, documented fallback: plain date field set in the besluit dialog with server-side validation — never silently wrong |
| Dashboard KPIs "Open WOR-trajecten" / "Reactie over gevraagde datum" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI widget |
| Meeting/agenda/raadpleging/decision linkage | `x-openregister-relations` | Typed relations; reverse lookup via standard OR relation queries |
| Formal response document generation | **Imperative** — `ConsultationResponseDocumentService` | Document rendering + Files persistence + Docudesk delegation with honest fallback is by design imperative in decidiq (`MinutesDocumentService` pattern); no dialect renders documents |
| Advisory-stage outcome on `relatedDecision` | **None new** — existing decision-methods `method=advice` semantics (actor sets `advised` directly in the stage flow) | Reuse; introducing a derivation here would duplicate decision-methods ownership |

### D4: Lifecycle models the WOR practice, not the legal proceedings

States map 1:1 to the statutory traject: ontvangst, behandeling, optionele achterbanraadpleging (art. 17-facilitated but mechanically owned by `constituency-consultation`), overlegvergadering (art. 25 lid 4 verplicht overleg), vaststelling van het advies/de instemming, verzending, bestuurdersbesluit (art. 25 lid 5 schriftelijke mededeling), afronding. `overlegvergadering → in-behandeling` allows repeat overleg rounds (common in practice). `ingetrokken` covers the bestuurder withdrawing the request before the response is sent. Art. 26 beroep and art. 27 lid 4–6 nietigheid/kantonrechter are deliberately NOT states — they are separate legal proceedings (out of scope); the terminal record simply holds `besluitOutcome` + `responseOutcome` for whatever follows outside decidiq.

**Alternative considered:** separate lifecycles per type (advies vs instemming) — rejected: the flows are identical except the opschorting derivation, which is field-conditional, not state-conditional; two lifecycles would double every filter and KPI.

### D5: The requested response date is a request attribute, not a statutory deadline

The WOR sets no fixed response term for the OR (only redelijke termijn); `requestedResponseDate` is what the bestuurder asked. Rappels therefore warn, never block: no lifecycle guard references the date. The overdue KPI ("Reactie over gevraagde datum") gives the OR its own accountability view. Rappel windows (provisional: 14 days before, weekly after) live in the notification trigger config; tuning is deferred to a future admin-settings change.

## Nextcloud Integration

- Controllers: one thin endpoint for response-document generation (existing governance-scoped controller pattern, `#[NoAdminRequired]` + per-object guard — no-admin-idor/semantic-auth gates; route registered in `appinfo/routes.php`, route-reachability gate).
- Services: `ConsultationResponseDocumentService` (new — mirrors `MinutesDocumentService`: markdown canonical into the traject's Files folder, Docudesk PDF opportunistic, honest fallback; saves via `ObjectService::saveObject()` carrying **all** fields forward, PUT-semantic).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications, lifecycle, and calculations are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; besluit recording and document generation via explicit dialogs in `src/dialogs`/`src/modals` (modal-isolation gate); Files leaf on the detail page for submitted documents.

## Security Considerations

- **Confidentiality (high):** adviesaanvragen routinely concern reorganisations and are market-sensitive; `ConsultationRequest` objects stay behind OR RBAC scoped to the OR governance body — **no public publication surface** is introduced by this change (deliberate contrast with the toezeggingenlijst), and no writeOnly fields exist on the schema (no render-boundary exposure).
- **Document generation authority:** the generation endpoint checks governance-body scope per object, not merely the route annotation (semantic-auth gate); generated documents land in the traject's Files folder inheriting its ACL.
- **CSRF/auth posture:** standard NC attributes on the one new controller method; no public app routes.
- **Input validation:** schema-level (required fields, enums, date formats) via OpenRegister validation; the lifecycle map rejects out-of-order transitions server-side.

## File Structure

```
lib/Settings/register.d/47-works-council-consultation.json   (new — schema + dialects + seed)
lib/Settings/decidesk_register.json                          (edit — +1 bodyType enum value)
src/manifest.d/works-council-consultation.json               (new — index + detail pages + menu)
src/manifest.json                                            (edit — 2 Dashboard stat widgets)
lib/Service/ConsultationResponseDocumentService.php          (new — Docudesk delegation + fallback)
lib/Controller/…                                             (edit — generation endpoint on existing controller)
appinfo/routes.php                                           (edit — 1 route)
tests/Unit/Service/ConsultationResponseDocumentServiceTest.php (new)
tests/e2e/works-council-consultation.spec.ts                 (new — gate-19 coverage)
docs/features/wor-trajecten.md                               (new)
```

## Seed Data

Realistic Dutch examples for a fictional company ("Voorbeeldingen B.V.", ondernemingsraad of 9 zetels); references use existing decidiq seed objects where available or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder where a cross-seed reference is resolved at import.

### Schema: `governance-body` (seed addition, existing schema)

| Field | Value |
|-------|-------|
| slug | ondernemingsraad-voorbeeldingen |
| name | "Ondernemingsraad Voorbeeldingen B.V." |
| bodyType | works-council |
| workflowTemplate | operations |

Members seeded as Person + Membership (REQ-GBD-011): voorzitter "S. de Boer", ambtelijk secretaris "K. Willems" (nil-UUID placeholders where Person seeds resolve at import). Bestuurder "M. van den Berg (algemeen directeur)" is seeded as a Person, not a member.

### Schema: `consultation-request`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | adviesaanvraag-reorganisatie-logistiek | instemmingsverzoek-werktijdenregeling | adviesaanvraag-outsourcing-ict |
| type | adviesaanvraag | instemmingsverzoek | adviesaanvraag |
| worArticle | "art. 25 lid 1 sub d WOR" | "art. 27 lid 1 sub b WOR" | "art. 25 lid 1 sub n WOR" |
| subject | "Voorgenomen reorganisatie afdeling logistiek (inkrimping 12 fte)" | "Wijziging werktijdenregeling: invoering zelfroosteren productie" | "Uitbesteding ICT-beheer aan externe dienstverlener" |
| bestuurder | (Person: M. van den Berg, nil-UUID placeholder) | (Person: M. van den Berg, nil-UUID placeholder) | (Person: M. van den Berg, nil-UUID placeholder) |
| governanceBody | (seed OR body ref) | (seed OR body ref) | (seed OR body ref) |
| receivedDate | 2026-05-12 | 2026-06-02 | 2026-01-15 |
| requestedResponseDate | 2026-06-30 *(past at seed time)* | 2026-08-15 | 2026-03-01 |
| lifecycle | overlegvergadering | achterbanraadpleging | afgerond |
| overlegvergadering | (seed meeting ref) | — | (seed meeting ref) |
| agendaItem | (seed agenda item ref) | — | (seed agenda item ref) |
| achterbanraadpleging | — | (constituency-consultation raadpleging, nil-UUID placeholder) | — |
| responseOutcome | — | — | advies-met-voorwaarden |
| responseText | — | — | "De OR adviseert positief onder voorwaarde van een sociaal plan en een evaluatiemoment na 12 maanden." |
| responseDate | — | — | 2026-02-24 |
| responseDocument | — | — | (generated advies PDF, Files link) |
| relatedDecision | — | — | (Decision, nil-UUID placeholder) |
| besluitOutcome | — | — | afwijkend-van-advies |
| besluitText | — | — | "Bestuurder besluit tot uitbesteding zonder evaluatiemoment; sociaal plan wordt overgenomen." |
| besluitDate | — | — | 2026-03-10 |
| opschortingTot | — | — | 2026-04-10 *(derived)* |

**Related items per object:** Files: adviesaanvraag-brief PDF on objects 1 and 3, concept-werktijdenregeling PDF on object 2, generated advies document on object 3 via the Files leaf. Notes/Tasks: none (internal OR follow-up is a VTODO via the existing action-item flow, deliberately not seeded here).

Object 1's `requestedResponseDate` lies in the past at seed time while non-terminal, so both dashboard KPIs are non-zero on a fresh install (ADR-016 testability); object 3 exercises the full flow including the derived opschortingstermijn.

## Migration Plan

1. Land the register.d fragment, the one-line bodyType enum edit, the manifest.d fragment, the two dashboard widgets, `ConsultationResponseDocumentService`, seed data, tests, and docs in one decidiq PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schema on upgrade).
2. `constituency-consultation` (sibling) is a soft reference only — `achterbanraadpleging` is a nullable reference and the lifecycle step is skippable, so the changes land in any order.
3. Rollback: revert the PR — the fragment disappears, pages unregister, the enum value and widgets revert (both additive). Existing ConsultationRequest objects remain soft-retained in OR; a `bodyType=works-council` body would fail re-validation only on edit and can be re-typed manually.

No data migration — the register starts empty apart from seed data.

## Risks / Trade-offs

- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from the existing Decision schema; gates 28/30/51/52 run on register+manifest changes; manifest refs use the slug `consultation-request`, never PascalCase.
- [Calculation dialect can't do `+1 month` date arithmetic] → documented D3 fallback (dialog-set field with server-side validation); PHPUnit asserts the derived/validated value flips when `besluitOutcome` changes (mutation-guarded, no fake green).
- [Base-file edits (enum, dashboard widgets) race with wave siblings] → strictly additive one-liners; union merge on conflict, never dropping a sibling's addition; fragment number 47 is reserved for this change.
- [Confidential traject data reaching a public surface] → no publication path exists by construction; the spec records "no public publication surface" so a future publication change must revisit deliberately.
- [Docudesk absent on the instance] → honest markdown fallback per the resolution-minutes pattern; never a silent fake PDF.

## Open Questions

- Exact `x-openregister-calculations` capability for date arithmetic (D3/Risk 2 in the proposal) — verify against OpenRegister's calculation resolver during apply.
- Rappel windows (14 days before / weekly after `requestedResponseDate`) — provisional values in the notification triggers; tuning deferred to a future admin-settings change.
