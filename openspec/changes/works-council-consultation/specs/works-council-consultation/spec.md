# works-council-consultation Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [works-council-consultation](../../changes/works-council-consultation/)

## Purpose

WOR consultation trajecten for ondernemingsraden: an adviesaanvraag (art. 25 WOR) or instemmingsverzoek (art. 27 WOR) submitted by the bestuurder is registered, treated in overlegvergaderingen, optionally preceded by an achterbanraadpleging, formally answered with an advies or instemming document, and closed by recording the bestuurder's decision — including the art. 25 lid 6 one-month opschortingstermijn when the bestuurder deviates from the advice. The traject is a declarative lifecycle on one `ConsultationRequest` schema (ADR-031), with deadline rappels following the toezeggingen-register notification pattern and document generation following the resolution-minutes Docudesk delegation pattern. It is explicitly not the citizen-participation `PublicConsultation` (public opinion gathering) and not a Decision subtype (the traject is a statutory request/response exchange, not a governance outcome; it may *link* to a routed Decision via `method=advice`).

**Standards**: WOR art. 25/27 (art. 25 lid 6 opschorting), Schema.org (`Action`), OpenRegister dialects (ADR-031)
**Legal reference**: Wet op de ondernemingsraden art. 25, 27, 30

## ADDED Requirements

### Requirement: REQ-WCC-001 ConsultationRequest schema on OpenRegister

The system SHALL define a `ConsultationRequest` schema (slug `consultation-request`) in the decidesk register via the `lib/Settings/register.d/47-works-council-consultation.json` fragment (ADR-037, never by editing existing schemas from the fragment), annotated `x-schema-org: schema:Action` (agent = bestuurder). The schema SHALL carry at minimum: `type` (required enum: `adviesaanvraag`, `instemmingsverzoek`), `worArticle` (required string — the WOR article reference, e.g. "art. 25 lid 1 sub e WOR", free-form so art. 30 requests fit), `subject` (required string), `bestuurder` (required Person reference — the requester, WOR art. 1 lid 1 sub e), `governanceBody` (required GovernanceBody reference — the ondernemingsraad), `receivedDate` (required date), `requestedResponseDate` (date, optional), `lifecycle` (required, see REQ-WCC-002), formal response fields (`responseOutcome` enum: `positief-advies`, `advies-met-voorwaarden`, `negatief-advies`, `instemming-verleend`, `instemming-geweigerd`; `responseText`; `responseDate`; a `responseDocument` DigitalDocument reference), bestuurder decision fields (see REQ-WCC-006), and optional references `overlegvergadering` (→ Meeting), `agendaItem` (→ AgendaItem), `achterbanraadpleging` (see REQ-WCC-003), and `relatedDecision` (→ Decision, see REQ-WCC-005). Submitted documents SHALL attach via the Files leaf / DigitalDocument relations declared in `x-openregister-relations`. Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `consultation-request`.

#### Scenario: Ambtelijk secretaris registers an adviesaanvraag

- GIVEN an ondernemingsraad governance body and the bestuurder as a Person in the register
- WHEN the secretaris registers a ConsultationRequest with `type=adviesaanvraag`, `worArticle="art. 25 lid 1 sub d WOR"`, a subject, the bestuurder, the received date, and a requested response date
- THEN a `consultation-request` object is created in the decidesk register linked to the OR body and the bestuurder
- AND a create missing `type`, `subject`, `bestuurder`, or `receivedDate` is rejected by OpenRegister schema validation

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the ConsultationRequest schema is registered from the `47-works-council-consultation.json` fragment
- AND no existing schema is modified by the fragment

### Requirement: REQ-WCC-002 Statutory flow is a declarative lifecycle

The `ConsultationRequest` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `lifecycle`, initial `ontvangen`, states `ontvangen → in-behandeling → (achterbanraadpleging) → overlegvergadering → vastgesteld → verzonden → besluit-ontvangen → afgerond`, where `achterbanraadpleging` is an optional step (`in-behandeling` transitions to either `achterbanraadpleging` or directly to `overlegvergadering`, and `achterbanraadpleging → overlegvergadering`), `overlegvergadering → in-behandeling` is allowed (repeat overleg rounds), and `ingetrokken` is reachable from every non-terminal state before `verzonden` (the bestuurder withdraws the request). `afgerond` and `ingetrokken` SHALL be terminal. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Traject runs the full statutory flow

- GIVEN a ConsultationRequest in lifecycle `ontvangen`
- WHEN the OR takes it in behandeling, treats it in an overlegvergadering, formally adopts the advies, and sends it
- THEN each transition `ontvangen → in-behandeling → overlegvergadering → vastgesteld → verzonden` is accepted by the declared transition map
- AND recording the bestuurder's decision and closing move it through `besluit-ontvangen` to terminal `afgerond`

#### Scenario: Achterbanraadpleging is optional

- GIVEN a ConsultationRequest in lifecycle `in-behandeling`
- WHEN the OR skips constituency consultation
- THEN the direct transition `in-behandeling → overlegvergadering` is accepted
- AND a traject that does consult transitions `in-behandeling → achterbanraadpleging → overlegvergadering`

#### Scenario: Invalid transition rejected declaratively

- GIVEN a ConsultationRequest in lifecycle `afgerond`
- WHEN any user attempts to set the lifecycle back to `in-behandeling`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-WCC-003 Achterbanraadpleging references constituency-consultation

The optional `achterbanraadpleging` step SHALL be represented by the `achterbanraadpleging` reference on `ConsultationRequest` pointing at a raadpleging/poll object owned by the `constituency-consultation` capability (sibling change). This capability SHALL NOT define poll/ballot/response mechanics of its own — no question schema, no response tallying, no participant management. When the referenced capability is not yet installed or the reference is empty, the lifecycle step SHALL remain usable as a plain status (the step is skippable per REQ-WCC-002) and the detail page SHALL degrade to showing no linked raadpleging.

#### Scenario: Traject links its achterbanraadpleging

- GIVEN a ConsultationRequest in lifecycle `achterbanraadpleging`
- WHEN the OR links the constituency-consultation raadpleging it started for this traject
- THEN the detail page shows the linked raadpleging as a navigable reference
- AND no poll mechanics (questions, responses, tallies) are stored on the ConsultationRequest itself

#### Scenario: Step degrades without the sibling capability

- GIVEN a decidesk instance where the constituency-consultation capability is not present
- WHEN a traject passes through lifecycle `achterbanraadpleging` without a linked raadpleging
- THEN the transition is accepted and the detail page renders without error, showing an empty raadpleging reference

### Requirement: REQ-WCC-004 Overlegvergadering and agenda linkage

A ConsultationRequest SHALL be treatable in OR meetings as an agenda item: the `overlegvergadering` reference (→ Meeting) and `agendaItem` reference (→ AgendaItem) SHALL be declared in `x-openregister-relations`, so the traject links to the overlegvergadering (WOR art. 25 lid 4) where it is discussed, and the meeting's agenda item links back to the traject. The linkage SHALL reuse the universal Meeting/AgendaItem schemas — no works-council-specific meeting schema SHALL be introduced (the OR is a GovernanceBody per REQ-WCC-009, and its meetings are universal meetings per governance-bodies REQ-GBD-003).

#### Scenario: Adviesaanvraag agendized on an overlegvergadering

- GIVEN a ConsultationRequest in `in-behandeling` and a scheduled meeting of the ondernemingsraad body
- WHEN the secretaris links the traject to that meeting and its agenda item
- THEN the traject detail shows the overlegvergadering as a navigable reference
- AND the traject can transition to lifecycle `overlegvergadering`

#### Scenario: No parallel meeting schema

- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no works-council-specific meeting or agenda schema exists; the traject references the universal `Meeting` and `AgendaItem` schemas

### Requirement: REQ-WCC-005 Formal response document and decision linkage

The system SHALL generate the formal advies/instemming document from the ConsultationRequest's response fields via a `ConsultationResponseDocumentService` that reuses the resolution-minutes Docudesk delegation pattern: the markdown document is canonical and persisted to Nextcloud Files linked to the traject; PDF rendering via Docudesk is opportunistic; when Docudesk is not installed the system SHALL still persist the markdown document and state honestly that PDF rendering was unavailable — it SHALL NOT fail or silently pretend a PDF was produced. The generated document SHALL be linked via `responseDocument`. When the underlying ondernemersbesluit is modelled as a routed `Decision` with an advisory `DecisionStage` assigned to the OR body, the ConsultationRequest's `relatedDecision` reference SHALL link to it and the formal response SHALL be recorded on that stage per the existing decision-methods `method=advice` semantics (the actor sets the non-binding `advised`/`deferred` outcome directly); this capability SHALL NOT introduce a new outcome-derivation mechanism.

#### Scenario: Formal advies document generated with Docudesk present

- GIVEN a ConsultationRequest in `vastgesteld` with `responseOutcome=advies-met-voorwaarden` and response text
- WHEN the secretaris triggers "Generate response document"
- THEN a markdown document is persisted to the traject's Files folder and a PDF is rendered via Docudesk
- AND the document is linked as `responseDocument` on the traject

#### Scenario: Honest fallback without Docudesk

- GIVEN the same traject on an instance without the Docudesk app
- WHEN the secretaris triggers "Generate response document" with the PDF format
- THEN the markdown document is persisted and the response states that Docudesk was unavailable and a markdown fallback was produced

#### Scenario: Response feeds a routed decision's advisory stage

- GIVEN a routed Decision with an advisory DecisionStage (`method=advice`) assigned to the ondernemingsraad body and a ConsultationRequest with `relatedDecision` set to that decision
- WHEN the OR's formal response is recorded
- THEN the stage outcome is set to the non-binding value (`advised`) per decision-methods semantics
- AND no new derivation mechanism is introduced by this capability

### Requirement: REQ-WCC-006 Bestuurder decision recording and art. 25 lid 6 opschortingstermijn

The `ConsultationRequest` schema SHALL carry bestuurder decision fields: `besluitOutcome` (enum: `conform-advies`, `afwijkend-van-advies`, `conform-instemming`, `niet-doorgezet`), `besluitText` (string), and `besluitDate` (date). Recording the besluit SHALL accompany the transition to lifecycle `besluit-ontvangen`. For an `adviesaanvraag` whose `besluitOutcome=afwijkend-van-advies`, the system SHALL derive `opschortingTot` (date) as `besluitDate` plus one month via `x-openregister-calculations` (WOR art. 25 lid 6: the bestuurder must suspend execution for one month when deviating from the advice), and the detail page SHALL surface the running opschortingstermijn. The derivation SHALL NOT apply to instemmingsverzoeken or to besluiten conform advies. A declarative notification SHALL inform the OR members when an afwijkend besluit is recorded.

#### Scenario: Afwijkend besluit derives the opschortingstermijn

- GIVEN an adviesaanvraag traject in `verzonden` with `responseOutcome=negatief-advies`
- WHEN the secretaris records `besluitOutcome=afwijkend-van-advies` with `besluitDate=2026-09-01`
- THEN `opschortingTot` derives to 2026-10-01 and the traject transitions to `besluit-ontvangen`
- AND the OR members receive a notification that the bestuurder deviated from the advies

#### Scenario: Conform besluit has no opschorting

- GIVEN an adviesaanvraag traject with `responseOutcome=positief-advies`
- WHEN the secretaris records `besluitOutcome=conform-advies`
- THEN `opschortingTot` remains empty and the traject can close to `afgerond`

#### Scenario: Instemmingsverzoek records refusal without opschorting

- GIVEN an instemmingsverzoek traject with `responseOutcome=instemming-geweigerd`
- WHEN the bestuurder's reaction is recorded as `besluitOutcome=niet-doorgezet`
- THEN no `opschortingTot` is derived (art. 25 lid 6 applies to adviesaanvragen only)
- AND further legal steps (nietigheid, kantonrechter) are outside this capability

### Requirement: REQ-WCC-007 Deadline tracking with declarative rappels

Deadline reminders on `requestedResponseDate` SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031, the toezeggingen-register pattern): a scheduled trigger that notifies the OR's recipients when a non-terminal traject approaches its requested response date, and a scheduled trigger when the traject is past that date without a recorded response, both with Dutch and English subjects. An additional scheduled trigger SHALL notify when a running `opschortingTot` expires. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Rappel before the requested response date

- GIVEN a ConsultationRequest in `in-behandeling` whose `requestedResponseDate` is within the configured rappel window
- WHEN the scheduled notification trigger evaluates
- THEN the recipients receive a Nextcloud notification referencing the traject

#### Scenario: Overdue rappel

- GIVEN a ConsultationRequest in a non-terminal lifecycle before `verzonden` whose `requestedResponseDate` is in the past
- WHEN the scheduled notification trigger evaluates
- THEN an overdue notification is sent
- AND no notification is sent for trajecten in `afgerond` or `ingetrokken`

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the works-council-consultation code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-WCC-008 List and detail pages plus dashboard KPIs

The system SHALL provide a WOR-trajecten index page and a ConsultationRequestDetail page as manifest pages in the `src/manifest.d/works-council-consultation.json` fragment (ADR-037), following manifest-v2 conventions (`register: decidesk`, `schema: consultation-request`, columns for subject, type, worArticle, bestuurder, receivedDate, requestedResponseDate, lifecycle; quick filters on type, lifecycle, and governance body). The detail page SHALL show all fields, the linked overlegvergadering/agenda item/raadpleging/decision as navigable references, the Files leaf for submitted documents, and the running opschortingstermijn when set. The Dashboard SHALL carry two declarative stat widgets sourced via manifest widget aggregation (`register: decidesk`, `schema: consultation-request`, `metric: count`, no imperative counting endpoint): "Open WOR-trajecten" (non-terminal lifecycle) and "Reactie over gevraagde datum" (non-terminal before `verzonden` with `requestedResponseDate` in the past), each routing to the index pre-filtered to the same set.

#### Scenario: Secretaris browses and filters the trajecten

- GIVEN registered trajecten of both types across lifecycles
- WHEN the secretaris opens the WOR-trajecten page and filters on `type=instemmingsverzoek` and lifecycle `in-behandeling`
- THEN only matching trajecten are listed
- AND clicking a row opens the detail page showing all fields and the linked references

#### Scenario: KPIs count the right sets

- GIVEN two open trajecten (one past its requested response date), one traject in `verzonden`, and one `afgerond`
- WHEN the dashboard renders
- THEN "Open WOR-trajecten" shows 3 (non-terminal) and "Reactie over gevraagde datum" shows 1
- AND clicking a KPI opens the index pre-filtered to that set

### Requirement: REQ-WCC-009 Works-council body type on GovernanceBody

The `GovernanceBody` schema's `bodyType` enum SHALL include the value `works-council` (added additively to the base `decidesk_register.json` — a fragment cannot extend an existing schema's enum), so an ondernemingsraad is the universal GovernanceBody (consistent with ADR-006 and governance-bodies REQ-GBD-012: never a separate schema). OR members SHALL relate to the body via the existing Person + Membership model (governance-bodies REQ-GBD-011). A seeded ondernemingsraad body SHALL make the domain demonstrable on install.

#### Scenario: Ondernemingsraad is a universal governance body

- GIVEN the register is imported on a clean instance
- WHEN the `GovernanceBody` schema's `bodyType` enum is inspected
- THEN it includes `works-council` alongside the existing values
- AND a seeded governance body with `bodyType=works-council` exists with members as Person + Membership records

#### Scenario: No parallel works-council schema

@e2e exclude register-schema-structure invariant — verified by register-import + PHPUnit, not browser-observable
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no separate `works-council` or `ondernemingsraad` schema exists; the OR is a `governance-body` object

## Non-Functional Requirements

- **Performance:** the trajecten index paginates via the standard OR list API; both KPIs are single count aggregations (no N+1).
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail/stat) which carry the fleet's gate-checked semantics; no dragging, no auth flows, no new help surfaces introduced by this capability.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] ConsultationRequest schema registers from fragment 47 and validates required fields; every property carries a title
- [ ] Lifecycle transitions are enforced by x-openregister-lifecycle only (canonical `initial` keyword; no app-side state machine)
- [ ] Achterbanraadpleging step links to constituency-consultation objects and degrades gracefully; no poll mechanics duplicated
- [ ] Overlegvergadering/agenda linkage uses the universal Meeting/AgendaItem schemas
- [ ] Response document generation follows the Docudesk-with-honest-fallback pattern; method=advice linkage sets the advisory stage outcome without new derivation machinery
- [ ] Afwijkend besluit on an adviesaanvraag derives opschortingTot (+1 month) and notifies the OR; conform besluiten and instemmingsverzoeken derive nothing
- [ ] Rappels fire declaratively before and after requestedResponseDate and at opschorting expiry, never for terminal states
- [ ] Index/detail pages render from the manifest fragment; both dashboard KPIs count declaratively and deep-link pre-filtered
- [ ] `works-council` bodyType exists and an ondernemingsraad body is seeded

## Notes

- Related: `constituency-consultation` (achterbanraadpleging poll mechanics — referenced, never duplicated), `decision-route`/`decision-methods` (`method=advice` advisory-stage semantics — reused, not modified), `resolution-minutes` (Docudesk delegation pattern), `toezeggingen-register` (declarative rappel + lifecycle dialect pattern), `governance-bodies` (the OR is a GovernanceBody).
- Distinct from citizen-participation's `PublicConsultation`: that schema gathers public reactions; `ConsultationRequest` is a statutory bestuurder→OR request with a legal response obligation.
- Out of scope (proposal): Ondernemingskamer beroep (art. 26), nietigheid/kantonrechter after geweigerde instemming, CAO interpretation, employer-side tooling.
