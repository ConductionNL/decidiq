# verordeningenregister Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- verordeningenregister

## Purpose

Gives decidiq a register of local regulations (verordeningen, beleidsregels, nadere regels, reglementen, externe statuten): `Regeling` objects carrying legal identity and a status lifecycle, immutable `RegelingVersie` objects where every version traces to its amending decision (wijzigingsbesluit), deterministic in-force resolution per date, a public register page of regelingen in force with consolidated texts, DROP/STOP-TPOD export packages delivered through OpenConnector (external-integration exception under ADR-031, honest degradation when absent), and notifications when an inwerkingtreding approaches without a published consolidated text. This is the register foundation the notubiz-ibabs change defers its STOP/TPOD bulk-export to, and it provides the immutable verordening-versie reference that commissievergaderingen REQ-CVG-013 consumes. Decidiq is not a bekendmakingsplatform: it produces the publication package; the connector delivers it.

**Standards**: CVDR (Centrale Voorziening Decentrale Regelgeving), DROP (Decentrale Regelgeving en Officiële Publicaties), STOP/TPOD (Standaard Officiële Publicaties / Toepassingsprofielen), Schema.org (`Legislation`, `LegislationObject`), Akoma Ntoso (`act`, FRBR work/expression versioning), OpenRaadsinformatie (`Besluit` linkage), Gemeentewet art. 139-144, Bekendmakingswet
**Feature tier**: V1
**Legal reference**: Gemeentewet art. 139-144 (bekendmaking en inwerkingtreding), Bekendmakingswet / Wet elektronische publicaties

## ADDED Requirements

### Requirement: REQ-VOR-001 Regeling schema

The system MUST provide a `Regeling` OpenRegister schema (Schema.org `Legislation`, Akoma Ntoso `act` work level) in the decidesk register carrying: `type` (enum: `verordening`, `beleidsregel`, `nadere-regel`, `reglement`, `statuut-extern`), `citeertitel` (required), `officieleTitel`, `wettelijkeGrondslag` (array of legal-basis citations, e.g. "Gemeentewet art. 149"), `vaststellendOrgaan` (UUID reference to a GovernanceBody), and `cvdrIdentifier` (optional string, the CVDR identifier assigned by the national CVDR after first publication). The schema MUST declare a lifecycle via `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys) with states `in-voorbereiding → vastgesteld → in-werking → vervallen` (`vervallen` terminal). Transitions outside the declared map MUST be rejected by OpenRegister; decidiq SHALL NOT implement a parallel state machine.

#### Scenario: Create a verordening

- GIVEN a staff user and the governance body "Gemeenteraad Amsterdam"
- WHEN they create a Regeling with type `verordening`, citeertitel "Afvalstoffenverordening Amsterdam", wettelijke grondslag "Gemeentewet art. 149" and vaststellend orgaan "Gemeenteraad Amsterdam"
- THEN an OpenRegister object MUST be created in the decidesk register with the `regeling` schema in lifecycle state `in-voorbereiding`
- AND the object MUST reference the GovernanceBody by UUID

#### Scenario: Citeertitel is required

- GIVEN a staff user creating a Regeling
- WHEN they submit without a citeertitel
- THEN OpenRegister schema validation MUST reject the request and no object is created

#### Scenario: Undeclared lifecycle transition rejected

- GIVEN a Regeling in state `in-voorbereiding`
- WHEN a transition directly to `vervallen` is attempted
- THEN the transition MUST be rejected because it is not in the declared transition map

### Requirement: REQ-VOR-002 RegelingVersie traced to its amending decision

The system MUST provide a `RegelingVersie` OpenRegister schema (Schema.org `LegislationObject`, Akoma Ntoso expression level) carrying: `regeling` (UUID reference to the parent Regeling, required), `versienummer` (positive integer, unique per regeling), `vastgesteldDoor` (UUID reference to the Decision that enacted this version — the wijzigingsbesluit, required), `inwerkingtreding` (date, required before activation), `vervaldatum` (optional date), `toelichting` (summary of what this version changes), and a consolidated-text document attached through OpenRegister's file abstraction. Every version MUST trace to its amending decision: creating a RegelingVersie without a resolvable `vastgesteldDoor` Decision reference MUST be rejected. The version lifecycle MUST be declared via `x-openregister-lifecycle` with states `concept → vastgesteld → in-werking → vervangen | vervallen` (`vervangen` and `vervallen` terminal).

#### Scenario: Create a version from an enacted amending decision

- GIVEN a Regeling "Afvalstoffenverordening Amsterdam" and an enacted Decision "Wijziging afvalstoffenverordening 2026"
- WHEN staff create RegelingVersie 3 with vastgesteldDoor pointing at that Decision, inwerkingtreding 2026-09-01, and an attached consolidated-text document
- THEN a `regeling-versie` object MUST be created in state `concept` referencing the Regeling and the Decision by UUID
- AND the version's consolidated text MUST be stored via OpenRegister's file abstraction

#### Scenario: Version without amending decision refused

- GIVEN a Regeling with two existing versions
- WHEN staff attempt to create a RegelingVersie without a `vastgesteldDoor` Decision reference
- THEN the request MUST be rejected with a validation error naming the missing amending-decision link
- AND no version object is created

#### Scenario: Version numbers are unique per regeling

- GIVEN a Regeling whose latest version has versienummer 3
- WHEN staff attempt to create another version with versienummer 3 for the same Regeling
- THEN the request MUST be rejected with a uniqueness error

### Requirement: REQ-VOR-003 Version immutability once in force

The system MUST seal a RegelingVersie when it transitions to `in-werking`: from that moment its `versienummer`, `vastgesteldDoor`, `inwerkingtreding`, and consolidated-text document reference MUST be immutable, enforced server-side independent of UI state. Corrections MUST be made as a new RegelingVersie traced to its own (rectification) decision — never by editing a sealed version. Sealed versions form the stable, immutable reference target that other capabilities (e.g. commissievergaderingen REQ-CVG-013's commissie-verordening coupling) MUST be able to reference by UUID with the guarantee that the referenced legal content never changes.

#### Scenario: Editing a sealed version refused

- GIVEN a RegelingVersie in state `in-werking`
- WHEN any user (including an admin) attempts to change its inwerkingtreding date or replace its consolidated-text document
- THEN the write MUST be rejected with an error naming the sealed state
- AND the stored object MUST be unchanged

#### Scenario: Correction happens as a new version

- GIVEN a sealed RegelingVersie 3 containing a text error
- WHEN staff process the rectification
- THEN they MUST create RegelingVersie 4 with vastgesteldDoor pointing at the rectification decision
- AND version 3 MUST remain unchanged and transition to `vervangen` when version 4 takes force

#### Scenario: External capability references a sealed version

- GIVEN a sealed RegelingVersie referenced by UUID from another object (e.g. a Commissie record)
- WHEN the referencing object is read at any later time
- THEN resolving the reference MUST yield the identical versienummer, inwerkingtreding, and consolidated-text document as at sealing time

### Requirement: REQ-VOR-004 In-force resolution per date

The system MUST resolve, for any Regeling and any date X, which RegelingVersie is in force on that date: the version with the latest `inwerkingtreding` less than or equal to X whose state is `in-werking` or `vervangen` at a later date, and whose `vervaldatum` (if set) is after X; if the Regeling itself is `vervallen` on X or no version qualifies, the resolution MUST return "no version in force". The resolution MUST be deterministic and exposed both in the UI (version timeline with "geldend op" date picker) and as a service method other capabilities can call. Activation validation MUST refuse sealing a version whose `inwerkingtreding` is not after the inwerkingtreding of the currently latest sealed version, so in-force resolution can never be ambiguous.

#### Scenario: Resolve the version in force on a historical date

- GIVEN a Regeling with sealed versions 1 (inwerkingtreding 2024-01-01), 2 (2025-06-01), and 3 (2026-09-01)
- WHEN the in-force resolution is asked for date 2025-12-15
- THEN it MUST return version 2

#### Scenario: No version in force before the first inwerkingtreding

- GIVEN the same Regeling
- WHEN the resolution is asked for date 2023-05-01
- THEN it MUST return "no version in force"

#### Scenario: Out-of-order activation refused

- GIVEN a Regeling whose latest sealed version has inwerkingtreding 2026-09-01
- WHEN staff attempt to seal a new version with inwerkingtreding 2026-06-01
- THEN the activation MUST be refused with an error naming the ordering conflict

### Requirement: REQ-VOR-005 Public register page of regelingen in force

The system MUST provide a public register page listing all regelingen currently in force for the organisation, showing per regeling the citeertitel, type, vaststellend orgaan, inwerkingtreding of the current version, CVDR identifier when present, and access to the current consolidated text. Public exposure MUST follow the public-publication conventions: eligibility enforced server-side (only regelingen in state `in-werking` with a sealed current version appear), exposure via OpenRegister's published-predicate RBAC surface, and no draft (`concept`) versions or internal annotations ever rendered. Regelingen in `vervallen` state MUST NOT appear in the in-force listing but their historical versions MUST remain resolvable via REQ-VOR-004.

#### Scenario: Public visitor sees regelingen in force

- GIVEN an anonymous visitor and three regelingen of which two are `in-werking` and one is `in-voorbereiding`
- WHEN they open the public verordeningenregister page
- THEN exactly the two in-force regelingen MUST be listed with citeertitel, type, and current inwerkingtreding
- AND the consolidated text of each current version MUST be downloadable

#### Scenario: Draft versions never leak

- GIVEN an in-force Regeling that also has a `concept` RegelingVersie awaiting a future decision
- WHEN the public page renders that Regeling
- THEN only the sealed current version and its consolidated text MUST be exposed
- AND the concept version MUST NOT appear in the payload

### Requirement: REQ-VOR-006 DROP/STOP-TPOD export package via OpenConnector

The system MUST generate a `RegelingExportPackage` OpenRegister object for one or more sealed regeling-versies, containing STOP/TPOD-structured XML plus the consolidated-text documents, with lifecycle `building → ready → delivering → delivered | failed` (package generation is an accepted imperative document-generation exception under ADR-031). Structural validation failures MUST set the package to `failed` with errors stored and block delivery. Delivery MUST go through an OpenConnector Source resolved lazily by slug (external-integration exception under ADR-031, same pattern as records-management-archiving transfer delivery): decidiq produces the package and calls the connector; it MUST NOT communicate with officielebekendmakingen.nl or DROP directly. When OpenConnector or the Source is absent, the package MUST remain `ready`, MUST be downloadable for manual DROP submission, and the UI MUST state honestly that automatic delivery is unavailable — the system MUST NOT fail or pretend delivery happened. On acknowledged delivery the package MUST store the remote reference, and staff MUST be able to record the CVDR identifier returned by the national register onto the Regeling.

#### Scenario: Build and deliver an export package

- GIVEN a sealed RegelingVersie and a configured OpenConnector Source for DROP
- WHEN staff trigger "Publiceer via DROP"
- THEN a `RegelingExportPackage` MUST be built, validated, and set to `ready`
- AND delivery through the Source MUST transition it to `delivered` with the remote acknowledgement reference stored

#### Scenario: OpenConnector absent degrades honestly

- GIVEN an instance without OpenConnector
- WHEN staff view a `ready` export package
- THEN a download of the package (STOP/TPOD XML + consolidated text) MUST be offered
- AND the UI MUST state that automatic delivery is unavailable, and the package stays `ready`

#### Scenario: Validation failure blocks delivery

- GIVEN a regeling-versie whose consolidated-text document is missing
- WHEN the package build runs
- THEN the package MUST transition to `failed` with the validation error stored
- AND delivery MUST be refused while the package is not `ready`

### Requirement: REQ-VOR-007 Inwerkingtreding notifications

The system MUST notify responsible staff when a RegelingVersie's `inwerkingtreding` is approaching (14 days ahead, and again at 3 days) while the version has no published consolidated text — i.e. the version is not sealed, lacks a consolidated-text document, or has no delivered/manually-confirmed export package. Notifications MUST be declared via the canonical `x-openregister-notifications` dialect (ADR-031); decidiq SHALL NOT dispatch these imperatively. No notification MUST be sent when the consolidated text is published in time.

#### Scenario: Approaching inwerkingtreding without published text

- GIVEN a RegelingVersie with inwerkingtreding 12 days from now and no consolidated-text document attached
- WHEN the notification evaluation runs
- THEN responsible staff MUST receive a notification naming the regeling, the version, and the missing consolidated text

#### Scenario: Published in time means silence

- GIVEN a RegelingVersie with inwerkingtreding 10 days from now, sealed, with consolidated text and a delivered export package
- WHEN the notification evaluation runs
- THEN no notification MUST be sent for this version

### Requirement: REQ-VOR-008 Regelingen list and detail pages

The system MUST provide an internal regelingen list page (filterable by type, status, and vaststellend orgaan; searchable on citeertitel) and a regeling detail page showing the regeling's metadata, its full version timeline (each version with versienummer, inwerkingtreding, state, link to its amending decision, and consolidated text), a "geldend op" date control driving the REQ-VOR-004 resolution, and the export/publication state per version. The amending-decision link on each version MUST navigate to the existing Decision detail page. Pages MUST follow the app's manifest/store conventions and be reachable from the app navigation.

#### Scenario: Browse and filter the register

- GIVEN twelve regelingen of mixed types and states
- WHEN a staff user filters the list on type `verordening` and status `in-werking`
- THEN only matching regelingen MUST be listed with citeertitel, type, status, and current-version inwerkingtreding

#### Scenario: Version timeline with decision traceability

- GIVEN a Regeling with three versions
- WHEN a staff user opens its detail page
- THEN all three versions MUST be shown in inwerkingtreding order with their states
- AND each version's wijzigingsbesluit link MUST navigate to that Decision's detail page

## Non-Functional Requirements

- **Performance:** the public register page and in-force resolution MUST answer from indexed OR queries; resolving the current version for a list of 200 regelingen MUST NOT trigger per-regeling N+1 object fetches.
- **Accessibility:** list, detail, and public pages MUST meet WCAG 2.1 AA; the version timeline MUST be navigable by keyboard and announced by screen readers.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); Dutch legal terms (citeertitel, inwerkingtreding, wijzigingsbesluit) remain untranslated domain vocabulary in both locales.

## Acceptance Criteria

- [ ] Register fragment `53-verordeningenregister.json` imports cleanly on a fresh instance with all three schemas, lifecycles, relations, and notifications
- [ ] A version cannot be created without an amending decision and cannot be edited once in force
- [ ] In-force resolution returns the correct version for boundary dates (day of inwerkingtreding, day of vervaldatum)
- [ ] Public page shows only in-force regelingen with sealed consolidated texts
- [ ] Export package delivers via OpenConnector when configured and degrades to download when not
- [ ] Seed data provides at least two regelingen with multi-version chains traced to seed decisions

## Notes

- Fragment number 53 is assigned to this change (ADR-037); 40–52 and 54–65 belong to sibling changes.
- commissievergaderingen REQ-CVG-013 consumes the sealed-version reference of REQ-VOR-003; the consuming wiring stays in that change.
- notubiz-ibabs-griffie-koppeling explicitly parks STOP/TPOD bulk-export as a separate publicatie-spec; REQ-VOR-006 is the register-side foundation for it.
- CVDR identifiers are assigned by the national register; decidiq stores them (REQ-VOR-001/006), it never mints them.
