# records-management-archiving Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- records-management-archiving

## Purpose

Gives decidesk the one records-management capability OpenRegister does not have — the **archival dossier**, an aggregate unit bundling a meeting's or decision route's records — and wires that dossier into OpenRegister's already-shipped Archiefwet stack for everything else. OR owns retention resolution (`RetentionService`, `ArchiefactiedatumCalculator`, `SelectionList`), MDTO serialization (`MdtoXmlGenerator`), SIP packaging and e-depot transfer (`SipPackageBuilder`, `EdepotTransferService`, `TransferListService`, `Transport/*`), destruction with dual approval and legal holds (`DestructionService`, `LegalHoldService`), and the `verklaring_van_vernietiging` certificate. Decidesk MUST NOT reimplement any of it (ADR-022). Decidesk adds: dossier assembly, a compliance dashboard (OR's is spec-only), a security-classification label, Selectielijst 2020 seed data expressed as OR `SelectionList` objects, and rendering of OR's destruction certificate.

Decidesk is not an e-depot, and it is also not a records engine: it forms the aggregate, describes it, and hands it to OR.

**Standards**: MDTO (Metagegevens voor duurzaam toegankelijke overheidsinformatie), Selectielijst gemeenten en intergemeentelijke organen 2020, Archiefwet 2021 (10-year transfer deadline), Schema.org (`ArchiveComponent`, `Collection`, `ItemList`, `DigitalDocument`), OpenRaadsinformatie (dossier members keep their `Besluit`/`Verslag` mappings), TOOI (organization identifiers)
**Feature tier**: V1
**Legal reference**: Archiefwet 2021 art. 8-13 (bewaring, overbrenging, vernietiging), Archiefbesluit, Archiefregeling

## What OpenRegister already provides (consumed, never rebuilt)

| Concern | OR component | Decidesk role |
|---|---|---|
| Retention resolution | `lib/Service/RetentionService.php::applyArchivalMetadata()` + `lib/Service/Archival/ArchiefactiedatumCalculator.php` | Set the schema's `archive.classificatie`; never compute dates |
| Selectielijst rules | `lib/Db/SelectionList.php` + `SelectionListMapper.php` | Ship Selectielijst 2020 rows as OR `SelectionList` seed objects |
| Persisted Archiefwet block | `ObjectEntity.retention` (json column) | Read for dashboard counters; never invent a local field |
| MDTO serialization | `lib/Service/Edepot/MdtoXmlGenerator.php` | Populate `tmlo` / `retention`; never map MDTO app-side |
| SIP packaging | `lib/Service/Edepot/SipPackageBuilder.php` (zip / BagIt, METS + PREMIS, size splitting) | None — consumed |
| Transfer | `TransferListService.php`, `EdepotTransferService.php`, `Transport/OpenConnectorTransport.php` | Create a transfer list over dossier member UUIDs |
| Destruction | `lib/Service/Archival/DestructionService.php`, `LegalHoldService.php`, `BackgroundJob/{DestructionCheckJob,DestructionExecutionJob}.php` | Consume approval routes; never delete objects itself |
| Destruction certificate | `DestructionService::generateCertificate()` → `type: 'verklaring_van_vernietiging'`; `GET /api/archival/certificates` | Render to PDF and retain |

### Naming: `_retention` is NOT the Archiefwet field

`_retention` in the `@self` envelope is `ObjectEntity::$archivalRetention` — a **transient, not-persisted, read-only** view produced by the `x-openregister-archival` TTL mechanism, shape `{effectiveRetention, matchedRule, expiresAt}`. It CANNOT be written. The persisted Archiefwet block is the **`retention`** property/column (archiefnominatie, archiefstatus, classificatie, bewaartermijn, archiefactiedatum, selectielijstBron, legalHold), written by `RetentionService::applyArchivalMetadata()`. The MDTO field is **`tmlo`**; `_tmlo` does not exist. Decidesk MUST target `retention` and `tmlo`.

`x-openregister-archival` is a TTL / log-rotation mechanism (design case: openconnector `call_log`, `"P30D"`). Its validator (`ArchivalAnnotationValidator`) rejects any key outside `{default, rules}` / `{condition, retention, reason}`, so it cannot express waardering B/V, a Selectielijst category, archiefactiedatum, afleidingswijze, or a bewaren→overbrengen route, and it auto-deletes on expiry with no approval. **It is NOT the Archiefwet path and MUST NOT be targeted for one.**

## ADDED Requirements

### Requirement: REQ-RMA-001 Archival dossier assembly

The system MUST support assembling an `ArchivalDossier` OpenRegister object (Schema.org `ArchiveComponent`) per meeting or per decision route. OpenRegister has no aggregate/dossier unit (`openspec/specs/document-zaakdossier/spec.md` is a `status: redirect` stub owned by Procest), so this is decidesk's own capability. A dossier MUST enumerate its member objects as UUID references grouped by kind (minutes, decisions, votingRounds, attachments, publicationRecords, proofPackages) and MUST record the governance body, the dossier period, and the assembly actor. Dossier assembly for a meeting MUST include the approved minutes, all decisions linked to the meeting (including motion/amendment/resolution `decisionType` variants per ADR-005), their voting rounds with vote totals, and the meeting's `DigitalDocument` attachments. The dossier lifecycle MUST be declared via `x-openregister-lifecycle` with states `forming → closed → transferred | destroyed` (`transferred` and `destroyed` terminal), and closing a dossier MUST freeze its member list — members MUST NOT be added or removed after `closed`.

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

### Requirement: REQ-RMA-002 Dossier-level MDTO via OpenRegister

The system MUST make dossiers MDTO-describable by populating OpenRegister's `tmlo` and `retention` fields on the `ArchivalDossier` object, so that OR's `MdtoXmlGenerator` emits valid MDTO records for it. Decidesk MUST NOT contain an MDTO mapping table, an MDTO serializer, or item-level MDTO derivation — `MdtoXmlGenerator::generate()` already emits naam, toelichting, waardering, bewaartermijn, informatiecategorie (from `retention.classificatie`), omvang, and bestandsformaat for any object, including dossier members, and OR's `SipPackageBuilder` assembles them. Decidesk's only MDTO responsibility is ensuring the dossier's `tmlo`/`retention` fields are populated before transfer. Where a dossier-level MDTO concept cannot be expressed through OR's existing fields, decidesk MUST record it as a proposed OpenRegister follow-up and MUST NOT build a decidesk-side workaround. This stays terminologically consistent with the MDTO commitment in the resolution-minutes spec.

#### Scenario: Dossier is MDTO-describable through OR fields

- GIVEN an `ArchivalDossier` assembled for a council meeting
- WHEN the secretary completes the archival panel and the dossier is closed
- THEN the dossier's OR `tmlo` field MUST carry its MDTO descriptive metadata
- AND its OR `retention` field MUST carry classificatie and waardering resolved by OR
- AND a request for the dossier's MDTO record MUST be served by OR's `MdtoXmlGenerator`, not by decidesk code

#### Scenario: Members need no decidesk-side MDTO derivation

- GIVEN a closed dossier whose minutes member has no decidesk-authored MDTO block
- WHEN OR builds the SIP package for the dossier's transfer list
- THEN OR MUST emit an MDTO record for the minutes from the object's own fields
- AND the build MUST NOT fail, and decidesk MUST NOT supply a derivation

### Requirement: REQ-RMA-003 Retention via OpenRegister Selectielijst and RetentionService

The system MUST express Selectielijst gemeenten 2020 categories as OpenRegister `SelectionList` objects (fields: category, retentionYears, action, description, schemaOverrides, organisation), shipped as editable seed data. Decidesk MUST NOT define a `RetentionRule` schema and MUST NOT compute disposition dates. Retention MUST be resolved by OR: decidesk sets the archivable schema's `archive` configuration (`enabled`, `classificatie`, and where needed `afleidingswijze` / `bewaartermijnOverride`), and `RetentionService::applyArchivalMetadata()` looks up the matching `SelectionList` entry and writes `archiefnominatie`, `archiefstatus`, `classificatie`, `bewaartermijn`, `selectielijstBron`, and an `archiefactiedatum` computed by `ArchiefactiedatumCalculator` into the object's persisted `retention` field. Decidesk MUST read those values and MUST NOT maintain a parallel disposition field. OR's supported afleidingswijzen are `afgehandeld`, `eigenschap`, and `termijn`; a trigger not expressible by these MUST be recorded as a proposed OpenRegister follow-up rather than implemented in decidesk.

#### Scenario: Destruction-category retention resolved by OpenRegister

- GIVEN a `SelectionList` seed for a `B` (vernietigen) category with a 10-year retention and an archivable dossier schema whose `archive.classificatie` names that category
- WHEN a dossier is created and OR applies archival metadata
- THEN OR MUST write `archiefnominatie`, `bewaartermijn`, `selectielijstBron`, and a calculated `archiefactiedatum` into the dossier's persisted `retention` field
- AND decidesk MUST NOT compute or store any disposition date of its own

#### Scenario: Permanent-retention category routes to transfer

- GIVEN a closed dossier whose resolved `retention.archiefnominatie` marks it for permanent retention (bewaren)
- WHEN the dossier's disposition is evaluated
- THEN the dossier MUST be routed to transfer (overbrenging) rather than destruction
- AND it MUST appear in the compliance dashboard's transfer-pipeline counters with the Archiefwet 10-year deadline computed from the dossier period

#### Scenario: An inexpressible trigger becomes an OR follow-up, not decidesk code

- GIVEN a desired `end-of-council-term` retention trigger
- WHEN it cannot be expressed through OR's `afgehandeld` / `eigenschap` / `termijn` afleidingswijzen
- THEN the gap MUST be recorded as a proposed OpenRegister follow-up
- AND decidesk MUST NOT ship a local calculator to work around it

### Requirement: REQ-RMA-004 Transfer via OpenRegister transfer lists and e-depot

The system MUST transfer dossiers by creating an OpenRegister transfer list over the dossier's member UUIDs via `TransferListService::createTransferList()` and relying on OR's `EdepotTransferService`, `SipPackageBuilder` (zip or BagIt, METS + PREMIS, size splitting), and `Transport/OpenConnectorTransport` for packaging and delivery. Decidesk MUST NOT define a `TransferPackage` schema, MUST NOT implement a package builder, an archive-connector service, or any `POST /api/transfer-packages*` endpoint, and MUST NOT validate MDTO itself. On successful transfer the dossier MUST transition to `transferred`, driven by OR's transfer outcome. When OR's e-depot transport is not configured (see OR's e-depot settings surface, `/api/settings/edepot`), decidesk MUST state honestly that automated transfer is unavailable, MUST leave the dossier untransferred, and MUST NOT fail or pretend transfer happened.

#### Scenario: Transfer a dossier through OpenRegister

- GIVEN a closed dossier routed to transfer and a configured OR e-depot transport
- WHEN staff trigger transfer
- THEN decidesk MUST create an OR transfer list over the dossier's member UUIDs
- AND packaging and delivery MUST be performed by OR's e-depot services, not decidesk
- AND on OR reporting success the dossier MUST transition to `transferred`

#### Scenario: Unconfigured e-depot degrades honestly

- GIVEN an instance where OR's e-depot transport is not configured
- WHEN staff open a transfer-routed dossier
- THEN the UI MUST state that automated transfer is unavailable and point to OR's e-depot settings
- AND the dossier MUST NOT change state, and no decidesk-side package MUST be produced as a substitute

### Requirement: REQ-RMA-005 Destruction via OpenRegister destruction lists

The system MUST perform destruction by consuming OpenRegister's destruction stack: OR's `DestructionList` entity, its approval routes (`POST /api/archival/destruction-lists/{id}/approve`, `.../reject`), its dual-approval rule (OR's `DestructionService` already rejects a second approval by the same archivist), its `LegalHoldService` pre-flight, and its `DestructionCheckJob` / `DestructionExecutionJob`. Decidesk MUST NOT define a `DestructionList` schema, MUST NOT implement a destruction service, and MUST NOT delete member objects itself — object deletion is OR's `DestructionService` responsibility, gated by its legal-hold checks. Decidesk's responsibility is to relate a dossier to the OR destruction list covering its members and to transition the dossier to `destroyed` when OR reports execution. Objects not on the approved OR list MUST NOT be affected.

#### Scenario: Dossier destruction routed to OpenRegister

- GIVEN dossiers whose resolved `retention.archiefactiedatum` has passed with a destruction nominatie
- WHEN destruction is proposed
- THEN the proposal MUST be an OpenRegister destruction list enumerating the dossiers' member UUIDs
- AND approval MUST go through OR's approval routes, with OR enforcing its dual-approval rule
- AND decidesk MUST NOT implement its own approval or deletion path

#### Scenario: Dossier reflects OR execution

- GIVEN an approved OR destruction list covering a dossier's members
- WHEN OR's destruction execution completes
- THEN the dossier MUST transition to `destroyed`
- AND objects under an OR legal hold MUST be reported as skipped by OR and MUST NOT be deleted
- AND no object outside OR's approved enumeration is affected

### Requirement: REQ-RMA-006 Vernietigingsverklaring rendering

The system MUST render OpenRegister's destruction certificate rather than compose its own. OR's `DestructionService::generateCertificate()` already produces a `type: 'verklaring_van_vernietiging'` record — approvers, destroyed/skipped counts, skipped objects, `objectsBySchema`, `objectsBySelectielijst`, a `complianceStatement` citing Archiefwet/Archiefbesluit, and `immutable: true` — exposed via OR's certificates route (`GET /api/archival/certificates`). Decidesk MUST fetch that certificate and render it to PDF via Docudesk with a markdown fallback (the established minutes pattern), and MUST persist the rendered verklaring as a permanently-retained object that is never destruction-eligible. Decidesk MUST NOT duplicate, re-derive, or re-state the certificate's field list; the certificate shape is OR's.

#### Scenario: Render OR's certificate

- GIVEN an OR destruction execution has completed and produced a `verklaring_van_vernietiging` certificate
- WHEN decidesk renders the verklaring
- THEN it MUST fetch the certificate from OR's certificates route
- AND render it to PDF via Docudesk, falling back to markdown when Docudesk is absent
- AND persist the rendered verklaring as a permanently-retained object that never appears in a destruction proposal
- AND any objects OR reported as skipped MUST be visible in the rendering rather than hidden

### Requirement: REQ-RMA-007 Archive-completeness and compliance dashboard

The system MUST provide a records-management compliance dashboard built declaratively (ADR-031): `x-openregister-aggregations` counters and manifest dashboard widgets (ADR-037 manifest fragment), with no bespoke reporting backend. This is genuinely decidesk's to build: OpenRegister's compliance/NEN-2082 reporting is spec-only (`openspec/specs/archivering-vernietiging/spec.md` records NEN 2082 compliance reporting as not implemented). Counters MUST read OpenRegister's persisted `retention.archiefactiedatum` and `retention.archiefstatus`, and MUST NOT read a decidesk-local disposition field or the transient `_retention` envelope view. The dashboard MUST show at minimum: dossiers per lifecycle state, transfer-routed dossiers approaching or past the Archiefwet 10-year deadline, closed dossiers whose `retention` was not resolved, meetings/decision routes eligible for dossier formation but without a dossier (completeness gap), pending OR destruction lists, and transfer outcomes. Counters MUST be verifiable against the underlying objects.

#### Scenario: Overdue transfer surfaces on the dashboard

- GIVEN a transfer-routed dossier whose period ended more than 10 years ago and which is not `transferred`
- WHEN a staff member opens the compliance dashboard
- THEN the overdue-transfer counter MUST include the dossier and link to the filtered dossier list
- AND the counter MUST be derived from OR's `retention.archiefactiedatum` / `retention.archiefstatus`

#### Scenario: Completeness gap surfaces meetings without a dossier

- GIVEN a completed meeting with approved minutes but no ArchivalDossier
- WHEN the dashboard is viewed
- THEN the completeness widget MUST count the meeting as an archiving gap

### Requirement: REQ-RMA-008 Security classification labels on archival records

The system MUST support a `securityClassification` label on `ArchivalDossier` and on the archivable member schemas (Minutes, Decision, Meeting, DigitalDocument) as an additive optional property defaulting to `openbaar`. OpenRegister already owns a canonical confidentiality ordinal — `ZaaktypeAuthorizationService::VERTROUWELIJKHEIDAANDUIDING_LEVELS` (`openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`, `vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`), whose order drives OR's "cleared to level N sees level N or below" decisions. Decidesk's four values (`openbaar`, `intern`, `vertrouwelijk`, `geheim`) MUST be documented as a strict subset of that ordinal, MUST preserve its relative order, and MUST ship a mapping table to the OR levels; decidesk MUST NOT define a divergent ordering or a level absent from OR's ordinal. The dossier classification MUST be at least as restrictive as its most restrictive member (computed, surfaced as a warning when violated), and objects classified above `openbaar` MUST be structurally refused by the public-publication payload builder (consistent with the public-publication spec's deny-list approach). MDTO carriage of the classification (`beperkingGebruik`) is NOT decidesk's: OR's `MdtoXmlGenerator` emits no `beperkingGebruik` element today, and MDTO serialization is OR's — this MUST be recorded as a proposed OpenRegister follow-up.

#### Scenario: Classification is a documented subset of OR's ordinal

- GIVEN a dossier classified `vertrouwelijk`
- WHEN its classification is compared to OpenRegister's confidentiality ordinal
- THEN the value MUST map onto `VERTROUWELIJKHEIDAANDUIDING_LEVELS` at the same relative position
- AND decidesk MUST NOT introduce a level absent from OR's ordinal

#### Scenario: Dossier classification is at least as restrictive as its members

- GIVEN a dossier classified `openbaar` containing a decision classified `vertrouwelijk`
- WHEN the dossier is viewed
- THEN the computed-classification warning MUST surface naming the more restrictive member

#### Scenario: Classified record refused for public publication

- GIVEN minutes classified `vertrouwelijk`
- WHEN a publish request targets those minutes
- THEN the publication payload builder MUST refuse before eligibility evaluation, consistent with the deny-list behavior in the public-publication spec

## Non-Functional Requirements

- **Performance:** dashboard counters MUST come from declarative aggregations (no N+1 per-dossier queries); dossier assembly over a large meeting MUST NOT issue a query per member.
- **Accessibility:** all new pages/widgets MUST meet WCAG 2.1 AA using standard NC components and nldesign CSS variables.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); statutory terms (overbrenging, vernietigingsverklaring, Selectielijst) keep their Dutch names in both locales with an English gloss.
- **Auditability:** every dossier lifecycle transition MUST appear in the OR audit trail with actor and timestamp; destruction and transfer auditing is OR's, and decidesk MUST NOT duplicate it.

## Acceptance Criteria

- [ ] One new schema (`ArchivalDossier`) imports cleanly with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys), aggregations, notifications, and relations; no RetentionRule / TransferPackage / DestructionList schema is added
- [ ] A meeting dossier bundles minutes, decisions, votes, and attachments; closing freezes membership and checks completeness
- [ ] The dossier's OR `tmlo` / `retention` fields are populated so OR's `MdtoXmlGenerator` serves its MDTO record; decidesk contains no MDTO mapping table or serializer
- [ ] Selectielijst 2020 categories ship as OR `SelectionList` seed objects, and resolved retention is produced by `RetentionService::applyArchivalMetadata()` into the persisted `retention` field — decidesk computes no disposition date and writes no `_retention`
- [ ] Transfer creates an OR transfer list over member UUIDs; OR performs packaging and delivery; an unconfigured OR e-depot degrades honestly with no decidesk-side package
- [ ] Destruction is proposed/approved/executed entirely through OR's destruction lists, approval routes, and jobs; the dossier only reflects the outcome
- [ ] The verklaring is OR's `verklaring_van_vernietiging` certificate fetched from OR's certificates route and rendered (Docudesk PDF, markdown fallback), persisted with permanent retention
- [ ] Compliance dashboard counters read `retention.archiefactiedatum` / `retention.archiefstatus` from declarative aggregations and are verifiable against the underlying objects
- [ ] `securityClassification` is a documented subset of OR's `VERTROUWELIJKHEIDAANDUIDING_LEVELS` with a mapping table, and blocks public publication above `openbaar`

## Proposed OpenRegister follow-ups

Only gaps verified against OpenRegister at commit `ebedbdd5a`; each is an upstream proposal, never a decidesk workaround:

1. **MDTO `beperkingGebruik`** — `MdtoXmlGenerator` emits naam, toelichting, waardering, bewaartermijn, informatiecategorie, omvang, bestandsformaat, but no access-restriction element. A confidentiality label therefore cannot reach MDTO output today. OR owns MDTO serialization.
2. **Retention triggers beyond ZGW afleidingswijzen** — `ArchiefactiedatumCalculator` supports `afgehandeld`, `eigenschap`, `termijn`. A governance-specific trigger such as `end-of-council-term` is expressible only by materialising a date onto the object and using `eigenschap`; a first-class trigger would be an OR enhancement.
3. **NEN-2082 / compliance reporting** — recorded as not implemented in OR's own `archivering-vernietiging` spec. Decidesk builds its dashboard on OR data; a shared OR reporting surface would supersede it.
4. **Aggregate/dossier unit** — OR has no dossier concept (`document-zaakdossier` is a redirect stub owned by Procest). If a fleet-wide aggregate emerges, decidesk's `ArchivalDossier` should converge on it.

## Notes

- Talk-conversation archiving (intelligence-DB demand 497) is deferred; the dossier member model reserves a future `talk-export` kind.
- Decidesk is never the e-depot; permanent custody is the receiving archive's responsibility (Archiefwet art. 12).
- OR's destruction execution calls `DeleteObject::delete(..., permanent: true)` after a legal-hold pre-flight — it is a permanent delete gated by OR's checks, not a "retention-aware soft delete". Decidesk MUST NOT describe it as one.
- Related ADRs: ADR-005 (decision supertype, i18n), ADR-022 (consume OR abstractions), ADR-031 (declarative-first), ADR-037 (manifest fragments).
- Related specs: resolution-minutes (MDTO on minutes, proof packages), public-publication (deny-list, derived payloads), decision-management (lifecycle `archived` on Decision is a workflow state, distinct from dossier `transferred`/`destroyed`).
