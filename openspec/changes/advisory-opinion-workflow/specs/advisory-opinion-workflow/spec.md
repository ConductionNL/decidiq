# advisory-opinion-workflow Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [advisory-opinion-workflow](../../changes/advisory-opinion-workflow/)

## Purpose

Formal advisory-opinion trajecten (adviesaanvraag → advies → verantwoording) between a deciding body and an external advisory body — jongerenraad, adviesraad sociaal domein, cliëntenraad — modelled as universal GovernanceBody objects (the p3-citizen-participation "citizen advisory body" Organization precedent). An `Adviesaanvraag` registers the request (who asks whom, the question, the linked decision, the requested-by date), an `Advies` artifact records the advisory body's formal response (document, summary, strekking), and a mandatory verantwoording fail-closed guard blocks decision completion when the final decision deviates from the advies strekking without a recorded motivering. In-route advice stages keep using decision-methods `method=advice` (a body records `advised`/`deferred` — the mechanics exist); this capability adds the REQUEST/RESPONSE/ACCOUNTABILITY wrapper around the route and covers the out-of-route case. It is explicitly not WOR medezeggenschap (`works-council-consultation` — statutory employee participation) and not raadscommissie advies (`commissievergaderingen` — internal committee advice riding the route).

**Standards**: Schema.org (`AskAction`, `Recommendation`), OpenRegister dialects (ADR-031), Gemeentewet/Participatiewet/Wmo adviesorgaan practice
**ORI note**: OpenRaadsinformatie defines no adviesaanvraag/advies type; the `x-schema-org` marker convention is used instead.

## ADDED Requirements

### Requirement: REQ-AOW-001 Adviesaanvraag schema on OpenRegister

The system SHALL define an `Adviesaanvraag` schema (slug `adviesaanvraag`) in the decidesk register via the `lib/Settings/register.d/60-advisory-opinion-workflow.json` fragment (ADR-037, never by editing existing schemas from the fragment), annotated `x-schema-org: schema:AskAction` (agent = requesting body). The schema SHALL carry at minimum: `subject` (required string), `question` (required string — the adviesvraag posed), `requestingBody` (required GovernanceBody reference — the deciding body), `advisoryBody` (required GovernanceBody reference — the external advisory body; advisory bodies are universal GovernanceBody objects, never a parallel schema), `sentDate` (required date), `requestedByDate` (date, optional — when the advies is requested by), `lifecycle` (required, see REQ-AOW-002), verantwoording fields (`verantwoordingText` string, `verantwoordingDate` date, both optional — see REQ-AOW-005), publication predicate fields (`publicatiedatum` / `depublicatiedatum`, see REQ-AOW-007), and optional references `relatedDecision` (→ Decision), `agendaItem` (→ AgendaItem), and `advisoryStage` (→ DecisionStage, see REQ-AOW-004). Submitted documents SHALL attach via the Files leaf / DigitalDocument relations declared in `x-openregister-relations`. Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `adviesaanvraag`.

#### Scenario: Griffie registers an adviesaanvraag to the jongerenraad

- GIVEN a deciding body "Gemeenteraad" and an advisory body "Jongerenraad" as GovernanceBody objects
- WHEN the griffie registers an Adviesaanvraag with a subject, the question posed, both body references, the sent date, and a requested-by date
- THEN an `adviesaanvraag` object is created in the decidesk register linked to both bodies
- AND a create missing `subject`, `question`, `requestingBody`, `advisoryBody`, or `sentDate` is rejected by OpenRegister schema validation

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the Adviesaanvraag and Advies schemas are registered from the `60-advisory-opinion-workflow.json` fragment
- AND no existing schema is modified by the fragment

### Requirement: REQ-AOW-002 Traject is a declarative lifecycle

The `Adviesaanvraag` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `lifecycle`, initial `verzonden`, transitions `verzonden → in-behandeling`, `in-behandeling → advies-uitgebracht`, `advies-uitgebracht → verantwoord` (a deviating besluit is recorded with its motivering), `advies-uitgebracht → afgerond` (the final decision conforms to the advies — no verantwoording needed), `verantwoord → afgerond`, and `niet-uitgebracht` reachable from `verzonden` and `in-behandeling` (the advisory body declines, the request is withdrawn, or the requested-by date lapses without advies). `afgerond` and `niet-uitgebracht` SHALL be terminal. The app SHALL NOT implement an imperative state machine for this lifecycle (the REQ-AOW-005 guard blocks a Decision transition; it never drives the Adviesaanvraag lifecycle).

#### Scenario: Traject runs the full flow with verantwoording

- GIVEN an Adviesaanvraag in lifecycle `verzonden`
- WHEN the advisory body takes it in behandeling, its secretary records the Advies, the deciding body decides deviatingly and records the verantwoording, and the traject is closed
- THEN each transition `verzonden → in-behandeling → advies-uitgebracht → verantwoord → afgerond` is accepted by the declared transition map

#### Scenario: Conform decision closes without verantwoording

- GIVEN an Adviesaanvraag in lifecycle `advies-uitgebracht` whose linked decision followed the advies strekking
- WHEN the traject is closed
- THEN the direct transition `advies-uitgebracht → afgerond` is accepted with no verantwoording recorded

#### Scenario: Invalid transition rejected declaratively

- GIVEN an Adviesaanvraag in lifecycle `afgerond`
- WHEN any user attempts to set the lifecycle back to `in-behandeling`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-AOW-003 Advies artifact schema

The system SHALL define an `Advies` schema (slug `advies`) in the decidesk register via the same fragment, annotated `x-schema-org: schema:Recommendation`, carrying the advisory body's formal response: `adviesaanvraag` (required Adviesaanvraag reference, many-to-one), `strekking` (required enum: `positief`, `positief-met-kanttekeningen`, `negatief`, `geen-advies`), `samenvatting` (required string — the summary), `adviesDate` (required date), `recordedBy` (required Person reference — the advisory body's secretary who records it), `adviesDocument` (optional DigitalDocument reference — the response document, attached via the Files leaf), and publication predicate fields (`publicatiedatum` / `depublicatiedatum`). Every property SHALL carry a `title`; the manifest and all widget/filter sources SHALL reference the schema by its slug `advies`. Recording an Advies SHALL accompany the Adviesaanvraag transition to `advies-uitgebracht`.

#### Scenario: Advisory body's secretary records the advies

- GIVEN an Adviesaanvraag in lifecycle `in-behandeling`
- WHEN the advisory body's secretary records an Advies with `strekking=positief-met-kanttekeningen`, a summary, the advies date, and the response document
- THEN an `advies` object is created linked to the aanvraag and the aanvraag transitions to `advies-uitgebracht`
- AND a create missing `adviesaanvraag`, `strekking`, `samenvatting`, `adviesDate`, or `recordedBy` is rejected by OpenRegister schema validation

#### Scenario: Advisory body issues no advice

- GIVEN an Adviesaanvraag in lifecycle `in-behandeling` whose advisory body declines to advise
- WHEN the traject is set to `niet-uitgebracht`
- THEN no Advies object is required, the traject is terminal, and the linked decision is never blocked by REQ-AOW-005

### Requirement: REQ-AOW-004 In-route and out-of-route boundary with decision-methods

In-route advice stages SHALL keep using the existing decision-methods `method=advice` semantics: when the advisory body sits IN the decision route as an advisory `DecisionStage`, the stage actor sets the non-binding `advised`/`deferred` outcome directly, and this capability SHALL NOT introduce a new outcome-derivation mechanism or modify the stage lifecycle. The Adviesaanvraag SHALL wrap such a stage via its optional `advisoryStage` reference (→ DecisionStage), adding the request metadata (question, dates), the Advies artifact, and the verantwoording accountability that decision-methods does not model. For the out-of-route case (an advisory body consulted outside any route), the Adviesaanvraag SHALL stand alone with `advisoryStage` empty and `relatedDecision` optional. Raadscommissie advies on plenary agenda items remains owned by `commissievergaderingen` and is NOT registered through this capability.

#### Scenario: Aanvraag wraps an in-route advisory stage

- GIVEN a routed Decision with an advisory DecisionStage (`method=advice`) assigned to the adviesraad sociaal domein body
- WHEN the griffie registers an Adviesaanvraag with `relatedDecision` and `advisoryStage` set and the advisory body later records its Advies
- THEN the stage actor sets the stage outcome to `advised` per existing decision-methods semantics (no new derivation mechanism)
- AND the aanvraag carries the question, dates, Advies artifact, and verantwoording that the stage does not model

#### Scenario: Out-of-route advies stands alone

- GIVEN a jongerenraad consulted on a subject with no routed Decision
- WHEN an Adviesaanvraag is registered with `advisoryStage` and `relatedDecision` empty
- THEN the traject runs its full lifecycle and the detail page renders without error, showing no linked decision

### Requirement: REQ-AOW-005 Mandatory verantwoording on deviation is fail-closed

When a Decision linked to an Adviesaanvraag (via `relatedDecision`) reaches its completing transition while the aanvraag has an Advies whose `strekking` deviates from the decision outcome, the system SHALL refuse the transition until a verantwoording (motivering) is recorded — a server-side fail-closed guard (`AdviceAccountabilityGuard`) wired into the existing decision status-transition path, mirroring the decision lifecycle-guard precedents. Deviation SHALL be defined mechanically on the sign only: `strekking` in (`positief`, `positief-met-kanttekeningen`) with a rejected outcome, or `strekking=negatief` with an adopted outcome; `geen-advies`, a conform outcome, a traject in `niet-uitgebracht`, and a decision without linked aanvragen SHALL never be blocked. When the guard cannot evaluate (e.g. relation resolution failure), it SHALL refuse the transition with an explanatory error — never silently allow it. Recording the verantwoording SHALL store the motivering on BOTH the Decision (additive `verantwoording` fields on the base register) and the Adviesaanvraag (`verantwoordingText` + `verantwoordingDate`, accompanying the transition to `verantwoord`), and a declarative notification SHALL inform the advisory body's members when a deviating besluit is verantwoord.

#### Scenario: Deviating decision blocked without motivering

- GIVEN a Decision with an Adviesaanvraag whose Advies has `strekking=negatief`
- WHEN the deciding body attempts to complete the decision with outcome adopted and no verantwoording recorded
- THEN the guard refuses the completing transition with an error naming the aanvraag and the missing motivering
- AND no partial state is written

#### Scenario: Verantwoording unblocks and lands on both objects

- GIVEN the same blocked decision
- WHEN the griffie records the verantwoording motivering
- THEN the motivering is stored on the Decision's verantwoording fields and on the Adviesaanvraag (`verantwoordingText`, transition to `verantwoord`), the completing transition is accepted, and the advisory body's members receive a declarative notification

#### Scenario: Conform decision never blocked

@e2e exclude guard-matrix contract — covered by PHPUnit on AdviceAccountabilityGuard plus Newman against the transition endpoint
- GIVEN a Decision whose linked Advies has `strekking=positief` and whose outcome is adopted
- WHEN the completing transition runs
- THEN the guard does not block, and the same holds for `geen-advies`, `niet-uitgebracht` trajecten, and decisions with no linked aanvraag

#### Scenario: Guard failure fails closed

@e2e exclude fail-mode contract — covered by PHPUnit with a failing relation resolver
- GIVEN the guard cannot resolve the aanvraag or advies during evaluation
- WHEN the completing transition runs
- THEN the transition is refused with an explanatory error rather than silently allowed

### Requirement: REQ-AOW-006 Advisory-body workload views with declarative rappels

The system SHALL give each advisory body a workload view: the adviesaanvragen index filterable to one advisory body showing its open (non-terminal) aanvragen with their `requestedByDate` deadlines. Deadline rappels SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031, the toezeggingen-register pattern): a scheduled trigger notifying the advisory body's recipients when a non-terminal aanvraag approaches its `requestedByDate`, and a scheduled trigger when it is past that date without an Advies recorded, both with Dutch and English subjects. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Advisory body sees its open workload

- GIVEN aanvragen for two advisory bodies across lifecycles
- WHEN the jongerenraad's secretary filters the index to the jongerenraad
- THEN only that body's non-terminal aanvragen are listed with their requested-by dates

#### Scenario: Rappel before and after the requested-by date

- GIVEN a non-terminal Adviesaanvraag whose `requestedByDate` is within the configured rappel window, and another past that date without a recorded Advies
- WHEN the scheduled notification triggers evaluate
- THEN the advisory body's recipients receive a pre-deadline rappel for the first and an overdue rappel for the second
- AND no notification is sent for trajecten in `afgerond` or `niet-uitgebracht`

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the advisory-opinion-workflow code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-AOW-007 Public publication of advies and verantwoording via the OR published-predicate

The system SHALL make published adviezen and their verantwoording available through OpenRegister's anonymous RBAC published-predicate surface (the toezeggingen predicate-on-live-object pattern): the `Advies` and `Adviesaanvraag` schemas each declare an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`, and staff with governance-body authority publish by setting `publicatiedatum` (withdraw by setting `depublicatiedatum`). Because the predicate sits on the live objects, a verantwoording recorded after publication SHALL be publicly visible without republication. Neither schema SHALL carry internal-only fields (no confidential remarks property), so the whole object is publishable by construction. The system SHALL NOT serve app-local anonymous pages, SHALL never publish without an explicit staff action, and SHALL NOT modify the public-publication capability's eligibility-gates requirement (this capability publishes live objects, not derived payloads).

#### Scenario: Published advies with verantwoording is anonymously readable

- GIVEN an Advies and its Adviesaanvraag published by the griffie (publicatiedatum in the past)
- WHEN an unauthenticated client reads the OR published-predicate surface
- THEN the advies is returned with its strekking and samenvatting, and the aanvraag with its question and verantwoording

#### Scenario: Later verantwoording is live on the public surface

- GIVEN a published Adviesaanvraag in `advies-uitgebracht`
- WHEN the verantwoording is recorded and the traject moves to `verantwoord`
- THEN the next anonymous read shows the verantwoordingText without any republish step

#### Scenario: Unpublished traject is not public

@e2e exclude predicate contract — covered by Newman against the OR published surface
- GIVEN an Adviesaanvraag and Advies without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN neither object is returned

### Requirement: REQ-AOW-008 List and detail pages plus dashboard KPIs

The system SHALL provide an Adviesaanvragen index page and an AdviesaanvraagDetail page as manifest pages in the `src/manifest.d/advisory-opinion-workflow.json` fragment (ADR-037), following manifest-v2 conventions (`register: decidesk`, `schema: adviesaanvraag`, columns for subject, advisoryBody, requestingBody, sentDate, requestedByDate, lifecycle; quick filters on advisory body, requesting body, and lifecycle). The detail page SHALL show the question, all dates, the linked decision/agenda item/advisory stage as navigable references, the Files leaf for submitted documents, the recorded Advies (strekking, samenvatting, document), and the verantwoording when set. The Dashboard SHALL carry two declarative stat widgets sourced via manifest widget aggregation (`register: decidesk`, `schema: adviesaanvraag`, `metric: count`, no imperative counting endpoint): "Open adviesaanvragen" (non-terminal lifecycle) and "Adviezen wachtend op afdoening" (lifecycle `advies-uitgebracht`), each routing to the index pre-filtered to the same set.

#### Scenario: Griffie browses and filters the aanvragen

- GIVEN registered aanvragen across advisory bodies and lifecycles
- WHEN the griffie opens the Adviesaanvragen page and filters on the adviesraad sociaal domein and lifecycle `in-behandeling`
- THEN only matching aanvragen are listed
- AND clicking a row opens the detail page showing the question, references, documents, advies, and verantwoording

#### Scenario: KPIs count the right sets

- GIVEN two aanvragen in `in-behandeling`, one in `advies-uitgebracht`, and one `afgerond`
- WHEN the dashboard renders
- THEN "Open adviesaanvragen" shows 3 (non-terminal) and "Adviezen wachtend op afdoening" shows 1
- AND clicking a KPI opens the index pre-filtered to that set

### Requirement: REQ-AOW-009 Advisory-body bodyType on GovernanceBody and seed data

The `GovernanceBody` schema's `bodyType` enum SHALL include the value `advisory-body` (added additively to the base `decidesk_register.json` — a fragment cannot extend an existing schema's enum; the works-council/shared-body precedent), so jongerenraden, adviesraden sociaal domein, and cliëntenraden are universal GovernanceBody objects (ADR-006, never a parallel schema) whose members relate via Person + Membership (governance-bodies REQ-GBD-011) and whose internal meetings use the existing meetings machinery unchanged. Seed data SHALL make the domain demonstrable on install: at least two seeded advisory bodies and adviesaanvraag/advies seeds covering an open traject past its requested-by date, an advies-uitgebracht traject, and a completed traject with a deviating besluit and recorded verantwoording, so both dashboard KPIs are non-zero (ADR-016 testability).

#### Scenario: Advisory bodies are universal governance bodies

- GIVEN the register is imported on a clean instance
- WHEN the `GovernanceBody` schema's `bodyType` enum is inspected
- THEN it includes `advisory-body` alongside the existing values
- AND seeded advisory bodies "Jongerenraad" and "Adviesraad Sociaal Domein" exist with members as Person + Membership records

#### Scenario: No parallel advisory-body schema

@e2e exclude register-schema-structure invariant — verified by register-import + PHPUnit, not browser-observable
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no separate `jongerenraad` or `adviesraad` schema exists; advisory bodies are `governance-body` objects and their meetings are universal meetings

## Non-Functional Requirements

- **Performance:** the aanvragen index paginates via the standard OR list API; both KPIs are single count aggregations (no N+1); the guard evaluates only the aanvragen related to the transitioning decision.
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail/stat) which carry the fleet's gate-checked semantics; no dragging, no auth flows, no new help surfaces introduced by this capability.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] Adviesaanvraag and Advies schemas register from fragment 60 and validate required fields; every property carries a title
- [ ] Lifecycle transitions are enforced by x-openregister-lifecycle only (canonical `initial` keyword; no app-side state machine)
- [ ] In-route advisory stages keep decision-methods `method=advice` semantics; the aanvraag wraps them without new derivation machinery
- [ ] A deviating decision cannot complete without a verantwoording; the guard fails closed and the motivering lands on both Decision and Adviesaanvraag
- [ ] Rappels fire declaratively before and after requestedByDate, never for terminal states
- [ ] Published adviezen + verantwoording are anonymously readable via the OR predicate surface; unpublished ones are not; public-publication's eligibility gates are untouched
- [ ] Index/detail pages render from the manifest fragment; both dashboard KPIs count declaratively and deep-link pre-filtered
- [ ] `advisory-body` bodyType exists and two advisory bodies plus trajecten are seeded

## Notes

- Related: `decision-route`/`decision-methods` (`method=advice` in-route semantics — reused, never modified), `commissievergaderingen` (raadscommissie advies stays there), `works-council-consultation` (sibling boundary: WOR = statutory employee participation with opschortingstermijn; this = advisory opinions from external adviesorganen with deviation-verantwoording; vocabulary mirrored where sensible), `toezeggingen-register` (declarative rappel + predicate-on-live-object patterns), `governance-bodies` (advisory bodies are GovernanceBody), `p3-citizen-participation` (citizen advisory body Organization precedent; citizen panels stay there).
- ORI/OpenRaadsinformatie defines no adviesaanvraag/advies type; `x-schema-org` annotations (`schema:AskAction`, `schema:Recommendation`) are used per the register's marker convention.
- Deviation is sign-only by design; honouring kanttekeningen is political judgment outside the guard (see proposal Risk 2).
