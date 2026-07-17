# records-management-archiving Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- records-management-archiving

## Purpose

Gives decidesk a records-management and Archiefwet archiving lifecycle: archival dossiers formed per meeting/decision, MDTO metadata for every archivable object (extending the MDTO commitment the resolution-minutes spec already carries), retention schedules per Selectielijst gemeenten 2020 mapped onto OpenRegister's object-level `_retention` abstraction (ADR-022 — decidesk consumes OR abstractions, never reimplements storage), MDTO-compliant transfer (overbrenging) packages delivered to a DMS/zaaksysteem/e-depot through OpenConnector, an authorized destruction workflow ending in a permanent vernietigingsverklaring, a declarative compliance dashboard (ADR-031), and security classification labels. Decidesk is not an e-depot: it forms, describes, transfers, and destroys records; the receiving archive system is the system of permanent custody.

**Standards**: MDTO (Metagegevens voor duurzaam toegankelijke overheidsinformatie), Selectielijst gemeenten en intergemeentelijke organen 2020, Archiefwet 2021 (10-year transfer deadline), Schema.org (`ArchiveComponent`, `Collection`, `ItemList`, `DigitalDocument`), OpenRaadsinformatie (dossier members keep their `Besluit`/`Verslag` mappings), TOOI (organization identifiers in MDTO actor fields)
**Feature tier**: V1
**Legal reference**: Archiefwet 2021 art. 8-13 (bewaring, overbrenging, vernietiging), Archiefbesluit, Archiefregeling

## ADDED Requirements

### Requirement: REQ-RMA-001 Archival dossier assembly

The system MUST support assembling an `ArchivalDossier` OpenRegister object (Schema.org `ArchiveComponent`) per meeting or per decision route. A dossier MUST enumerate its member objects as UUID references grouped by kind (minutes, decisions, votingRounds, attachments, publicationRecords, proofPackages) and MUST record the governance body, the dossier period, and the assembly actor. Dossier assembly for a meeting MUST include the approved minutes, all decisions linked to the meeting (including motion/amendment/resolution `decisionType` variants per ADR-005), their voting rounds with vote totals, and the meeting's `DigitalDocument` attachments. The dossier lifecycle MUST be declared via `x-openregister-lifecycle` with states `forming → closed → transferred | destroyed` (`transferred` and `destroyed` terminal), and closing a dossier MUST freeze its member list — members MUST NOT be added or removed after `closed`.

#### Scenario: Assemble a dossier for a completed meeting

- GIVEN a completed council meeting with approved minutes, two enacted decisions, their voting rounds, and three attachments
- WHEN the secretary triggers "Form archival dossier" on the meeting
- THEN an `ArchivalDossier` object MUST be created in the decidesk register in state `forming`
- AND its member list MUST reference the minutes, both decisions, the voting rounds, and the attachments by UUID
- AND the dossier MUST record the governance body and the meeting date as its period

#### Scenario: Closing freezes the member list

- GIVEN an `ArchivalDossier` in state `forming`
- WHEN the secretary closes the dossier
- THEN the lifecycle MUST transition to `closed`
- AND any subsequent attempt to add or remove a member MUST be rejected with an error naming the frozen state

#### Scenario: Close of an incomplete dossier requires an override

- GIVEN a meeting dossier whose minutes are not yet `approved`
- WHEN the secretary attempts to close the dossier
- THEN the system MUST refuse and name the completeness gaps (e.g., "minutes not approved")
- AND closing MUST only proceed when the secretary supplies an explicit override reason, which MUST be stored on the dossier

### Requirement: REQ-RMA-002 MDTO metadata on archivable objects

The system MUST attach MDTO metadata to every archival dossier: an `mdto` object property on `ArchivalDossier` carrying at minimum naam, omschrijving, classificatie (Selectielijst category reference), dekkingInTijd, archiefvormer (TOOI organization identifier), waardering (B/V + term), and beperkingGebruik (from the security classification). For dossier members (Minutes, Decision, Meeting, DigitalDocument), item-level MDTO records MUST be derived at package-build time from the objects' existing properties plus OR-managed metadata (`_tmlo`/`_retention`, audit timestamps) — the member schemas gain only an optional `mdto` override property, and absence of an override MUST NOT block packaging. This extends, and MUST stay terminologically consistent with, the MDTO commitment in the resolution-minutes spec ("MDTO metadata is attached for archival compliance").

#### Scenario: Dossier carries MDTO metadata

- GIVEN an `ArchivalDossier` assembled for a council meeting
- WHEN the secretary completes the MDTO panel with a Selectielijst classification and coverage period
- THEN the dossier's `mdto` property MUST store naam, classificatie, dekkingInTijd, archiefvormer, and waardering
- AND the archiefvormer MUST be a TOOI-resolvable organization identifier

#### Scenario: Item-level MDTO derived at packaging

- GIVEN a closed dossier whose minutes object has no manual `mdto` override
- WHEN a transfer package is built
- THEN the package MUST contain a derived MDTO record for the minutes populated from the object's title, approval date, and OR audit metadata
- AND the build MUST NOT fail because a member lacks a manual MDTO override

### Requirement: REQ-RMA-003 Retention schedules per Selectielijst gemeenten 2020

The system MUST provide a `RetentionRule` OpenRegister schema (Schema.org `DefinedTerm`) carrying the Selectielijst gemeenten 2020 category (nummer, omschrijving), the waardering (`V` bewaren/overbrengen or `B` vernietigen), the retention period in years for `B` categories, and the trigger-event definition (e.g., dossier closure, end of council term). Assigning a retention rule to a dossier MUST resolve a concrete disposition date (trigger date + period) and MUST write the resolved retention onto the dossier and its member objects exclusively through OpenRegister's object API `_retention` abstraction (ADR-022) — decidesk SHALL NOT create tables or bypass OR storage. Rules MUST ship as editable seed objects covering the governance-relevant Selectielijst categories.

#### Scenario: Assign a destruction-category rule

- GIVEN a closed dossier and a RetentionRule for a `B`-category with a 10-year period triggered by dossier closure
- WHEN the secretary assigns the rule to the dossier
- THEN the dossier MUST store the rule reference and a resolved disposition date 10 years after closure
- AND `_retention` on the dossier's member objects MUST be set through the OR object API to match

#### Scenario: Permanent-retention category routes to transfer

- GIVEN a closed dossier assigned a `V` (bewaren) RetentionRule
- WHEN the disposition is resolved
- THEN the dossier MUST be marked for transfer (overbrenging) rather than destruction
- AND it MUST appear in the compliance dashboard's transfer-pipeline counters with the Archiefwet 10-year deadline computed from the dossier period

### Requirement: REQ-RMA-004 Transfer package generation

The system MUST generate a `TransferPackage` OpenRegister object (Schema.org `Dataset`) for one or more closed, transfer-marked dossiers. The package MUST contain, per dossier: the MDTO sidecar metadata (MDTO-XML) for the dossier and each member, and the member documents/content (minutes text, decision texts, attachments) referenced or embedded. Package building MUST validate the MDTO sidecars against the MDTO schema; a validation failure MUST set the package state to `failed-validation` and block delivery. The package lifecycle MUST be `building → ready → delivering → delivered | failed`. Package generation is an accepted imperative service (document generation exception under ADR-031); it MUST NOT mutate the source dossiers other than recording the package reference.

#### Scenario: Build a valid transfer package

- GIVEN two closed dossiers marked for transfer
- WHEN staff trigger "Build transfer package"
- THEN a `TransferPackage` MUST be created containing MDTO-XML sidecars for both dossiers and all members plus their documents
- AND after successful MDTO validation the package state MUST be `ready`

#### Scenario: MDTO validation failure blocks delivery

- GIVEN a dossier whose MDTO record is missing the mandatory waardering
- WHEN the package build runs
- THEN the package state MUST become `failed-validation` with the validation errors stored on the package
- AND delivery MUST be refused while the package is not `ready`

### Requirement: REQ-RMA-005 Transfer delivery via OpenConnector

The system MUST deliver `ready` transfer packages to the configured DMS/zaaksysteem/e-depot through an OpenConnector Source (external-integration exception under ADR-031), resolved lazily by slug in the same pattern as the existing `eidas-qes` e-sign Source. On successful delivery the package MUST record the remote acknowledgement reference and the dossiers MUST transition to `transferred`. When OpenConnector is absent or the archive Source is not configured, the package MUST remain `ready`, MUST be downloadable by staff for manual delivery, and the UI MUST state honestly that automatic delivery is unavailable — the system MUST NOT fail or pretend delivery happened. Delivery failures MUST surface to staff and be retryable; a failed delivery MUST NOT transition any dossier.

#### Scenario: Deliver a package to the configured e-depot

- GIVEN a `ready` TransferPackage and a configured OpenConnector archive Source
- WHEN staff trigger delivery
- THEN the package MUST be sent through the OpenConnector Source and, on acknowledgement, the package state MUST become `delivered` with the remote reference stored
- AND each contained dossier MUST transition to `transferred`

#### Scenario: OpenConnector absent degrades honestly

- GIVEN an instance without OpenConnector
- WHEN staff view a `ready` package
- THEN a download of the package (documents + MDTO sidecars) MUST be offered
- AND the UI MUST state that automatic delivery is unavailable, and no dossier state changes

### Requirement: REQ-RMA-006 Destruction workflow with separated authorization

The system MUST implement destruction as a `DestructionList` OpenRegister object (Schema.org `ItemList`) with declarative lifecycle `proposed → authorized → executed | rejected`. A proposal MUST enumerate, frozen at proposal time, the dossiers (and their member object UUIDs) whose resolved disposition date has passed for `B`-category rules. Authorization MUST be an explicit action by a user other than the proposer (separation of duties, enforced server-side) and MUST record actor and timestamp. Execution MUST delete the enumerated objects exclusively via OpenRegister's retention-aware `deleteObject` (soft delete honouring `_retention`; never a decidesk-side hard purge) and MUST transition the affected dossiers to `destroyed`. Objects not on the authorized list MUST NOT be touched. Scheduled bulk execution is an accepted imperative exception under ADR-031.

#### Scenario: Propose a destruction list

- GIVEN three dossiers whose `B`-category disposition dates have passed
- WHEN the records manager creates a destruction proposal
- THEN a `DestructionList` in state `proposed` MUST enumerate exactly those dossiers and their member UUIDs
- AND dossiers with unexpired or `V` dispositions MUST NOT be eligible for the list

#### Scenario: Proposer cannot authorize their own list

- GIVEN a `DestructionList` proposed by user A
- WHEN user A attempts to authorize it
- THEN the authorization MUST be rejected server-side with a separation-of-duties error
- AND when user B (with records-management authority) authorizes it, actor and timestamp MUST be recorded and the state MUST become `authorized`

#### Scenario: Execution deletes only the authorized objects

- GIVEN an `authorized` DestructionList
- WHEN execution runs
- THEN every enumerated object MUST be deleted via OR's retention-aware deleteObject
- AND no object outside the authorized enumeration is deleted
- AND each affected dossier MUST transition to `destroyed`

### Requirement: REQ-RMA-007 Vernietigingsverklaring (destruction verification report)

On completion of a destruction execution, the system MUST generate a vernietigingsverklaring: a permanent report stating what was destroyed (dossier titles, Selectielijst categories, object counts, period), on whose authorization (proposer, authorizer, timestamps), the legal basis (retention rule references), and the execution result per object. The verklaring MUST be stored as a permanently retained object with its rendered document (Docudesk PDF when available, markdown fallback per the established minutes pattern) and MUST itself never be eligible for destruction. Partial failures during execution MUST be listed per object in the verklaring rather than hidden.

#### Scenario: Verklaring produced after execution

- GIVEN a destruction execution that deleted 2 dossiers (14 objects) with one object failing deletion
- WHEN the execution completes
- THEN a vernietigingsverklaring MUST record both dossiers, their categories, the proposer and authorizer with timestamps, 13 successful deletions, and the 1 failure with its error
- AND the verklaring object MUST carry permanent retention and MUST never appear in a destruction proposal

### Requirement: REQ-RMA-008 Archive-completeness and compliance dashboard

The system MUST provide a records-management compliance dashboard built declaratively (ADR-031): `x-openregister-aggregations` counters and manifest dashboard widgets (ADR-037 manifest fragment), with no bespoke reporting backend. The dashboard MUST show at minimum: dossiers per lifecycle state, transfer-marked dossiers approaching or past the Archiefwet 10-year deadline, closed dossiers without an assigned RetentionRule, meetings/decision routes eligible for dossier formation but without a dossier (completeness gap), pending destruction lists, and delivered/failed transfer packages. Counters MUST be verifiable against the underlying objects.

#### Scenario: Overdue transfer surfaces on the dashboard

- GIVEN a transfer-marked dossier whose period ended more than 10 years ago and which is not `transferred`
- WHEN a staff member opens the compliance dashboard
- THEN the overdue-transfer counter MUST include the dossier and link to the filtered dossier list

#### Scenario: Completeness gap surfaces meetings without a dossier

- GIVEN a completed meeting with approved minutes but no ArchivalDossier
- WHEN the dashboard is viewed
- THEN the completeness widget MUST count the meeting as an archiving gap

### Requirement: REQ-RMA-009 Security classification labels on archival records

The system MUST support a `securityClassification` label (enum: `openbaar`, `intern`, `vertrouwelijk`, `geheim`) on `ArchivalDossier` and on the archivable member schemas (Minutes, Decision, Meeting, DigitalDocument) as an additive optional property defaulting to `openbaar`. The dossier classification MUST be at least as restrictive as its most restrictive member (computed, surfaced as a warning when violated). The classification MUST map into the MDTO `beperkingGebruik` field on export, MUST be carried on transfer packages, and objects classified above `openbaar` MUST be structurally refused by the public-publication payload builder (consistent with the public-publication spec's deny-list approach).

#### Scenario: Classification propagates to MDTO and transfer

- GIVEN a dossier classified `vertrouwelijk` containing a decision classified `vertrouwelijk`
- WHEN a transfer package is built
- THEN the MDTO sidecar MUST carry a beperkingGebruik reflecting `vertrouwelijk`
- AND the package MUST record the highest classification it contains

#### Scenario: Classified record refused for public publication

- GIVEN minutes classified `vertrouwelijk`
- WHEN a publish request targets those minutes
- THEN the publication payload builder MUST refuse before eligibility evaluation, consistent with the deny-list behavior in the public-publication spec

## Non-Functional Requirements

- **Performance:** dashboard counters MUST come from declarative aggregations (no N+1 per-dossier queries); package building for a 50-dossier batch MUST run as a background job, never in a web request.
- **Accessibility:** all new pages/widgets MUST meet WCAG 2.1 AA using standard NC components and nldesign CSS variables.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); statutory terms (overbrenging, vernietigingsverklaring, Selectielijst) keep their Dutch names in both locales with an English gloss.
- **Auditability:** every lifecycle transition on dossiers, packages, and destruction lists MUST appear in the OR audit trail with actor and timestamp.

## Acceptance Criteria

- [ ] ArchivalDossier, RetentionRule, TransferPackage, DestructionList schemas import cleanly with declarative lifecycle/aggregations/notifications/relations in `lib/Settings/decidesk_register.json`
- [ ] A meeting dossier bundles minutes, decisions, votes, and attachments; closing freezes membership and checks completeness
- [ ] MDTO-XML sidecars validate at package-build time; failed validation blocks delivery
- [ ] Resolved retention is written through OR's `_retention` API abstraction only
- [ ] Transfer delivery works via OpenConnector and degrades honestly (downloadable package) without it
- [ ] Destruction requires a second authorizer, deletes only enumerated objects via OR retention-aware delete, and produces a permanent vernietigingsverklaring
- [ ] Compliance dashboard shows overdue transfers, unassigned retention, completeness gaps, pending destructions from declarative aggregations
- [ ] Security classifications propagate to MDTO beperkingGebruik and block public publication above `openbaar`
- [ ] Seed data ships realistic Selectielijst 2020 rules and example dossiers

## Notes

- Talk-conversation archiving (intelligence-DB demand 497) is deferred; the dossier member model reserves a future `talk-export` kind.
- Decidesk is never the e-depot; permanent custody is the receiving archive's responsibility (Archiefwet art. 12).
- Related ADRs: ADR-005 (decision supertype, i18n), ADR-022 (consume OR abstractions), ADR-031 (declarative-first), ADR-037 (manifest fragments).
- Related specs: resolution-minutes (MDTO on minutes, proof packages), public-publication (deny-list, derived payloads), decision-management (lifecycle `archived` on Decision is a workflow state, distinct from dossier `transferred`/`destroyed`).
