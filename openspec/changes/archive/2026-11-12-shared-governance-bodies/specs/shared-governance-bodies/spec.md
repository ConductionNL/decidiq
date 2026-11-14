# shared-governance-bodies Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [shared-governance-bodies](../../changes/shared-governance-bodies/)

## Purpose

Shared governance bodies (gemeenschappelijke regelingen per Wgr, joint committees, federaties): a shared GovernanceBody — e.g. the bestuur of an uitvoeringsorganisatie carried by three municipalities, like the SED organisatie — gains a queryable roster of member organisations via `BodyParticipation` (seats, voting weight, toetreding/uittreding), memberships in the shared body record namens which participating organisation the person sits, and the core Wgr accountability workflow runs as the zienswijzeprocedure: the shared body opens a `Zienswijzeronde` on a document or decision (e.g. de ontwerpbegroting, Wgr art. 35), each participating organisation receives a tracked `Zienswijze` with a deadline and declarative rappels (toezeggingen-register dialect), records its standpunt and response document, and the shared body aggregates the zienswijzen and records how they were processed. Weighted voting inside the shared body reuses the existing Membership `votingWeight` machinery (meeting-attendees REQ-MAT-006) — this capability adds no voting mechanics. All participating organisations share one Nextcloud instance; cross-instance OCM federation is explicitly future work.

**Standards**: Wet gemeenschappelijke regelingen (art. 35 zienswijzeprocedure), W3C org ontology / Popolo (`org:Membership` with an organisation as member; `on_behalf_of` provenance), Schema.org (`schema:Organization`, `schema:Action`), OpenRegister dialects (ADR-031)

## ADDED Requirements

### Requirement: REQ-SGB-001 BodyParticipation schema on OpenRegister

The system SHALL define a `BodyParticipation` schema (slug `body-participation`) in the decidesk register via the `lib/Settings/register.d/56-shared-governance-bodies.json` fragment (ADR-037, never by editing existing schemas from the fragment), annotated `x-schema-org: org:Membership` (an organisation-as-member membership per the W3C org ontology/Popolo). The schema SHALL carry at minimum: `sharedBody` (required GovernanceBody reference — the gemeenschappelijke regeling / joint body), `participant` (required GovernanceBody reference — the participating council/organisation), `seats` (integer — number of seats the participant holds in the shared body), `votingWeight` (number, default 1 — the org-level weight agreed in the regeling; master data, see REQ-SGB-008), `toetredingsDatum` (date-time — accession), `uittredingsDatum` (date-time — withdrawal, null while participating), and `label` (string, optional). A participation SHALL be treated as active when `uittredingsDatum` is null or in the future (the Membership active-window semantics). Both references SHALL be declared in `x-openregister-relations` (each many-to-one). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `body-participation`.

#### Scenario: Three municipalities participate in one uitvoeringsorganisatie

- GIVEN a shared GovernanceBody for an uitvoeringsorganisatie and three municipal council GovernanceBodies
- WHEN a BodyParticipation is created per municipality with `sharedBody`, `participant`, seats, voting weight, and a toetredingsDatum
- THEN three `body-participation` objects exist in the decidesk register resolving both GovernanceBody relations
- AND a create missing `sharedBody` or `participant` is rejected by OpenRegister schema validation

#### Scenario: Withdrawn participant is inactive

- GIVEN a BodyParticipation whose `uittredingsDatum` lies in the past
- WHEN the shared body's active participant roster is evaluated
- THEN that participation is excluded from the active roster while remaining visible as a historical record

### Requirement: REQ-SGB-002 Additive base-register edits for shared-body typing and membership provenance

The system SHALL extend `lib/Settings/decidesk_register.json` with exactly two strictly additive edits (an enum value and an optional property cannot be added to an existing schema from an ADR-037 fragment — the works-council-consultation D2 precedent): (1) the GovernanceBody `bodyType` enum SHALL gain the value `shared-body`, alongside the existing values and any sibling additions (union merge — the works-council-consultation sibling's `works-council` value MUST survive); (2) the Membership schema SHALL gain an optional `namens` property (GovernanceBody reference, `x-openregister-relations` many-to-one) recording namens which participating organisation the member sits in the shared body (Popolo `on_behalf_of` semantics for organisational provenance, parallel to the existing `party` property). No existing property, enum value, or required-field set SHALL be modified or removed.

#### Scenario: Shared body typed and filterable

- GIVEN the register is imported after this change
- WHEN a GovernanceBody is created with `bodyType: "shared-body"`
- THEN OpenRegister accepts the object
- AND the governance-bodies index can filter on the `shared-body` bodyType

#### Scenario: Base edits are additive only

- GIVEN the updated `decidesk_register.json`
- WHEN its GovernanceBody and Membership schemas are diffed against the merge base
- THEN the only changes are the added `shared-body` enum value and the added optional `namens` property; all pre-existing values and properties are unchanged

### Requirement: REQ-SGB-003 Membership provenance in the shared body

A person's Membership in a shared GovernanceBody SHALL be able to record, via the optional `namens` reference of REQ-SGB-002, which participating organisation the member represents. The shared body's member roster surfaces SHALL display the namens-organisation per member, and the roster SHALL be filterable by namens-organisation. Memberships without `namens` (including all memberships in non-shared bodies) SHALL remain valid and render unchanged.

#### Scenario: Board member sits namens a municipality

- GIVEN a Person with a Membership (role `member`) in the shared uitvoeringsorganisatie body
- WHEN the membership's `namens` is set to the participating municipal GovernanceBody
- THEN the shared body's roster shows the member with their namens-organisation
- AND filtering the roster on that organisation lists only its representatives

#### Scenario: Membership without provenance stays valid

- GIVEN an existing Membership in a municipal council without `namens`
- WHEN the membership is validated and rendered after this change
- THEN it validates and renders exactly as before

### Requirement: REQ-SGB-004 Zienswijzeronde schema with declarative lifecycle

The system SHALL define a `Zienswijzeronde` schema (slug `zienswijzeronde`, annotated `x-schema-org: schema:Action`) in the same register fragment, carrying at minimum: `title` (required), `sharedBody` (required GovernanceBody reference — the body opening the ronde), `subjectType` (required enum: `ontwerpbegroting`, `begrotingswijziging`, `kadernota`, `jaarrekening`, `wijziging-regeling`, `toetreding-uittreding`, `overig`), `subjectDescription` (string), `deadline` (required date — the date by which participating organisations must submit their zienswijze, Wgr art. 35), `cyclusStap` (optional reference to the pc-cyclus sibling's `cyclus-stap` — the GR ontwerpbegroting is a P&C artifact; nullable soft reference, the ronde works standalone), `decision` (optional Decision reference — the shared body's vaststellingsbesluit after processing), and `status` (required). The subject document(s) SHALL attach via OpenRegister's Files leaf — no app-local file storage. The status workflow SHALL be declared exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): initial `concept`, transitions `concept → geopend → verwerking → afgerond`, plus `concept → ingetrokken` and `geopend → ingetrokken`, with `afgerond` and `ingetrokken` terminal. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Shared body opens a zienswijzeronde on the ontwerpbegroting

- GIVEN the shared uitvoeringsorganisatie body with three active participations
- WHEN the secretaris creates a Zienswijzeronde (`subjectType: ontwerpbegroting`, deadline, attached ontwerpbegroting PDF) and opens it
- THEN a `zienswijzeronde` object exists in status `geopend` with the document on its Files leaf
- AND omitting `title`, `sharedBody`, `subjectType`, or `deadline` is rejected by OpenRegister schema validation

#### Scenario: Invalid transition rejected declaratively

- GIVEN a Zienswijzeronde in `afgerond`
- WHEN any user attempts to set the status back to `geopend`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-SGB-005 Zienswijze schema and per-participant generation on opening

The system SHALL define a `Zienswijze` schema (slug `zienswijze`, annotated `x-schema-org: schema:Action`) in the same register fragment, carrying at minimum: `ronde` (required Zienswijzeronde reference), `participant` (required GovernanceBody reference — the organisation whose zienswijze this is), `deadline` (date — copied from the ronde at generation so declarative scheduled triggers can filter on the object's own field), `standpunt` (enum: `positief`, `positief-met-kanttekeningen`, `negatief`, `geen-zienswijze`), `text` (string — the zienswijze wording), `ingediendDatum` (date-time), `decision` (optional Decision reference — the participant council's besluit adopting the zienswijze), `verwerking` (enum: `overgenomen`, `gedeeltelijk-overgenomen`, `niet-overgenomen` — set by the shared body), `verwerkingsToelichting` (string), and `status` (required) with the canonical `x-openregister-lifecycle` dialect: initial `uitstaand`, transitions `uitstaand → in-voorbereiding → ingediend → verwerkt`, plus `uitstaand → niet-ingediend` and `in-voorbereiding → niet-ingediend` (deadline lapsed without submission), with `verwerkt` and `niet-ingediend` terminal. The response document SHALL attach via the Files leaf. When a Zienswijzeronde transitions `concept → geopend`, the system SHALL generate exactly one Zienswijze in status `uitstaand` per participation of the shared body that is active at that moment (REQ-SGB-001 active-window), copying the ronde's deadline; generation SHALL be idempotent (re-opening or retrying SHALL NOT create duplicates for the same ronde + participant).

#### Scenario: Opening the ronde fans out to the participants

- GIVEN the shared body has three active participations and one withdrawn participation
- WHEN the Zienswijzeronde is opened
- THEN exactly three `zienswijze` objects are generated in `uitstaand`, one per active participant, each carrying the ronde's deadline
- AND each appears in its own organisation's zienswijzen context (filtered on `participant`)

#### Scenario: Generation is idempotent

- GIVEN a ronde whose opening already generated three zienswijzen
- WHEN the generation action is retried for the same ronde
- THEN no duplicate zienswijze is created for any ronde + participant pair

### Requirement: REQ-SGB-006 Participant organisation records its zienswijze

The system SHALL allow a participating organisation to record its zienswijze on its own Zienswijze object: setting `standpunt`, `text`, `ingediendDatum`, attaching the response document via the Files leaf, optionally linking the participant council's `decision` (the raadsbesluit adopting the zienswijze), and transitioning the status to `ingediend`. The zienswijze SHALL NOT create meetings, agenda items, or decisions itself — scheduling the behandeling in the participant's council stays with the agenda-management flow, and the besluit stays in the Decision model (the pc-cyclus REQ-PCC-007 linkage discipline).

#### Scenario: Municipal council submits its zienswijze

- GIVEN a generated Zienswijze in `uitstaand` for a participating municipality
- WHEN the griffier records standpunt `positief-met-kanttekeningen`, the zienswijze text, the submission date, and attaches the response letter
- THEN the object transitions to `ingediend` carrying standpunt, text, and document
- AND an optionally linked raadsbesluit renders as a navigable Decision reference

#### Scenario: Deadline lapses without submission

- GIVEN a Zienswijze in `uitstaand` whose deadline has passed
- WHEN the shared body's secretaris marks it not submitted
- THEN the object transitions to the terminal `niet-ingediend` status and is counted as such in the ronde overview

### Requirement: REQ-SGB-007 Aggregated zienswijzen overview and verwerking by the shared body

The Zienswijzeronde detail SHALL render an aggregated overview of all its Zienswijze objects (reverse lookup on `ronde`) with columns for participant organisation, status, standpunt, ingediendDatum, and verwerking. The shared body SHALL record per zienswijze how it was processed (`verwerking` + `verwerkingsToelichting`, transitioning the zienswijze `ingediend → verwerkt`), and SHALL be able to link the ronde's closing `decision` (the vaststellingsbesluit, e.g. vaststelling van de begroting) before transitioning the ronde `verwerking → afgerond`. Consumers SHALL read the overview from the declared relations — no bespoke aggregation endpoint.

#### Scenario: Shared body processes the received zienswijzen

- GIVEN a ronde in `verwerking` with two `ingediend` and one `niet-ingediend` zienswijzen
- WHEN the bestuur records `overgenomen` on one and `gedeeltelijk-overgenomen` (with toelichting) on the other, links the vaststellingsbesluit, and closes the ronde
- THEN both processed zienswijzen are `verwerkt` with their verwerking visible in the overview, the ronde is `afgerond`, and its `decision` renders as a navigable reference

### Requirement: REQ-SGB-008 Weighted voting reuses existing Membership machinery

Per-participant weighted voting in the shared body SHALL reuse the EXISTING Membership `votingWeight` machinery as exposed per attendee by meeting-attendees REQ-MAT-006 — this capability SHALL NOT add any new voting mechanics, tabulation path, or ballot changes. `BodyParticipation.votingWeight` is org-level master data from the regeling: it SHALL be surfaced when creating or editing a Membership in the shared body (as the suggested default weight for members sitting namens that participant), and it SHALL NOT be read by any vote-computation path.

#### Scenario: Weighted vote in the shared body via memberships

- GIVEN the regeling grants municipality A weight 2 and municipalities B and C weight 1, recorded on their BodyParticipations, and the shared body's Memberships carry the corresponding `votingWeight`
- WHEN a meeting of the shared body is retrieved
- THEN each attendee's votingWeight comes from their Membership per REQ-MAT-006, with no new tabulation code introduced by this capability

#### Scenario: Participation weight suggests the membership weight

- GIVEN a BodyParticipation with `votingWeight: 2` for municipality A
- WHEN a new Membership in the shared body is created with `namens` = municipality A
- THEN the membership form suggests votingWeight 2 as the default, which the user MAY override

### Requirement: REQ-SGB-009 Deadline rappels are declarative notifications

Zienswijze deadline rappels SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031, the toezeggingen-register pattern) on the `Zienswijze` schema, with Dutch and English subjects: (1) **deadline approaching** — a scheduled trigger when a zienswijze is in `uitstaand` or `in-voorbereiding` and its `deadline` lies within the rappel window; (2) **deadline passed** — a scheduled trigger when a zienswijze is still in `uitstaand` or `in-voorbereiding` and its `deadline` is past; and (3) **zienswijze uitstaand** — a `created` trigger notifying the receiving organisation's readers when the zienswijze is generated. No rappel SHALL fire for zienswijzen in a terminal status. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Rappel before the deadline

- GIVEN a Zienswijze in `uitstaand` whose deadline lies within the rappel window
- WHEN the scheduled notification trigger evaluates
- THEN the receiving organisation's recipients get a notification referencing the zienswijze and its ronde

#### Scenario: No rappel after terminal status

- GIVEN a Zienswijze in `ingediend`, `verwerkt`, or `niet-ingediend`
- WHEN the scheduled triggers evaluate
- THEN no deadline rappel fires for that zienswijze

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the shared-governance-bodies code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-SGB-010 Participation and zienswijze views plus dashboard KPI

The system SHALL provide: (1) a "Deelnemende organisaties" section on the existing `GovernanceBodyDetail` manifest page (direct edit to `src/manifest.json` — fragments replace same-id pages wholesale) listing the body's BodyParticipations with columns for participant, seats, votingWeight, toetredingsDatum, and uittredingsDatum via reverse lookup on `sharedBody`; (2) a zienswijzerondes index page and a ronde detail page as manifest pages in a `src/manifest.d/shared-governance-bodies.json` fragment (ADR-037; `register: decidesk`, schema refs by slug `zienswijzeronde`/`zienswijze`/`body-participation`), the index filterable on sharedBody, subjectType, and status; (3) a zienswijzen index filterable on participant organisation and status, so each organisation sees its open zienswijze obligations; and (4) a Dashboard stat widget "Openstaande zienswijzen" counting Zienswijze objects in `uitstaand` or `in-voorbereiding`, sourced via the manifest widget aggregation (`metric: count`) — no imperative counting endpoint — routing to the zienswijzen index pre-filtered to the open set, where the participant filter gives the per-organisation view.

#### Scenario: Roster visible on the shared body detail page

- GIVEN the seeded shared body with three participations
- WHEN the user opens its GovernanceBody detail page
- THEN the "Deelnemende organisaties" section lists all three with seats, weight, and toetreding/uittreding dates

#### Scenario: Organisation sees its open obligations

- GIVEN seeded zienswijzen for three organisations, one already `ingediend`
- WHEN the user filters the zienswijzen index on one organisation with an open zienswijze
- THEN only that organisation's zienswijzen are listed with status and deadline

#### Scenario: KPI counts open zienswijzen and deep-links

- GIVEN two zienswijzen in `uitstaand`/`in-voorbereiding` and one in `ingediend`
- WHEN the dashboard renders
- THEN the "Openstaande zienswijzen" KPI shows 2
- AND clicking it opens the zienswijzen index pre-filtered to the open set

## Non-Functional Requirements

- **Performance:** rosters, overviews, and indexes paginate via the standard OR list API and read declared relations/aggregations (no N+1, no bespoke endpoints); opening a ronde writes at most one zienswijze per active participation in one action.
- **Accessibility:** Target WCAG 2.2 AA; status and deadline-overdue indicators do not rely on colour alone; manifest pages use the fleet's gate-checked shared components.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] Three schemas register from `register.d/56-shared-governance-bodies.json` and validate required fields; base edits are additive only (enum value + optional `namens`)
- [ ] Opening a ronde idempotently generates one `uitstaand` zienswijze per active participation, deadline copied
- [ ] Lifecycles are enforced by x-openregister-lifecycle only; rappels fire declaratively and never for terminal zienswijzen
- [ ] Participant records standpunt/text/document/decision link; shared body records verwerking and closes the ronde with a vaststellingsbesluit link
- [ ] No new voting mechanics: attendee weights keep coming from Membership per REQ-MAT-006; BodyParticipation weight is prefill-only
- [ ] Roster section, ronde/zienswijze pages, and the KPI render from declarative sources with working deep-links
- [ ] Seed data models a three-municipality GR and makes the KPI non-zero on fresh install

## Notes

- Related: `governance-bodies`/`governance-body-crud` (GovernanceBody = `schema:Organization`; the shared body IS a governance body — no parallel schema, ADR-006 discipline), `person-and-membership` (Popolo Membership carries the person-level relationship; `namens` parallels `party`/`on_behalf_of`), `meeting-attendees` REQ-MAT-006 (voting weight exposure — reused, hard boundary), `pc-cyclus` (GR begroting as CyclusStap — soft link), `toezeggingen-register` (deadline/rappel dialect precedent), `governing-documents-register`/`verordeningenregister` (own the regeling texts — out of scope here).
- Cross-Nextcloud-instance federation (each municipality on its own instance, OCM) is deliberately future work; this capability assumes one shared instance, which matches the live SED-style tender scenario (one shared RIS).
