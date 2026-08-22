# embargo-geheimhouding Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- embargo-geheimhouding

## Purpose

Gives decidiq the juridical geheimhouding and embargo layer on top of its existing confidentiality classifiers: a formal `Geheimhouding` record with a structured, configurable legal ground (Gemeentewet art. 87-89 with pre-2023 article labels, Woo art. 5.1), a bekrachtiging workflow where the ground requires it, an opheffing workflow whose lifting besluit routes the object into the normal publication machinery, a member-facing embargo with scheduled timed release, a geheimhoudingenregister per body, a view audit trail for stukken under geheimhouding, and declarative notifications. It builds ON the existing classifiers — `AgendaItem.confidentiality` (meeting-pack-board-book), `Decision.isPublished` (p3-citizen-participation), the public-publication deny-list and future-`publicatiedatum` primitive, the commissievergaderingen besloten access + audit precedent (REQ-CVG-010), and the records-management classification vocabulary (REQ-RMA-009) — and never introduces a parallel confidentiality vocabulary.

**Standards**: Gemeentewet art. 87-89 (geheimhouding, post Wet bevorderen integriteit en functioneren decentraal bestuur 2023; voorheen art. 25/55/86), Woo art. 5.1 (uitzonderingsgronden), Schema.org (`Action` for Geheimhouding, `DefinedTerm`/`DefinedTermSet` for grounds, `DigitalDocument` + `DigitalDocumentPermission` semantics for embargo), OpenRaadsinformatie (Geheimhouding records are never exposed on ORI; post-opheffing publications keep the existing `legalBasis` → `classification` mapping)
**Feature tier**: V1
**Legal reference**: Gemeentewet art. 87 (kring van geheimhouding), art. 88 (oplegging), art. 89 (opheffing); Woo art. 5.1 lid 1 (absolute gronden) en lid 2 (relatieve gronden)

## ADDED Requirements

### Requirement: REQ-EMB-001 Geheimhouding record with structured legal ground

The system MUST support a `Geheimhouding` OpenRegister object (Schema.org `Action`: agent = imposing organ, object = target, startTime = imposedAt, endTime = liftedAt) attachable to exactly one target: a `DigitalDocument`, an `AgendaItem`, or a `Decision` (`scope` enum: `document` | `item` | `decision`, plus the target UUID). The record MUST carry: a reference to a `GeheimhoudingGrond` (structured legal ground — never free text), `imposedBy` (enum: `body` | `chair` | `college`, plus the `GovernanceBody` UUID), `imposedAt` (datetime), and a lifecycle declared exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031, keyword `initial`): field `lifecycle`, initial `opgelegd`, transitions `opgelegd → bekrachtigd → opgeheven` and `opgelegd → opgeheven` (permitted only when the ground does not require bekrachtiging), with `opgeheven` terminal. The app SHALL NOT implement an imperative state machine for this lifecycle.

Imposing a geheimhouding MUST drive the target's EXISTING classifier instead of a new vocabulary: on an `AgendaItem` target it MUST set `confidentiality: confidential` (meeting-pack-board-book REQ-005 enum); on a `Decision` target it MUST set `isPublished: "confidential"` (p3-citizen-participation enum); on a `DigitalDocument` target the document MUST be excluded from publication surfaces while the geheimhouding is active. For new geheimhouding records the structured ground supersedes the free-text `Decision.legalBasis` field; existing free-text `legalBasis` values MUST remain untouched (compatibility: the free-text field stays valid for non-geheimhouding legal citations).

#### Scenario: College imposes geheimhouding on a document for the raad

- GIVEN a `DigitalDocument` attached to an agenda item of an upcoming council meeting
- WHEN the griffier records a geheimhouding with ground "Gemeentewet art. 87-89", imposedBy `college`, scope `document`
- THEN a `Geheimhouding` object MUST exist in state `opgelegd` referencing the document UUID, the ground, the college's governance body, and the imposition timestamp
- AND the document MUST be excluded from every publication surface while the geheimhouding is active

#### Scenario: Imposing on an agenda item drives the existing confidentiality enum

- GIVEN an `AgendaItem` with `confidentiality: internal`
- WHEN a geheimhouding with scope `item` is imposed on it
- THEN the agenda item's `confidentiality` MUST become `confidential`
- AND no new confidentiality field MUST be introduced on the agenda item

#### Scenario: Lifecycle transitions are guarded declaratively

- GIVEN a geheimhouding in state `opgelegd` whose ground requires bekrachtiging
- WHEN a direct transition to `opgeheven` is attempted
- THEN OpenRegister MUST reject the transition per the declared lifecycle map

### Requirement: REQ-EMB-002 Configurable ground list with dual Gemeentewet article labels

The system MUST provide a `GeheimhoudingGrond` OpenRegister object (Schema.org `DefinedTerm` in the `DefinedTermSet` "geheimhoudingsgronden") with: `name`, `citation` (statutory article reference), `legacyCitation` (optional pre-2023 article reference), `category` (enum: `gemeentewet` | `woo-absoluut` | `woo-relatief` | `overig`), `requiresBekrachtiging` (boolean), `description`, and `active` (boolean). Grounds MUST be data, not code: administrators MUST be able to add, edit, and deactivate grounds; deactivated grounds MUST remain resolvable on existing geheimhouding records but MUST NOT be selectable for new ones. The system MUST ship seed grounds covering the Gemeentewet geheimhoudingsartikelen carrying BOTH the post-2023 labels (art. 87, 88, 89 — Wet bevorderen integriteit en functioneren decentraal bestuur) AND the pre-2023 labels (voorheen art. 25/55/86) in `legacyCitation`, plus the Woo art. 5.1 lid 1 (absolute) and lid 2 (relatieve) uitzonderingsgronden. Whether bekrachtiging is required MUST be a property of the configured ground, never hardcoded (the 2023 regime changed the bekrachtigingsvereiste; municipalities and other governance domains differ).

#### Scenario: Seeded grounds carry both article labelings

- GIVEN a clean install with seeds imported
- WHEN the ground list is opened in the impose dialog
- THEN a Gemeentewet ground MUST show citation "Gemeentewet art. 87-89" together with its legacy label "voorheen art. 25/55/86"
- AND Woo art. 5.1 grounds MUST be present in both the absolute and relative categories

#### Scenario: Deactivated ground stays resolvable on old records

- GIVEN a geheimhouding referencing ground X
- WHEN an administrator deactivates ground X
- THEN the existing geheimhouding MUST still render ground X's name and citation
- AND ground X MUST NOT appear in the ground picker for new geheimhoudingen

### Requirement: REQ-EMB-003 Bekrachtiging workflow with fail-visible overdue flag

Where the referenced ground has `requiresBekrachtiging: true`, the system MUST place the geheimhouding on the agenda of the next scheduled meeting of the confirming body: an `AgendaItem` referencing the geheimhouding MUST be created on that meeting (with `confidentiality` respecting the underlying stukken), and the `bekrachtigingDeadline` MUST be recorded on the geheimhouding (defaulting to that meeting's date, manually overridable). Recording the bekrachtiging MUST link the `bekrachtigingsbesluit` (a `Decision` UUID of the confirming body) and transition the lifecycle to `bekrachtigd`. When the deadline passes without a recorded bekrachtigingsbesluit, the system MUST flag the geheimhouding as overdue — fail-visible in the register overview and via notification — and MUST NOT auto-lift, auto-expire, or otherwise change the legal state (legally cautious: the system reports, the organ decides).

#### Scenario: Geheimhouding lands on the confirming body's next agenda

- GIVEN a geheimhouding whose ground requires bekrachtiging by the raad
- WHEN it is recorded in state `opgelegd`
- THEN an agenda item referencing the geheimhouding MUST exist on the raad's next scheduled meeting
- AND the geheimhouding's `bekrachtigingDeadline` MUST equal that meeting's date

#### Scenario: Bekrachtigingsbesluit recorded

- GIVEN the confirming body decides to bekrachtig the geheimhouding
- WHEN the griffier links the bekrachtigingsbesluit `Decision`
- THEN the geheimhouding MUST transition to `bekrachtigd` and store the decision UUID and timestamp

#### Scenario: Overdue bekrachtiging is flagged, never auto-lifted

- GIVEN a geheimhouding whose `bekrachtigingDeadline` passed without a linked besluit
- WHEN the register overview is viewed
- THEN the geheimhouding MUST appear as overdue in the awaiting-bekrachtiging KPI
- AND its lifecycle MUST still be `opgelegd` and the target MUST remain confidential

### Requirement: REQ-EMB-004 Opheffing workflow routing into the normal publication machinery

Lifting a geheimhouding MUST require an opheffingsbesluit by the imposing or confirming body: the system MUST record the `opheffingsbesluit` (a `Decision` UUID), the lifting date, and optional conditions (free text, e.g. "openbaar na afronding onderhandelingen"), and transition the lifecycle to `opgeheven`. On opheffing the target object MUST flow into the NORMAL publication machinery (public-publication spec): the target becomes ELIGIBLE for publication again but MUST NOT be published automatically — a griffie member confirms publication through the existing publish flow. The system MUST restore no classifier silently: the target's classifier (`confidentiality` / `isPublished`) is updated as part of the recorded opheffing action, with the actor in the audit trail.

#### Scenario: Opheffing recorded with conditions

- GIVEN a geheimhouding in state `bekrachtigd` on a decision
- WHEN the raad's opheffingsbesluit is linked with condition "na ondertekening contract"
- THEN the geheimhouding MUST transition to `opgeheven` storing besluit UUID, date, and the condition
- AND the decision's `isPublished` MUST return to `internal` (not `public`)

#### Scenario: Opheffing never auto-publishes

- GIVEN a geheimhouding that was just opgeheven on a document
- WHEN no griffie member has run the publish flow
- THEN the document MUST NOT appear on any public surface
- AND the publish flow MUST now accept the document (eligibility restored)

### Requirement: REQ-EMB-005 Member-facing embargo with scheduled timed release

The `DigitalDocument` schema MUST gain additive optional properties: `embargoUntil` (datetime), `embargoActive` (boolean, system-managed), and `embargoAudience` (the group/role entitled to immediate access). While `embargoActive` is true, only the entitled audience (and staff) MUST be able to read the document; the wider membership gains access at the `embargoUntil` moment. Because group-scoped OpenRegister RBAC rules cannot time-switch per object for member groups, the member-side unlock MUST be implemented as a scheduled background job (OCP `TimedJob`) that flips `embargoActive` to false via the OR object API for every document whose `embargoUntil` has passed; the job interval (15 minutes) IS the documented release granularity — the system MUST NOT claim second-precision release. The PUBLIC side MUST reuse the existing future-`publicatiedatum` primitive on publication payloads unchanged (public-publication spec: `publicatiedatum <= $now` predicate on the public group); this requirement adds NO new public-access mechanism.

#### Scenario: Entitled member sees the document immediately

- GIVEN a document with `embargoUntil` tomorrow and `embargoAudience` "fractievoorzitters"
- WHEN a fractievoorzitter opens the meeting's documents today
- THEN the document MUST be readable
- AND a regular member's request MUST be denied

#### Scenario: Wider member access unlocks at the embargo moment

- GIVEN the same document after `embargoUntil` has passed
- WHEN the embargo-release job runs
- THEN `embargoActive` MUST become false via the OR object API
- AND a regular member MUST now be able to read the document within the documented 15-minute granularity

#### Scenario: Public release still goes through publicatiedatum

- GIVEN an embargoed document whose payload was published with a future `publicatiedatum`
- WHEN the `publicatiedatum` moment passes
- THEN anonymous public access follows the existing published-predicate behavior, independent of the member-side job

### Requirement: REQ-EMB-006 Geheimhoudingenregister overview and awaiting-bekrachtiging KPI

The system MUST provide a geheimhoudingenregister overview per governance body listing active geheimhoudingen with target, ground (citation incl. legacy label), imposedBy, imposedAt, lifecycle state, and bekrachtiging status. Counters MUST come from declarative `x-openregister-aggregations` (ADR-031): active geheimhoudingen per body, awaiting bekrachtiging (state `opgelegd` with `requiresBekrachtiging` ground), overdue bekrachtiging (deadline passed), and opgeheven-awaiting-publication. The overview and KPI widgets MUST ship as an ADR-037 manifest fragment (schema refs by slug, e.g. `geheimhouding`, never PascalCase).

#### Scenario: Griffie sees the register with KPIs

- GIVEN three active geheimhoudingen for the raad of which one is past its bekrachtiging deadline
- WHEN the griffier opens the geheimhoudingenregister
- THEN all three MUST be listed with ground and bekrachtiging status
- AND the awaiting-bekrachtiging KPI MUST show 1 overdue, sourced from a declarative aggregation

### Requirement: REQ-EMB-007 View audit trail for stukken under geheimhouding

Extending the commissievergaderingen besloten audit precedent (REQ-CVG-010: "wie heeft wanneer besloten-stukken ingezien"), the system MUST record who viewed a document under active geheimhouding and when: every read/download of such a document through the app MUST produce an audit entry (actor NC UID, timestamp, document UUID, geheimhouding UUID) in the OpenRegister audit trail. The audit MUST be queryable per geheimhouding from its detail view by griffie/staff. Views after opheffing MUST NOT be specially audited (normal OR behavior applies).

#### Scenario: Viewing a geheim document is audited

- GIVEN a document under active geheimhouding
- WHEN member "j.jansen" downloads it via the app
- THEN an audit entry with actor, timestamp, document UUID, and geheimhouding UUID MUST be recorded
- AND the geheimhouding detail view MUST list this view for the griffier

### Requirement: REQ-EMB-008 Declarative notifications for bekrachtiging and embargo events

Notifications MUST be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031; gate-18 forbids imperative dispatch): (1) a scheduled trigger when a geheimhouding in state `opgelegd` with a bekrachtiging-requiring ground approaches or passes its `bekrachtigingDeadline`, notifying the griffie group; (2) a trigger when `embargoActive` flips to false, notifying the meeting's members that the document is released (fires only AFTER the flip actually happened); (3) a trigger when a geheimhouding transitions to `opgeheven`, reminding the griffie group that the object can now enter the publish flow. All subjects MUST be bilingual (nl/en). The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob (the EmbargoReleaseJob flips the field; the notification rides the field change declaratively).

#### Scenario: Bekrachtiging-due notification

- GIVEN a geheimhouding whose bekrachtigingDeadline is within the configured warning window
- WHEN the scheduled notification trigger evaluates
- THEN the griffie group MUST receive a notification with a Dutch and English subject naming the geheimhouding

#### Scenario: Embargo-released notification follows the actual flip

- GIVEN an embargoed document whose `embargoUntil` passed
- WHEN the release job flips `embargoActive` to false
- THEN the release notification MUST fire on that change
- AND no release notification MUST exist for documents whose flip has not yet run

### Requirement: REQ-EMB-009 Publication guard for objects under active geheimhouding

Objects under an active geheimhouding (lifecycle `opgelegd` or `bekrachtigd`) MUST be structurally refused by the publication payload builder before eligibility evaluation, consistent with the public-publication deny-list approach and with records-management REQ-RMA-009 (classified records refused). This requirement is ADDED in this capability: it does NOT modify the public-publication spec's eligibility-gates requirement. The refusal MUST name the geheimhouding (UUID + ground citation) in the staff-facing error so the griffie knows which record blocks publication and how to lift it.

#### Scenario: Publish attempt on a document under geheimhouding is refused

- GIVEN a document under a geheimhouding in state `bekrachtigd`
- WHEN a staff member attempts to publish it
- THEN payload construction MUST be refused before eligibility evaluation
- AND the error MUST reference the blocking geheimhouding and its ground citation

## Non-Functional Requirements

- **Performance:** register KPIs MUST come from declarative aggregations (no N+1 per-geheimhouding queries); the embargo-release job MUST query only documents with `embargoActive: true` and `embargoUntil` in the past.
- **Accessibility:** all new pages/widgets/dialogs MUST meet WCAG 2.1 AA using standard NC components and nldesign CSS variables.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); statutory terms (geheimhouding, bekrachtiging, opheffing, embargo) keep their Dutch names in both locales with an English gloss.
- **Auditability:** every lifecycle transition on Geheimhouding objects MUST appear in the OR audit trail with actor and timestamp; classifier changes on targets are part of the recorded impose/opheffing actions.

## Acceptance Criteria

- [ ] `Geheimhouding` and `GeheimhoudingGrond` schemas import cleanly from fragment 65 with declarative lifecycle/aggregations/notifications/relations
- [ ] Imposing drives the existing classifiers (`AgendaItem.confidentiality`, `Decision.isPublished`) — no parallel vocabulary
- [ ] Seed grounds ship Gemeentewet art. 87-89 with legacy labels plus Woo art. 5.1 grounds; grounds are admin-editable
- [ ] Bekrachtiging places the geheimhouding on the confirming body's next agenda and links the besluit; overdue is flagged, never auto-lifted
- [ ] Opheffing requires a besluit, records date + conditions, and restores publish eligibility without auto-publishing
- [ ] Embargoed documents are visible to the entitled audience immediately and to the wider membership after the scheduled flip (15-minute granularity, honestly documented)
- [ ] Register overview + awaiting-bekrachtiging KPI come from declarative aggregations
- [ ] Views of stukken under geheimhouding are audited and queryable per geheimhouding
- [ ] Publication payload builder structurally refuses targets under active geheimhouding

## Notes

- The 2023 Wet bevorderen integriteit en functioneren decentraal bestuur changed which impositions require bekrachtiging — hence `requiresBekrachtiging` lives on the configurable ground, never in code.
- Out of scope: DigiD-gated public access, WOB/Woo litigation flows, redaction tooling (exists), retro-classification of archived records (records-management-archiving owns archival access via `beperkingGebruik`).
- Related ADRs: ADR-005 (i18n), ADR-016 (seed data), ADR-022 (consume OR abstractions), ADR-031 (declarative-first), ADR-037 (register/manifest fragments).
- Related specs/changes: meeting-pack-board-book REQ-005 (confidentiality enum), p3-citizen-participation (isPublished enum + free-text legalBasis compatibility), public-publication (deny-list, `publicatiedatum` primitive, withdraw/rectify), commissievergaderingen REQ-CVG-010 (besloten access + viewer audit), records-management-archiving REQ-RMA-009 (classification vocabulary).
